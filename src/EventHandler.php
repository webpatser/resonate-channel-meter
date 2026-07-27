<?php

namespace Webpatser\ResonateChannelMeter;

use Illuminate\Support\Carbon;
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
 *   period when the channel reaches the threshold, closing it — after an
 *   optional grace window — when it drops below.
 *
 * Both modes are idempotent, so a webhook redelivered after a timeout does not
 * duplicate a period or leave an orphan close.
 */
class EventHandler
{
    /** Events that can change a channel's member count. */
    private const MEMBERSHIP_EVENTS = ['member_added', 'member_removed', 'channel_occupied', 'channel_vacated'];

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

        if (! is_string($channel)) {
            return;
        }

        $at = Carbon::createFromTimestampMs($timeMs);
        $name = $event['name'] ?? null;

        if ($this->minMembers() <= 1) {
            match ($name) {
                'channel_occupied' => $this->open($appId, $channel, $at),
                'channel_vacated' => $this->close($appId, $channel, $at),
                default => null,
            };

            return;
        }

        if (in_array($name, self::MEMBERSHIP_EVENTS, true)) {
            $this->evaluate($appId, $channel, $at);
        }
    }

    /**
     * Close any open periods whose grace window has elapsed.
     *
     * The handler closes periods as membership events arrive, but if a channel
     * simply goes quiet after dropping below the threshold (no further webhook)
     * the open period would dangle. A periodic sweep is the backstop. It
     * re-checks the live count first, so a member who returned without a
     * delivered event keeps the period open.
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

        foreach (ChannelMeterPeriod::query()->whereNull('ended_at')->get() as $period) {
            $belowSince = $this->belowSince($period);

            if ($belowSince === null) {
                continue;
            }

            if ($this->counter->count($period->channel) >= $this->minMembers()) {
                $this->setBelowSince($period, null);

                continue;
            }

            $deadline = $belowSince->copy()->addSeconds($grace);

            if ($now >= $deadline) {
                $this->closePeriod($period, $deadline);
                $closed++;
            }
        }

        return $closed;
    }

    /**
     * Re-evaluate one channel's metering against its live member count.
     */
    protected function evaluate(string $appId, string $channel, Carbon $at): void
    {
        $open = $this->openPeriod($appId, $channel);

        $decision = MeterDecision::for(
            isOpen: $open !== null,
            belowSince: $this->belowSince($open),
            memberCount: $this->counter->count($channel),
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
    }

    /**
     * Open a period for the channel, unless one is already open.
     */
    protected function open(string $appId, string $channel, Carbon $at): void
    {
        if ($this->openPeriod($appId, $channel) !== null) {
            return;
        }

        $resolved = $this->resolver->resolve($channel);

        ChannelMeterPeriod::query()->create([
            'app_id' => $appId,
            'channel' => $channel,
            'model_type' => $resolved['type'] ?? null,
            'model_id' => $resolved['id'] ?? null,
            'started_at' => $at,
        ]);
    }

    /**
     * Close the latest open period for the channel, if there is one.
     */
    protected function close(string $appId, string $channel, Carbon $at): void
    {
        $this->openPeriod($appId, $channel)?->update(['ended_at' => $at]);
    }

    /**
     * The latest open period for a channel, if one exists.
     */
    protected function openPeriod(string $appId, string $channel): ?ChannelMeterPeriod
    {
        return ChannelMeterPeriod::query()
            ->where('app_id', $appId)
            ->where('channel', $channel)
            ->whereNull('ended_at')
            ->latest('started_at')
            ->first();
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
     */
    protected function closePeriod(?ChannelMeterPeriod $period, Carbon $endedAt): void
    {
        if ($period === null) {
            return;
        }

        $metadata = $period->metadata ?? [];
        unset($metadata['below_since']);

        $period->update([
            'ended_at' => $endedAt,
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
     * The configured grace window, in seconds, below the threshold.
     */
    protected function graceSeconds(): int
    {
        return (int) config('resonate-channel-meter.grace_seconds', 0);
    }
}
