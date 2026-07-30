<?php

namespace Webpatser\ResonateChannelMeter\Tests\Support;

use Webpatser\ResonateChannelMeter\ChannelMeterPeriod;
use Webpatser\ResonateChannelMeter\EventHandler;

/**
 * An event handler whose "is a period already open?" read is always stale.
 *
 * This is the losing side of the concurrency bug reproduced in one process:
 * two webhook deliveries land on two workers, both read "no open period", and
 * both go on to insert one. With the read defeated, only the database can keep
 * the channel down to a single open period.
 */
class StaleReadEventHandler extends EventHandler
{
    /**
     * Pretend the channel has no open period, whatever the table says.
     */
    protected function openPeriod(string $appId, string $channel, bool $lock = false): ?ChannelMeterPeriod
    {
        return null;
    }
}
