<?php

namespace Webpatser\ResonateChannelMeter\Events;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Events\Dispatchable;
use Webpatser\ResonateChannelMeter\EventHandler;

/**
 * An open period dropped below `min_members` and its grace window started.
 *
 * Dispatched by {@see EventHandler} only when the period's `below_since`
 * marker goes from unset to set, after the outermost database transaction
 * commits. Fully-occupied mode only.
 */
class PeriodBelowThreshold
{
    use Dispatchable;

    /**
     * Create a new event instance.
     *
     * @param  CarbonInterface  $at  the moment the drop was applied (the webhook `time_ms`, or the sweep moment)
     * @param  CarbonInterface  $belowSince  the marker as stored on the period (whole seconds)
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
