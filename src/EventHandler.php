<?php

namespace Webpatser\ResonateChannelMeter;

use Illuminate\Support\Carbon;
use Webpatser\ResonateChannelMeter\Resolvers\ChannelResolver;

/**
 * Applies one Pusher webhook event to the channel-meter store.
 *
 * Only `channel_occupied` and `channel_vacated` are recorded; other event
 * types (member events, client events) are ignored. Both operations are
 * idempotent, so a webhook redelivered after a timeout does not duplicate a
 * period or leave an orphan close.
 */
class EventHandler
{
    /**
     * Create a new event handler.
     */
    public function __construct(protected ChannelResolver $resolver)
    {
        //
    }

    /**
     * Handle one event from a webhook delivery.
     *
     * @param  array{name:string,channel:string}  $event
     */
    public function handle(string $appId, array $event, int $timeMs): void
    {
        $channel = $event['channel'] ?? null;

        if (! is_string($channel)) {
            return;
        }

        $at = Carbon::createFromTimestampMs($timeMs);

        match ($event['name'] ?? null) {
            'channel_occupied' => $this->open($appId, $channel, $at),
            'channel_vacated' => $this->close($appId, $channel, $at),
            default => null,
        };
    }

    /**
     * Open a period for the channel, unless one is already open.
     */
    protected function open(string $appId, string $channel, Carbon $at): void
    {
        $alreadyOpen = ChannelMeterPeriod::query()
            ->where('app_id', $appId)
            ->where('channel', $channel)
            ->whereNull('ended_at')
            ->exists();

        if ($alreadyOpen) {
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
        ChannelMeterPeriod::query()
            ->where('app_id', $appId)
            ->where('channel', $channel)
            ->whereNull('ended_at')
            ->latest('started_at')
            ->first()
            ?->update(['ended_at' => $at]);
    }
}
