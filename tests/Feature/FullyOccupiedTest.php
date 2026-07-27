<?php

use Illuminate\Support\Carbon;
use Webpatser\ResonateChannelMeter\ChannelMeterPeriod;
use Webpatser\ResonateChannelMeter\Contracts\MembershipCounter;
use Webpatser\ResonateChannelMeter\EventHandler;
use Webpatser\ResonateChannelMeter\Tests\Support\FakeMembershipCounter;
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
