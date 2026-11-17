<?php

use Illuminate\Support\Carbon;
use Webpatser\ResonateChannelMeter\ChannelMeterPeriod;
use Webpatser\ResonateChannelMeter\Contracts\MembershipCounter;
use Webpatser\ResonateChannelMeter\EventHandler;
use Webpatser\ResonateChannelMeter\Tests\Support\FakeMembershipCounter;
use Webpatser\ResonateChannelMeter\Tests\Support\LegacyFakeMembershipCounter;
use Webpatser\ResonateChannelMeter\Tests\Support\TestChat;

beforeEach(function () {
    config()->set('resonate-channel-meter.min_members', 2);
    config()->set('resonate-channel-meter.grace_seconds', 30);

    $this->counter = new FakeMembershipCounter;
    $this->app->instance(MembershipCounter::class, $this->counter);

    $this->handler = app(EventHandler::class);
    $this->channel = 'presence-chat.42';
    $this->base = 1_700_000_000_000; // ms
});

it('opens a period only once the channel is fully occupied', function () {
    $this->counter->set($this->channel, 1);
    meterEvent($this->handler, 'member_added', $this->channel, $this->base);
    expect(ChannelMeterPeriod::count())->toBe(0);

    $this->counter->set($this->channel, 2);
    meterEvent($this->handler, 'member_added', $this->channel, $this->base + 5_000);

    $period = ChannelMeterPeriod::sole();
    expect($period->ended_at)->toBeNull()
        ->and($period->started_at->getTimestampMs())->toBe($this->base + 5_000)
        ->and($period->model_type)->toBe(TestChat::class)
        ->and($period->model_id)->toBe('42');
});

it('is idempotent while fully occupied', function () {
    $this->counter->set($this->channel, 2);
    meterEvent($this->handler, 'member_added', $this->channel, $this->base);
    meterEvent($this->handler, 'member_added', $this->channel, $this->base + 1_000);

    expect(ChannelMeterPeriod::count())->toBe(1);
});

it('marks below_since but keeps the period open during grace', function () {
    $this->counter->set($this->channel, 2);
    meterEvent($this->handler, 'member_added', $this->channel, $this->base);

    $this->counter->set($this->channel, 1);
    meterEvent($this->handler, 'member_removed', $this->channel, $this->base + 10_000);

    $period = ChannelMeterPeriod::sole();
    expect($period->ended_at)->toBeNull()
        ->and($period->metadata['below_since'] ?? null)->not->toBeNull();
});

it('continues unbroken when a member returns within grace', function () {
    $this->counter->set($this->channel, 2);
    meterEvent($this->handler, 'member_added', $this->channel, $this->base);

    $this->counter->set($this->channel, 1);
    meterEvent($this->handler, 'member_removed', $this->channel, $this->base + 10_000);

    $this->counter->set($this->channel, 2);
    meterEvent($this->handler, 'member_added', $this->channel, $this->base + 20_000);

    $period = ChannelMeterPeriod::sole();
    expect($period->ended_at)->toBeNull()
        ->and($period->metadata['below_since'] ?? null)->toBeNull();
});

it('closes at drop+grace when grace elapses', function () {
    $this->counter->set($this->channel, 2);
    meterEvent($this->handler, 'member_added', $this->channel, $this->base);

    $this->counter->set($this->channel, 1);
    meterEvent($this->handler, 'member_removed', $this->channel, $this->base + 10_000); // below_since here
    meterEvent($this->handler, 'member_removed', $this->channel, $this->base + 50_000); // past grace

    $period = ChannelMeterPeriod::sole();
    expect($period->ended_at->getTimestampMs())->toBe($this->base + 40_000); // 10s + 30s grace
});

it('sweeps a dangling period closed once grace has elapsed', function () {
    $this->counter->set($this->channel, 2);
    meterEvent($this->handler, 'member_added', $this->channel, $this->base);

    $this->counter->set($this->channel, 1);
    meterEvent($this->handler, 'member_removed', $this->channel, $this->base + 10_000);

    $closed = $this->handler->sweep(Carbon::createFromTimestampMs($this->base + 45_000));

    expect($closed)->toBe(1)
        ->and(ChannelMeterPeriod::sole()->ended_at->getTimestampMs())->toBe($this->base + 40_000);
});

it('sweep marks a period below when member_removed was lost, then closes it at the deadline', function () {
    $this->counter->set($this->channel, 2);
    meterEvent($this->handler, 'member_added', $this->channel, $this->base);

    $this->counter->set($this->channel, 1); // a member left, but no event delivered

    $closed = $this->handler->sweep(Carbon::createFromTimestampMs($this->base + 60_000));

    $period = ChannelMeterPeriod::sole();
    expect($closed)->toBe(0)
        ->and($period->ended_at)->toBeNull()
        ->and(Carbon::parse($period->metadata['below_since'])->getTimestampMs())->toBe($this->base + 60_000);

    expect($this->handler->sweep(Carbon::createFromTimestampMs($this->base + 89_000)))->toBe(0)
        ->and(ChannelMeterPeriod::sole()->ended_at)->toBeNull();

    $closed = $this->handler->sweep(Carbon::createFromTimestampMs($this->base + 95_000));

    $period = ChannelMeterPeriod::sole();
    expect($closed)->toBe(1)
        ->and($period->ended_at->getTimestampMs())->toBe($this->base + 90_000) // sweep mark + 30s grace
        ->and($period->metadata)->toBeNull();
});

