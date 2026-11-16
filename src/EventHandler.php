<?php

namespace Webpatser\ResonateChannelMeter;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webpatser\ResonateChannelMeter\Contracts\MembershipCounter;
use Webpatser\ResonateChannelMeter\Resolvers\ChannelResolver;
use Webpatser\ResonateChannelMeter\Support\MeterAction;
use Webpatser\ResonateChannelMeter\Support\MeterDecision;

/**
 * Applies one Pusher webhook event to the channel-meter store.
 *
 * Two modes, chosen by `resonate-channel-meter.min_members`:
 *
 * - Room occupancy (min_members <= 1, the default): only `channel_occupied`
 *   and `channel_vacated` are recorded, one period per occupied stretch.
 * - Fully occupied (min_members >= 2): every membership event re-evaluates the
 *   live member count (from the bound {@see MembershipCounter}) and opens a
 *   period when the channel reaches the threshold, closing it (after an
 *   optional grace window) when it drops below.
 *
 * Both modes are idempotent, so a webhook redelivered after a timeout does not
 * duplicate a period or leave an orphan close. Deliveries that race each other
 * are held apart by the unique index on (app_id, channel, open_slot) plus a
 * locked read inside a transaction, and deliveries that arrive out of order are
 * dropped against the channel's high-water mark in `channel_meter_marks`, whose
 * row is locked for the whole check, apply and mark of each event.
 */
class EventHandler
{
    /** Events that can change a channel's member count. */
    private const MEMBERSHIP_EVENTS = ['member_added', 'member_removed', 'channel_occupied', 'channel_vacated'];

    /** Events that open and close a period in room-occupancy mode. */
    private const OCCUPANCY_EVENTS = ['channel_occupied', 'channel_vacated'];

    /**
     * Create a new event handler.
     */
    public function __construct(
        protected ChannelResolver $resolver,
        protected MembershipCounter $counter,
    ) {
        //
    }

    /**
     * Handle one event from a webhook delivery.
     *
     * @param  array{name?:string,channel?:string}  $event
     */
    public function handle(string $appId, array $event, int $timeMs): void
    {
        $channel = $event['channel'] ?? null;

        if (! is_string($channel) || ! $this->meters($channel)) {
            return;
        }

        $at = Carbon::createFromTimestampMs($timeMs);
        $name = $event['name'] ?? null;

        if ($this->minMembers() <= 1) {
            if (! in_array($name, self::OCCUPANCY_EVENTS, true)) {
                return;
            }

            $this->inOrder($appId, $channel, $timeMs, function () use ($appId, $channel, $at, $name): void {
                if ($name === 'channel_occupied') {
                    $this->open($appId, $channel, $at);
                } else {
                    $this->close($appId, $channel, $at);
                }
            });

            return;
        }

        if (! in_array($name, self::MEMBERSHIP_EVENTS, true)) {
            return;
        }

        // The live count can be a network call, so it is read before the
        // channel's mark row is locked rather than while holding it.
        $memberCount = $this->counter->count($channel);

        $this->inOrder($appId, $channel, $timeMs, function () use ($appId, $channel, $at, $memberCount): void {
            $this->evaluate($appId, $channel, $at, $memberCount);
        });
    }

    /**
     * Apply an event only if it is not older than the newest one applied.
     *
     * The channel's mark row is locked first and held for the whole
     * transaction, so the stale check, the apply and the mark update cannot
     * interleave with another delivery for the same channel.
     *
     * @param  callable(): void  $apply
     */
    protected function inOrder(string $appId, string $channel, int $timeMs, callable $apply): void
    {
        DB::transaction(function () use ($appId, $channel, $timeMs, $apply): void {
            $this->lockMark($appId, $channel);

            if ($this->isStale($appId, $channel, $timeMs)) {
                return;
            }

            $apply();

            $this->markApplied($appId, $channel, $timeMs);
        });
    }

    /**
     * Reconcile every open period against the live member count.
     *
     * The handler closes periods as membership events arrive, but if a channel
     * simply goes quiet after dropping below the threshold (no further webhook)
     * the open period would dangle. A periodic sweep is the backstop. It
     * re-checks the live count first, so a member who returned without a
     * delivered event keeps the period open.
     *
     * It also covers a `member_removed` that never arrived (delivery gave up,
     * the sender crashed with the event queued, a wrong webhook URL): an open
     * period with no below-threshold marker whose live count is under the
     * threshold is marked below as of the sweep, so the normal grace close
     * follows instead of the period running on until the next event. Every
     * period goes through the same {@see MeterDecision} the webhook path uses,
     * so the two paths are idempotent with each other.
     *
     * Each period is handled in its own transaction and re-read under
     * `lockForUpdate()`, so a `member_added` that lands while the sweep is
     * running cannot have its reopen overwritten by a stale decision.
     *
     * @return int the number of periods closed
     */
    public function sweep(?Carbon $now = null): int
    {
        if ($this->minMembers() <= 1) {
            return 0;
        }

        $now ??= Carbon::now();
        $grace = $this->graceSeconds();
        $closed = 0;

        foreach ($this->openPeriodIds() as $id) {
            if ($this->sweepPeriod($id, $now, $grace)) {
                $closed++;
            }
        }

        return $closed;
    }

