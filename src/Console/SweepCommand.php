<?php

namespace Webpatser\ResonateChannelMeter\Console;

use Illuminate\Console\Command;
use Webpatser\ResonateChannelMeter\EventHandler;

/**
 * Closes fully-occupied periods whose grace window has elapsed.
 *
 * The {@see EventHandler} pauses the meter as membership events arrive, but a
 * channel that goes quiet after dropping below the threshold, or whose
 * `member_removed` webhook was lost, leaves its period open until the next
 * webhook. Schedule this command (every few seconds) as the backstop that
 * re-evaluates every open period against the live count and durably closes
 * those that stayed below the threshold past their grace window.
 */
class SweepCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'channel-meter:sweep';

    /**
     * @var string
     */
    protected $description = 'Close fully-occupied channel-meter periods whose grace window has elapsed';

    /**
     * Execute the console command.
     */
    public function handle(EventHandler $handler): int
    {
        $closed = $handler->sweep();

        $this->info("Closed {$closed} channel-meter period(s).");

        return self::SUCCESS;
    }
}