it('sweep leaves a fully occupied period without below_since untouched', function () {
    $this->counter->set($this->channel, 2);
    meterEvent($this->handler, 'member_added', $this->channel, $this->base);

    $closed = $this->handler->sweep(Carbon::createFromTimestampMs($this->base + 60_000));

    $period = ChannelMeterPeriod::sole();
    expect($closed)->toBe(0)
        ->and($period->ended_at)->toBeNull()
        ->and($period->metadata)->toBeNull();
});

it('sweep closes a period at once when member_removed was lost and grace is zero', function () {
    config()->set('resonate-channel-meter.grace_seconds', 0);

    $this->counter->set($this->channel, 2);
    meterEvent($this->handler, 'member_added', $this->channel, $this->base);

    $this->counter->set($this->channel, 1);

    $closed = $this->handler->sweep(Carbon::createFromTimestampMs($this->base + 60_000));

    expect($closed)->toBe(1)
        ->and(ChannelMeterPeriod::sole()->ended_at->getTimestampMs())->toBe($this->base + 60_000);
});

it('a late member_removed after the sweep marked the period keeps the sweep marker', function () {
    $this->counter->set($this->channel, 2);
    meterEvent($this->handler, 'member_added', $this->channel, $this->base);

    $this->counter->set($this->channel, 1);
    $this->handler->sweep(Carbon::createFromTimestampMs($this->base + 60_000));

    meterEvent($this->handler, 'member_removed', $this->channel, $this->base + 70_000);

    $period = ChannelMeterPeriod::sole();
    expect($period->ended_at)->toBeNull()
        ->and(Carbon::parse($period->metadata['below_since'])->getTimestampMs())->toBe($this->base + 60_000);
});

it('counts members per application, not across apps sharing a channel name', function () {
    // Another application has the same channel name fully occupied; this
    // application's channel has one member and must not open a period.
    $this->counter->set($this->channel, 2, 'other-app');
    $this->counter->set($this->channel, 1, 'app-id');

    $this->handler->handle('app-id', ['name' => 'member_added', 'channel' => $this->channel], $this->base);
    $this->handler->handle('other-app', ['name' => 'member_added', 'channel' => $this->channel], $this->base);

    expect(ChannelMeterPeriod::query()->where('app_id', 'app-id')->count())->toBe(0)
        ->and(ChannelMeterPeriod::query()->where('app_id', 'other-app')->count())->toBe(1);
});

it('sweep reads each period\'s own application count', function () {
    $this->counter->set($this->channel, 2, 'app-id');
    $this->counter->set($this->channel, 2, 'other-app');

    $this->handler->handle('app-id', ['name' => 'member_added', 'channel' => $this->channel], $this->base);
    $this->handler->handle('other-app', ['name' => 'member_added', 'channel' => $this->channel], $this->base);

    // A member left this application's channel without a delivered event;
    // the other application's channel of the same name is still full.
    $this->counter->set($this->channel, 1, 'app-id');

    $this->handler->sweep(Carbon::createFromTimestampMs($this->base + 60_000));

    $mine = ChannelMeterPeriod::query()->where('app_id', 'app-id')->sole();
    $theirs = ChannelMeterPeriod::query()->where('app_id', 'other-app')->sole();

    expect($mine->metadata['below_since'] ?? null)->not->toBeNull()
        ->and(Carbon::parse($mine->metadata['below_since'])->getTimestampMs())->toBe($this->base + 60_000)
        ->and($theirs->metadata)->toBeNull();
});

it('still works with a legacy counter that receives only the channel', function () {
    $legacy = new LegacyFakeMembershipCounter;
    $this->app->instance(MembershipCounter::class, $legacy);
    $handler = app(EventHandler::class);

    $legacy->set($this->channel, 2);
    meterEvent($handler, 'member_added', $this->channel, $this->base);

    $legacy->set($this->channel, 1);
    $handler->sweep(Carbon::createFromTimestampMs($this->base + 60_000));

    $period = ChannelMeterPeriod::sole();

    expect($legacy->calls)->toBe([[$this->channel], [$this->channel]])
        ->and($period->ended_at)->toBeNull()
        ->and(Carbon::parse($period->metadata['below_since'])->getTimestampMs())->toBe($this->base + 60_000);
});

it('sweep clears below_since instead of closing when the member returned', function () {
    $this->counter->set($this->channel, 2);
    meterEvent($this->handler, 'member_added', $this->channel, $this->base);

    $this->counter->set($this->channel, 1);
    meterEvent($this->handler, 'member_removed', $this->channel, $this->base + 10_000);

    $this->counter->set($this->channel, 2); // back, but no event delivered
    $closed = $this->handler->sweep(Carbon::createFromTimestampMs($this->base + 45_000));

    $period = ChannelMeterPeriod::sole();
    expect($closed)->toBe(0)
        ->and($period->ended_at)->toBeNull()
        ->and($period->metadata['below_since'] ?? null)->toBeNull();
});
