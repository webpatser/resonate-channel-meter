<?php

namespace Webpatser\ResonateChannelMeter\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Webpatser\ResonateChannelMeter\ChannelMeterPeriod;

/**
 * Adds channel-meter period relations to a domain model.
 *
 * Apply on any model that the resolver maps a channel to (a chat, a call,
 * a livestream) so it can pull its own occupancy periods back out without
 * the consumer having to know the channel naming scheme.
 */
trait HasChannelMeter
{
    /**
     * Every occupancy period recorded for this model.
     */
    public function channelMeterPeriods(): MorphMany
    {
        return $this->morphMany(ChannelMeterPeriod::class, 'model');
    }

    /**
     * The periods that are still open right now.
     */
    public function openChannelMeterPeriods(): MorphMany
    {
        return $this->channelMeterPeriods()->whereNull('ended_at');
    }

    /**
     * The total occupied time, in seconds, summed across closed periods.
     *
     * Optionally clamped to a window: a period only contributes the portion
     * that falls inside `[$from, $to]`. Open periods are ignored; close them
     * if you need to bill an in-progress session.
     */
    public function totalChannelMeterSeconds(?Carbon $from = null, ?Carbon $to = null): int
    {
        $seconds = 0;

        foreach ($this->channelMeterPeriods()->whereNotNull('ended_at')->get() as $period) {
            $start = $from === null ? $period->started_at : $period->started_at->max($from);
            $end = $to === null ? $period->ended_at : $period->ended_at->min($to);

            if ($end > $start) {
                $seconds += (int) abs($end->diffInSeconds($start));
            }
        }

        return $seconds;
    }
}