    /**
     * The ids of every period that looked open when the sweep started.
     *
     * Only ids are collected: whether a period is still open, and what its
     * below-threshold marker says, is decided per period under its own lock.
     *
     * @return array<int, int>
     */
    protected function openPeriodIds(): array
    {
        return ChannelMeterPeriod::query()
            ->whereNull('ended_at')
            ->orderBy('id')
            ->get(['id'])
            ->map(fn (ChannelMeterPeriod $period): int => (int) $period->getKey())
            ->all();
    }

    /**
     * Re-evaluate one swept period against its channel's live member count.
     *
     * Back at the threshold clears a below-threshold marker; under it with no
     * marker sets one as of `$now` (a lost `member_removed`); under it past the
     * grace deadline closes the period at that deadline.
     *
     * @return bool whether the period was closed
     */
    protected function sweepPeriod(int $id, Carbon $now, int $grace): bool
    {
        $closed = false;

        DB::transaction(function () use ($id, $now, $grace, &$closed): void {
            $period = ChannelMeterPeriod::query()->whereKey($id)->lockForUpdate()->first();

            if ($period === null || ! $period->isOpen()) {
                return;
            }

            $decision = MeterDecision::for(
                isOpen: true,
                belowSince: $this->belowSince($period),
                memberCount: $this->counter->count($period->channel),
                minMembers: $this->minMembers(),
                graceSeconds: $grace,
                now: $now,
            );

            match ($decision->action) {
                MeterAction::MarkBelowSince => $this->setBelowSince($period, $now),
                MeterAction::ClearBelowSince => $this->setBelowSince($period, null),
                MeterAction::ClosePeriod => $this->closePeriod($period, $decision->endedAt ?? $now),
                MeterAction::OpenPeriod, MeterAction::NoOp => null,
            };

            $closed = $decision->action === MeterAction::ClosePeriod;
        });

        return $closed;
    }

    /**
     * Re-evaluate one channel's metering against its live member count.
     *
     * The member count is read by the caller (it can be a network call), then
     * the period is locked and re-read so the read-modify-write of the
     * below-threshold marker cannot be lost to a concurrent delivery.
     */
    protected function evaluate(string $appId, string $channel, Carbon $at, int $memberCount): void
    {
        DB::transaction(function () use ($appId, $channel, $at, $memberCount): void {
            $open = $this->openPeriod($appId, $channel, lock: true);

            $decision = MeterDecision::for(
                isOpen: $open !== null,
                belowSince: $this->belowSince($open),
                memberCount: $memberCount,
                minMembers: $this->minMembers(),
                graceSeconds: $this->graceSeconds(),
                now: $at,
            );

            match ($decision->action) {
                MeterAction::OpenPeriod => $this->open($appId, $channel, $at),
                MeterAction::MarkBelowSince => $this->setBelowSince($open, $at),
                MeterAction::ClearBelowSince => $this->setBelowSince($open, null),
                MeterAction::ClosePeriod => $this->closePeriod($open, $decision->endedAt ?? $at),
                MeterAction::NoOp => null,
            };
        });
    }

