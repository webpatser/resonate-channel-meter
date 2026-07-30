<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webpatser\ResonateChannelMeter\ChannelMeterPeriod;

beforeEach(function () {
    $this->guard = require __DIR__.'/../../database/migrations/2026_07_30_000001_add_open_period_guard_to_channel_meter_periods_table.php';

    expect($this->guard)->toBeInstanceOf(Migration::class);

    // Back to the 0.2.0 table shape, the one an existing install upgrades from.
    $this->guard->down();

    $this->base = Carbon::createFromTimestampMs(1_700_000_000_000);
});

it('keeps one open period per channel and closes the duplicates it inherits', function () {
    DB::table('channel_meter_periods')->insert([
        ['app_id' => 'app-id', 'channel' => 'presence-chat.42', 'started_at' => $this->base, 'ended_at' => null],
        ['app_id' => 'app-id', 'channel' => 'presence-chat.42', 'started_at' => $this->base->copy()->addSeconds(5), 'ended_at' => null],
        ['app_id' => 'app-id', 'channel' => 'presence-chat.42', 'started_at' => $this->base->copy()->addSeconds(9), 'ended_at' => null],
        ['app_id' => 'app-id', 'channel' => 'presence-chat.7', 'started_at' => $this->base, 'ended_at' => null],
        ['app_id' => 'other-app', 'channel' => 'presence-chat.42', 'started_at' => $this->base, 'ended_at' => null],
    ]);

    $this->guard->up();

    $open = ChannelMeterPeriod::query()->whereNull('ended_at')->get();

    // One open period survives per (app_id, channel): the earliest one.
    expect($open)->toHaveCount(3)
        ->and($open->every(fn (ChannelMeterPeriod $period) => $period->started_at->equalTo($this->base)))->toBeTrue()
        ->and($open->every(fn (ChannelMeterPeriod $period) => $period->open_slot === 1))->toBeTrue();

    // The duplicates are closed where they started, so they bill nothing.
    $closed = ChannelMeterPeriod::query()->whereNotNull('ended_at')->get();

    expect($closed)->toHaveCount(2)
        ->and($closed->every(fn (ChannelMeterPeriod $period) => $period->ended_at?->equalTo($period->started_at) === true))->toBeTrue()
        ->and($closed->every(fn (ChannelMeterPeriod $period) => $period->open_slot === null))->toBeTrue();
});

it('leaves a table with no duplicates alone', function () {
    DB::table('channel_meter_periods')->insert([
        ['app_id' => 'app-id', 'channel' => 'presence-chat.42', 'started_at' => $this->base, 'ended_at' => null],
        ['app_id' => 'app-id', 'channel' => 'presence-chat.7', 'started_at' => $this->base, 'ended_at' => $this->base->copy()->addSeconds(60)],
    ]);

    $this->guard->up();

    expect(ChannelMeterPeriod::query()->whereNull('ended_at')->count())->toBe(1)
        ->and(ChannelMeterPeriod::query()->whereNotNull('ended_at')->count())->toBe(1);
});
