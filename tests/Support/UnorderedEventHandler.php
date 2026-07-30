<?php

namespace Webpatser\ResonateChannelMeter\Tests\Support;

use Webpatser\ResonateChannelMeter\EventHandler;

/**
 * An event handler with the per-channel high-water mark switched off.
 *
 * Lets a test drive `close()` with a moment that precedes `started_at`, which
 * the ordering guard would normally reject, so the clamp inside `closePeriod()`
 * is verified on its own rather than through the guard in front of it.
 */
class UnorderedEventHandler extends EventHandler
{
    /**
     * Treat every event as newer than everything applied so far.
     */
    protected function isStale(string $appId, string $channel, int $timeMs): bool
    {
        return false;
    }
}
