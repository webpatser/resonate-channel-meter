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
     * The domain entity the channel maps to, if any.
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
