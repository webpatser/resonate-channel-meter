<?php

namespace Webpatser\ResonateChannelMeter\Events;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Events\Dispatchable;
use Webpatser\ResonateChannelMeter\EventHandler;
use Webpatser\ResonateChannelMeter\Support\PeriodCloseCause;

/**
 * An open period was closed by the handler or the sweep.
 *
 * Dispatched by {@see EventHandler} once per close, after the outermost
 * database transaction commits. A period the host closes by writing the row
 * itself dispatches nothing.
 */
class PeriodClosed
{
    use Dispatchable;

    /**
     * Create a new event instance.
     *
     * Dates are typed `CarbonInterface`: they come from the model's casts,
     * which return `CarbonImmutable` when the host calls `Date::use()` with it.
     *
     * @param  CarbonInterface  $at  the period's `ended_at` as written (same value as `$endedAt`)
     * @param  CarbonInterface  $endedAt  the period's `ended_at`, clamped to `started_at`
     */
    public function __construct(
        public readonly string $appId,
        public readonly string $channel,
        public readonly int $periodId,
        public readonly ?string $modelType,
        public readonly ?string $modelId,
        public readonly CarbonInterface $at,
        public readonly CarbonInterface $startedAt,
        public readonly CarbonInterface $endedAt,
        public readonly PeriodCloseCause $cause,
    ) {
        //
    }
}