    /**
     * Open a period for the channel, unless one is already open.
     *
     * Two guards, because one is not enough: the locked read inside the
     * transaction serialises deliveries that reach the same row, and the
     * unique index on (app_id, channel, open_slot) catches the rest (two
     * workers inserting a first period for the same channel at once, where
     * there is no row to lock yet). `insertOrIgnore` lets the delivery that
     * loses that race drop out silently instead of blowing up the webhook.
     */
    protected function open(string $appId, string $channel, Carbon $at): void
    {
        DB::transaction(function () use ($appId, $channel, $at): void {
            if ($this->openPeriod($appId, $channel, lock: true) !== null) {
                return;
            }

            $resolved = $this->resolver->resolve($channel);
            $now = Carbon::now();

            ChannelMeterPeriod::query()->insertOrIgnore([
                'app_id' => $appId,
                'channel' => $channel,
                'model_type' => $resolved['type'] ?? null,
                'model_id' => $resolved['id'] ?? null,
                'started_at' => $at,
                'open_slot' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    /**
     * Close the latest open period for the channel, if there is one.
     */
    protected function close(string $appId, string $channel, Carbon $at): void
    {
        DB::transaction(function () use ($appId, $channel, $at): void {
            $this->closePeriod($this->openPeriod($appId, $channel, lock: true), $at);
        });
    }

    /**
     * The latest open period for a channel, if one exists.
     */
    protected function openPeriod(string $appId, string $channel, bool $lock = false): ?ChannelMeterPeriod
    {
        return ChannelMeterPeriod::query()
            ->where('app_id', $appId)
            ->where('channel', $channel)
            ->whereNull('ended_at')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->latest('started_at')
            ->first();
    }

    /**
     * Lock a channel's high-water mark row, creating it on first sight.
     *
     * Two first events for the same channel can both find no row; the
     * unique index on (app_id, channel) lets exactly one insert land and
     * `insertOrIgnore` drops the other, after which both lock the one row.
     */
    protected function lockMark(string $appId, string $channel): ChannelMeterMark
    {
        $mark = $this->markQuery($appId, $channel)->lockForUpdate()->first();

        if ($mark !== null) {
            return $mark;
        }

        ChannelMeterMark::query()->insertOrIgnore([
            'app_id' => $appId,
            'channel' => $channel,
            'last_event_ms' => null,
        ]);

        return $this->markQuery($appId, $channel)->lockForUpdate()->firstOrFail();
    }

    /**
     * A query for one channel's high-water mark row.
     *
     * @return Builder<ChannelMeterMark>
     */
    protected function markQuery(string $appId, string $channel): Builder
    {
        return ChannelMeterMark::query()
            ->where('app_id', $appId)
            ->where('channel', $channel);
    }

    /**
     * Determine whether an event is older than the newest one already applied.
     *
     * Webhook deliveries are retried and can overtake each other, so arrival
     * order is not event order. Without this guard a redelivered
     * `channel_occupied` that lands after its `channel_vacated` opens a phantom
     * period nothing will ever close, and a delayed `channel_vacated` closes a
     * period before it started. Events at the high-water mark itself still
     * apply: the open/close paths are idempotent, and `time_ms` is shared by
     * every event in one delivery.
     *
     * The mark lives in `channel_meter_marks`, not on a period, so it exists
     * before the channel's first period does. Called with the mark row
     * already locked by {@see inOrder()}.
     */
    protected function isStale(string $appId, string $channel, int $timeMs): bool
    {
        $applied = $this->markQuery($appId, $channel)->value('last_event_ms');

        return $applied !== null && $timeMs < (int) $applied;
    }

    /**
     * Advance a channel's high-water mark to the event just applied.
     */
    protected function markApplied(string $appId, string $channel, int $timeMs): void
    {
        $this->markQuery($appId, $channel)
            ->where(fn ($query) => $query->whereNull('last_event_ms')->orWhere('last_event_ms', '<', $timeMs))
            ->update(['last_event_ms' => $timeMs]);
    }

    /**
     * Read the "dropped below the threshold at" marker from a period, if set.
     */
    protected function belowSince(?ChannelMeterPeriod $period): ?Carbon
    {
        $value = $period?->metadata['below_since'] ?? null;

        return is_string($value) ? Carbon::parse($value) : null;
    }

    /**
     * Set or clear a period's below-threshold marker.
     */
    protected function setBelowSince(?ChannelMeterPeriod $period, ?Carbon $at): void
    {
        if ($period === null) {
            return;
        }

        $metadata = $period->metadata ?? [];

        if ($at === null) {
            unset($metadata['below_since']);
        } else {
            $metadata['below_since'] = $at->toIso8601String();
        }

        $period->update(['metadata' => $metadata === [] ? null : $metadata]);
    }

    /**
     * Close a period at a given moment, clearing its below-threshold marker.
     *
     * `ended_at` is clamped to `started_at`: a delayed `channel_vacated` (or a
     * grace deadline computed from a marker older than the period) would
     * otherwise write a negative duration, which the billing roll-up in
     * `HasChannelMeter` drops silently, losing the whole session. A clamped
     * close records a zero-length period instead, which is visible and bills
     * nothing. Closing also releases the open slot so the channel can be
     * occupied again.
     */
    protected function closePeriod(?ChannelMeterPeriod $period, Carbon $endedAt): void
    {
        if ($period === null) {
            return;
        }

        $metadata = $period->metadata ?? [];
        unset($metadata['below_since']);

        $period->update([
            'ended_at' => $endedAt->lessThan($period->started_at) ? $period->started_at->copy() : $endedAt,
            'open_slot' => null,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    /**
     * The configured member threshold for an open period.
     */
    protected function minMembers(): int
    {
        return (int) config('resonate-channel-meter.min_members', 1);
    }

    /**
     * Determine whether a channel is one this package meters.
     *
     * The Pusher protocol reserves "#" for channels the server owns rather than
     * the application. `webpatser/resonate-users` puts a signed-in connection on
     * "#server-to-user-{id}" so a message can be addressed to a person, and
     * that channel is a user's session, not a room. A channel matching no
     * pattern is still recorded, so without this every sign-in would open a
     * billable period and every sign-off would close one.
     */
    protected function meters(string $channel): bool
    {
        /** @var list<string> $prefixes */
        $prefixes = array_values(array_filter(
            array_map(strval(...), (array) config('resonate-channel-meter.ignore_channel_prefixes', ['#'])),
            static fn (string $prefix): bool => $prefix !== '',
        ));

        foreach ($prefixes as $prefix) {
            if (str_starts_with($channel, $prefix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The configured grace window, in seconds, below the threshold.
     */
    protected function graceSeconds(): int
    {
        return (int) config('resonate-channel-meter.grace_seconds', 0);
    }
}
