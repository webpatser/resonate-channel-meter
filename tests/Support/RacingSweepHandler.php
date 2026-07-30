<?php

namespace Webpatser\ResonateChannelMeter\Tests\Support;

use Closure;
use Webpatser\ResonateChannelMeter\EventHandler;

/**
 * A sweep with a deterministic interleaving point.
 *
 * The callback runs after the sweep has collected the periods that looked
 * open, but before any of them is locked and judged, which is exactly where a
 * concurrent `member_added` webhook lands in production. A sweep that decides
 * on the state it read up front closes a period that has since been reopened.
 */
class RacingSweepHandler extends EventHandler
{
    /** What a concurrent worker does while the sweep is in flight. */
    public ?Closure $duringSweep = null;

    /**
     * Collect the open period ids, then let the concurrent worker run.
     *
     * @return array<int, int>
     */
    protected function openPeriodIds(): array
    {
        $ids = parent::openPeriodIds();

        if ($this->duringSweep !== null) {
            ($this->duringSweep)();
        }

        return $ids;
    }
}
