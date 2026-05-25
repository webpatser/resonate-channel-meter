<?php

use Webpatser\ResonateChannelMeter\ChannelMeterPeriod;
use Webpatser\ResonateChannelMeter\EventHandler;
use Webpatser\ResonateChannelMeter\Tests\Support\TestChat;

beforeEach(function () {
    $this->handler = app(EventHandler::class);
    $this->time = 1700000000000; // ms
});

it('opens a period on channel_occupied', function () {
    $this->handler->handle('app-id', ['name' => 'channel_occupied', 'channel' => 'presence-chat.42'], $this->time);

    $period = ChannelMeterPeriod::first();

    expect($period)->not->toBeNull()
        ->and($period->app_id)->toBe('app-id')
        ->and($period->channel)->toBe('presence-chat.42')
        ->and($period->started_at->getTimestampMs())->toBe($this->time)
        ->and($period->ended_at)->toBeNull();
});

it('attaches the model resolved from the channel', function () {
    $this->handler->handle('app-id', ['name' => 'channel_occupied', 'channel' => 'presence-chat.42'], $this->time);

    $period = ChannelMeterPeriod::first();

    expect($period->model_type)->toBe(TestChat::class)
        ->and($period->model_id)->toBe('42');
});

it('closes the latest open period on channel_vacated', function () {
    $this->handler->handle('app-id', ['name' => 'channel_occupied', 'channel' => 'presence-chat.42'], $this->time);
    $this->handler->handle('app-id', ['name' => 'channel_vacated', 'channel' => 'presence-chat.42'], $this->time + 60_000);

    $period = ChannelMeterPeriod::first();

    expect($period->ended_at)->not->toBeNull()
        ->and($period->ended_at->getTimestampMs())->toBe($this->time + 60_000);
});

it('is idempotent on a repeated channel_occupied', function () {
    $this->handler->handle('app-id', ['name' => 'channel_occupied', 'channel' => 'presence-chat.42'], $this->time);
    $this->handler->handle('app-id', ['name' => 'channel_occupied', 'channel' => 'presence-chat.42'], $this->time + 1_000);

    expect(ChannelMeterPeriod::count())->toBe(1);
});

it('is a no-op on a channel_vacated with no open period', function () {
    $this->handler->handle('app-id', ['name' => 'channel_vacated', 'channel' => 'presence-chat.42'], $this->time);

    expect(ChannelMeterPeriod::count())->toBe(0);
});

it('records channels with no model when no pattern matches', function () {
    $this->handler->handle('app-id', ['name' => 'channel_occupied', 'channel' => 'updates'], $this->time);

    $period = ChannelMeterPeriod::first();

    expect($period->channel)->toBe('updates')
        ->and($period->model_type)->toBeNull()
        ->and($period->model_id)->toBeNull();
});

it('ignores non-occupancy event types', function () {
    $this->handler->handle('app-id', ['name' => 'member_added', 'channel' => 'presence-chat.42'], $this->time);
    $this->handler->handle('app-id', ['name' => 'client_event', 'channel' => 'presence-chat.42'], $this->time);

    expect(ChannelMeterPeriod::count())->toBe(0);
});
