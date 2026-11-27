<?php

namespace Webpatser\ResonateChannelMeter;

use Illuminate\Database\Eloquent\Model;

/**
 * The out-of-order high-water mark of one channel: the envelope `time_ms` of
 * the newest webhook event applied to it.
 *
 * The row exists from a channel's first metered event on, independent of its
 * periods, so the guard holds even before the first period is opened. The
 * event handler locks it for the length of each event, which serialises the
 * check, the apply and the mark update per channel.
 *
 * @property string $app_id
 * @property string $channel
 * @property ?int $last_event_ms
 */
class ChannelMeterMark extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that aren't mass assignable.
     */
    protected $guarded = [];

    /**
     * The model's casts.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'last_event_ms' => 'integer',
        ];
    }
}
