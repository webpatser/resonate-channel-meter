<?php

namespace Webpatser\ResonateChannelMeter;

use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One occupancy period for a channel: a `channel_occupied` opens it, the
 * matching `channel_vacated` closes it.
 *
 * The polymorphic `model` association maps the channel to a domain entity
 * (the chat, the call, the livestream) resolved by the configured patterns,
 * so a period is queryable both by raw channel name and by domain model.
 *
 * @property string $app_id
 * @property string $channel
 * @property ?string $model_type
 * @property ?string $model_id
 * @property Carbon $started_at
 * @property ?Carbon $ended_at
 * @property ?int $open_slot 1 while open, null once closed: the nullable half of the one-open-period-per-channel unique index
 * @property ?int $last_event_ms the newest event `time_ms` applied to this channel
 * @property ?array<string, mixed> $metadata
 */
class ChannelMeterPeriod extends Model
{
    /**
     * The attributes that aren't mass assignable.
     */
    protected $guarded = [];

    /**
     * The model's casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * Register the model's events.
     *
     * `open_slot` is bookkeeping for the unique index that allows only one
     * open period per (app_id, channel): it mirrors `ended_at` (1 while open,
     * null once closed) and is kept in step here so host code that writes a
     * period directly cannot break the invariant by forgetting it.
     */
    protected static function booted(): void
    {
        static::saving(function (self $period): void {
            $period->open_slot = $period->ended_at === null ? 1 : null;
        });
    }

    /**
     * The domain entity the channel maps to, if any.
     *
     * @return MorphTo<Model, $this>
     */
    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Determine whether the period is still open (occupied).
     */
    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * The duration of the period, or null while the period is still open.
     */
    public function duration(): ?CarbonInterval
    {
        if ($this->ended_at === null) {
            return null;
        }

        return $this->started_at->diffAsCarbonInterval($this->ended_at);
    }
}
