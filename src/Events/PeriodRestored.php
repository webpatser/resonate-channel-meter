<?php

namespace Webpatser\ResonateChannelMeter\Events;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Events\Dispatchable;
use Webpatser\ResonateChannelMeter\EventHandler;

/**
 * An open period got back to `min_members` within its grace window.
 *
 * Dispatched by {@see EventHandler} only when a set `below_since` marker is
 * cleared, after the outermost database transaction commits. A clear on a
 * period without a marker dispatches nothing. Fully-occupied mode only.
 */
class PeriodRestored
{
    use Dispatchable;

    /**
     * Create a new event instance.
     *
     * @param  CarbonInterface  $at  the moment the recovery was applied (the webhook `time_ms`, or the sweep moment)
     * @param  CarbonInterface  $belowSince  the marker that was cleared, equal to the matching {@see PeriodBelowThreshold::$belowSince}
     */
    public function __construct(
        public readonly string $appId,
        public readonly string $channel,
        public readonly int $periodId,
        public readonly ?string $modelType,
        public readonly ?string $modelId,
        public readonly CarbonInterface $at,
        public readonly CarbonInterface $belowSince,
    ) {
        //
    }
}
