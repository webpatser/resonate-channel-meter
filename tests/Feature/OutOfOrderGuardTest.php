<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webpatser\ResonateChannelMeter\ChannelMeterMark;
use Webpatser\ResonateChannelMeter\ChannelMeterPeriod;
use Webpatser\ResonateChannelMeter\EventHandler;

beforeEach(function () {
    $this->handler = app(EventHandler::class);
    $this->channel = 'presence-chat.42';
});

it('drops a late channel_occupied after channel_vacated on a fresh channel', function () {
    $this->handler->handle('app-id', ['name' => 'channel_vacated', 'channel' => $this->channel], 2000);
    $this->handler->handle('app-id', ['name' => 'channel_occupied', 'channel' => $this->channel], 1000);

    expect(ChannelMeterPeriod::count())->toBe(0)
        ->and(ChannelMeterMark::query()->where('channel', $this->channel)->value('last_event_ms'))->toBe(2000);
});

it('keeps one mark row per channel and advances it monotonically', function () {
    $this->handler->handle('app-id', ['name' => 'channel_occupied', 'channel' => $this->channel], 1000);
    $this->handler->handle('app-id', ['name' => 'channel_vacated', 'channel' => $this->channel], 3000);
    $this->handler->handle('app-id', ['name' => 'channel_occupied', 'channel' => $this->channel], 2000);

    expect(ChannelMeterMark::count())->toBe(1)
        ->and(ChannelMeterMark::first()->last_event_ms)->toBe(3000)
        ->and(ChannelMeterPeriod::count())->toBe(1)
        ->and(ChannelMeterPeriod::first()->ended_at)->not->toBeNull();
});

it('keeps marks separate per app and channel', function () {
    $this->handler->handle('app-id', ['name' => 'channel_vacated', 'channel' => $this->channel], 2000);
    $this->handler->handle('other-app', ['name' => 'channel_occupied', 'channel' => $this->channel], 1000);
    $this->handler->handle('app-id', ['name' => 'channel_occupied', 'channel' => 'presence-chat.7'], 1000);

    expect(ChannelMeterPeriod::count())->toBe(2)
        ->and(ChannelMeterMark::count())->toBe(3);
});

it('survives a concurrent first insert of the mark row', function () {
    // The losing worker's insert: the row already exists when it gets there.
    ChannelMeterMark::query()->insertOrIgnore(['app_id' => 'app-id', 'channel' => $this->channel, 'last_event_ms' => null]);
    ChannelMeterMark::query()->insertOrIgnore(['app_id' => 'app-id', 'channel' => $this->channel, 'last_event_ms' => null]);

    $this->handler->handle('app-id', ['name' => 'channel_occupied', 'channel' => $this->channel], 1000);

    expect(ChannelMeterMark::count())->toBe(1)
        ->and(ChannelMeterMark::first()->last_event_ms)->toBe(1000)
        ->and(ChannelMeterPeriod::count())->toBe(1);
});

it('seeds marks from the newest last_event_ms of existing periods', function () {
    Schema::drop('channel_meter_marks');

    $now = now();

    DB::table('channel_meter_periods')->insert([
        ['app_id' => 'app-id', 'channel' => $this->channel, 'started_at' => $now, 'ended_at' => $now, 'open_slot' => null, 'last_event_ms' => 1500],
        ['app_id' => 'app-id', 'channel' => $this->channel, 'started_at' => $now, 'ended_at' => null, 'open_slot' => 1, 'last_event_ms' => 4000],
        ['app_id' => 'app-id', 'channel' => 'presence-chat.7', 'started_at' => $now, 'ended_at' => null, 'open_slot' => 1, 'last_event_ms' => null],
    ]);

    $migration = require __DIR__.'/../../database/migrations/2026_11_16_000001_create_channel_meter_marks_table.php';
    $migration->up();

    expect(ChannelMeterMark::count())->toBe(1)
        ->and(ChannelMeterMark::first()->channel)->toBe($this->channel)
        ->and(ChannelMeterMark::first()->last_event_ms)->toBe(4000);

    // The seeded mark holds back a redelivered vacate older than it.
    $this->handler->handle('app-id', ['name' => 'channel_vacated', 'channel' => $this->channel], 3000);

    expect(ChannelMeterPeriod::query()->whereNull('ended_at')->where('channel', $this->channel)->count())->toBe(1);
});
