<?php

namespace Webpatser\ResonateChannelMeter\Events;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Events\Dispatchable;
use Webpatser\ResonateChannelMeter\EventHandler;

/**
 * A metered period was opened for a channel.
 *
 * Dispatched by {@see EventHandler} once per inserted period, after the
 * outermost database transaction commits. A delivery that finds a period
 * already open, or loses the insert race, dispatches nothing.
 */
class PeriodOpened
{
    use Dispatchable;

    /**
     * Create a new event instance.
     *
     * @param  CarbonInterface  $at  the period's `started_at`
     */
    public function __construct(
        public readonly string $appId,
        public readonly string $channel,
        public readonly int $periodId,
        public readonly ?string $modelType,
        public readonly ?string $modelId,
        public readonly CarbonInterface $at,
    ) {
        //
    }
}
