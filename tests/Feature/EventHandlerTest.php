<?php

use Webpatser\ResonateChannelMeter\ChannelMeterMark;
use Webpatser\ResonateChannelMeter\ChannelMeterPeriod;
use Webpatser\ResonateChannelMeter\Contracts\AppScopedMembershipCounter;
use Webpatser\ResonateChannelMeter\Contracts\MembershipCounter;
use Webpatser\ResonateChannelMeter\EventHandler;
use Webpatser\ResonateChannelMeter\NullMembershipCounter;
use Webpatser\ResonateChannelMeter\Tests\Support\FakeMembershipCounter;
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

it('does not meter a channel that matches no pattern by default', function () {
    // Any client can occupy a public channel of any name, so metering every
    // unmatched channel would let an unauthenticated client create rows
    // without bound.
    $this->handler->handle('app-id', ['name' => 'channel_occupied', 'channel' => 'updates'], $this->time);
    $this->handler->handle('app-id', ['name' => 'channel_vacated', 'channel' => 'updates'], $this->time + 1_000);

    expect(ChannelMeterPeriod::count())->toBe(0)
        ->and(ChannelMeterMark::count())->toBe(0);
});

it('does not meter an unmatched channel in fully-occupied mode by default', function () {
    config()->set('resonate-channel-meter.min_members', 2);

    $counter = new FakeMembershipCounter;
    $counter->set('updates', 5);
    $this->app->instance(MembershipCounter::class, $counter);

    meterEvent(app(EventHandler::class), 'member_added', 'updates', $this->time);

    expect(ChannelMeterPeriod::count())->toBe(0)
        ->and(ChannelMeterMark::count())->toBe(0);
});

it('closes a period left open for an unmatched channel once the opt-in is turned off', function () {
    config()->set('resonate-channel-meter.meter_unmatched_channels', true);
    meterEvent(app(EventHandler::class), 'channel_occupied', 'updates', $this->time);

    config()->set('resonate-channel-meter.meter_unmatched_channels', false);
    meterEvent(app(EventHandler::class), 'channel_vacated', 'updates', $this->time + 60_000);

    $period = ChannelMeterPeriod::sole();

    expect($period->ended_at)->not->toBeNull()
        ->and($period->ended_at->getTimestampMs())->toBe($this->time + 60_000);
});

it('writes no rows for a channel_vacated on an unmatched channel without an open period', function () {
    meterEvent($this->handler, 'channel_vacated', 'updates', $this->time);

    expect(ChannelMeterPeriod::count())->toBe(0)
        ->and(ChannelMeterMark::count())->toBe(0);
});

it('lets an ignored prefix override a matching pattern', function () {
    config()->set('resonate-channel-meter.patterns', [
        '#room.{id}' => TestChat::class,
        'presence-chat.{id}' => TestChat::class,
    ]);

    meterEvent(app(EventHandler::class), 'channel_occupied', '#room.42', $this->time);

    expect(ChannelMeterPeriod::count())->toBe(0)
        ->and(ChannelMeterMark::count())->toBe(0);
});

it('meters an unmatched channel in fully-occupied mode with the opt-in', function () {
    config()->set('resonate-channel-meter.min_members', 2);
    config()->set('resonate-channel-meter.meter_unmatched_channels', true);

    $counter = new FakeMembershipCounter;
    $counter->set('updates', 2);
    $this->app->instance(MembershipCounter::class, $counter);

    meterEvent(app(EventHandler::class), 'member_added', 'updates', $this->time);

    $period = ChannelMeterPeriod::sole();

    expect($period->channel)->toBe('updates')
        ->and($period->model_type)->toBeNull()
        ->and($period->ended_at)->toBeNull();
});

it('binds a default counter that answers both contracts', function () {
    $counter = app(MembershipCounter::class);

    expect($counter)->toBeInstanceOf(NullMembershipCounter::class)
        ->toBeInstanceOf(MembershipCounter::class)
        ->toBeInstanceOf(AppScopedMembershipCounter::class)
        ->and($counter->count('presence-chat.42'))->toBe(0)
        ->and($counter->count('app-id', 'presence-chat.42'))->toBe(0);
});

it('records channels with no model when meter_unmatched_channels is on', function () {
    config()->set('resonate-channel-meter.meter_unmatched_channels', true);

    $this->handler->handle('app-id', ['name' => 'channel_occupied', 'channel' => 'updates'], $this->time);
    $this->handler->handle('app-id', ['name' => 'channel_vacated', 'channel' => 'updates'], $this->time + 60_000);

    $period = ChannelMeterPeriod::sole();

    expect($period->channel)->toBe('updates')
        ->and($period->model_type)->toBeNull()
        ->and($period->model_id)->toBeNull()
        ->and($period->ended_at->getTimestampMs())->toBe($this->time + 60_000);
});

it('ignores non-occupancy event types', function () {
    $this->handler->handle('app-id', ['name' => 'member_added', 'channel' => 'presence-chat.42'], $this->time);
    $this->handler->handle('app-id', ['name' => 'client_event', 'channel' => 'presence-chat.42'], $this->time);

    expect(ChannelMeterPeriod::count())->toBe(0);
});

it('does not meter a reserved channel', function () {
    // The Pusher protocol reserves "#" for channels the server owns rather than
    // the application. webpatser/resonate-users puts a signed-in connection on
    // "#server-to-user-{id}", which is a person's session and not a room. With
    // meter_unmatched_channels on, a channel matching no pattern is recorded,
    // so without the exclusion every sign-in would open a billable period.
    config()->set('resonate-channel-meter.meter_unmatched_channels', true);

    $handler = app(EventHandler::class);
    $base = 1_700_000_000_000;

    meterEvent($handler, 'channel_occupied', '#server-to-user-42', $base);
    meterEvent($handler, 'channel_vacated', '#server-to-user-42', $base + 5_000);

    expect(ChannelMeterPeriod::count())->toBe(0);
});

it('still meters an ordinary channel while reserved ones are ignored', function () {
    $handler = app(EventHandler::class);
    $base = 1_700_000_000_000;

    meterEvent($handler, 'channel_occupied', '#server-to-user-42', $base);
    meterEvent($handler, 'channel_occupied', 'presence-chat.42', $base);

    expect(ChannelMeterPeriod::count())->toBe(1)
        ->and(ChannelMeterPeriod::query()->value('channel'))->toBe('presence-chat.42');
});

it('meters everything when the exclusion list is emptied and unmatched channels are metered', function () {
    config()->set('resonate-channel-meter.ignore_channel_prefixes', []);
    config()->set('resonate-channel-meter.meter_unmatched_channels', true);

    $handler = app(EventHandler::class);

    meterEvent($handler, 'channel_occupied', '#server-to-user-42', 1_700_000_000_000);

    expect(ChannelMeterPeriod::count())->toBe(1);
});
