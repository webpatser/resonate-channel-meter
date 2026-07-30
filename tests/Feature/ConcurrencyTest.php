<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Webpatser\ResonateChannelMeter\ChannelMeterPeriod;
use Webpatser\ResonateChannelMeter\Contracts\MembershipCounter;
use Webpatser\ResonateChannelMeter\EventHandler;
use Webpatser\ResonateChannelMeter\Tests\Support\FakeMembershipCounter;
use Webpatser\ResonateChannelMeter\Tests\Support\RacingSweepHandler;
use Webpatser\ResonateChannelMeter\Tests\Support\StaleReadEventHandler;
use Webpatser\ResonateChannelMeter\Tests\Support\TestChat;
use Webpatser\ResonateChannelMeter\Tests\Support\UnorderedEventHandler;

beforeEach(function () {
    $this->handler = app(EventHandler::class);
    $this->channel = 'presence-chat.42';
    $this->base = 1_700_000_000_000; // ms
});

it('records one open period when two deliveries race past the guard read', function () {
    $handler = app(StaleReadEventHandler::class);

    // Both deliveries read "no open period" before either of them inserts.
    meterEvent($handler, 'channel_occupied', $this->channel, $this->base);
    meterEvent($handler, 'channel_occupied', $this->channel, $this->base + 20);

    expect(ChannelMeterPeriod::count())->toBe(1)
        ->and(ChannelMeterPeriod::query()->whereNull('ended_at')->count())->toBe(1);
});

it('refuses a second open period for the same channel at the database level', function () {
    ChannelMeterPeriod::query()->create([
        'app_id' => 'app-id',
        'channel' => $this->channel,
        'started_at' => Carbon::createFromTimestampMs($this->base),
    ]);

    expect(fn () => ChannelMeterPeriod::query()->create([
        'app_id' => 'app-id',
        'channel' => $this->channel,
        'started_at' => Carbon::createFromTimestampMs($this->base + 1_000),
    ]))->toThrow(QueryException::class);
});

it('allows a fresh period once the previous one is closed', function () {
    meterEvent($this->handler, 'channel_occupied', $this->channel, $this->base);
    meterEvent($this->handler, 'channel_vacated', $this->channel, $this->base + 60_000);
    meterEvent($this->handler, 'channel_occupied', $this->channel, $this->base + 90_000);

    expect(ChannelMeterPeriod::count())->toBe(2)
        ->and(ChannelMeterPeriod::query()->whereNull('ended_at')->count())->toBe(1);
});

it('ignores a channel_occupied redelivered after the channel_vacated', function () {
    meterEvent($this->handler, 'channel_occupied', $this->channel, $this->base);
    meterEvent($this->handler, 'channel_vacated', $this->channel, $this->base + 60_000);

    // The original delivery times out and is retried after the vacated landed.
    meterEvent($this->handler, 'channel_occupied', $this->channel, $this->base);

    expect(ChannelMeterPeriod::count())->toBe(1)
        ->and(ChannelMeterPeriod::query()->whereNull('ended_at')->count())->toBe(0);
});

it('ignores a late channel_vacated and still bills both sessions', function () {
    $chat = TestChat::query()->create(['id' => 42, 'name' => 'Concurrency']);

    meterEvent($this->handler, 'channel_occupied', $this->channel, $this->base);
    meterEvent($this->handler, 'channel_vacated', $this->channel, $this->base + 60_000);

    // A second session starts, then the first session's vacated is redelivered.
    meterEvent($this->handler, 'channel_occupied', $this->channel, $this->base + 90_000);
    meterEvent($this->handler, 'channel_vacated', $this->channel, $this->base + 30_000);

    expect(ChannelMeterPeriod::query()->whereNull('ended_at')->count())->toBe(1);

    meterEvent($this->handler, 'channel_vacated', $this->channel, $this->base + 150_000);

    $periods = ChannelMeterPeriod::query()->orderBy('started_at')->get();

    expect($periods)->toHaveCount(2)
        ->and($periods->every(fn ($period) => $period->ended_at->greaterThanOrEqualTo($period->started_at)))->toBeTrue()
        ->and($periods->last()->ended_at->getTimestampMs())->toBe($this->base + 150_000)
        ->and($chat->totalChannelMeterSeconds())->toBe(120);
});

it('clamps ended_at to started_at instead of writing a negative period', function () {
    $chat = TestChat::query()->create(['id' => 42, 'name' => 'Concurrency']);

    $handler = app(UnorderedEventHandler::class);

    meterEvent($handler, 'channel_occupied', $this->channel, $this->base + 60_000);
    meterEvent($handler, 'channel_vacated', $this->channel, $this->base);

    $period = ChannelMeterPeriod::sole();

    expect($period->ended_at->getTimestampMs())->toBe($this->base + 60_000)
        ->and($period->ended_at->greaterThanOrEqualTo($period->started_at))->toBeTrue()
        ->and($chat->totalChannelMeterSeconds())->toBe(0);
});

it('does not sweep a period closed that was reoccupied mid-sweep', function () {
    config()->set('resonate-channel-meter.min_members', 2);
    config()->set('resonate-channel-meter.grace_seconds', 30);

    $counter = new FakeMembershipCounter;
    $this->app->instance(MembershipCounter::class, $counter);

    $worker = app(EventHandler::class);
    $sweeper = app(RacingSweepHandler::class);

    $counter->set($this->channel, 2);
    meterEvent($worker, 'member_added', $this->channel, $this->base);

    $counter->set($this->channel, 1);
    meterEvent($worker, 'member_removed', $this->channel, $this->base + 10_000);

    // While the sweep is in flight the member comes back and leaves again, so
    // the drop marker the sweep read up front is already 34 seconds stale.
    $sweeper->duringSweep = function () use ($counter, $worker) {
        $counter->set($this->channel, 2);
        meterEvent($worker, 'member_added', $this->channel, $this->base + 44_000);

        $counter->set($this->channel, 1);
        meterEvent($worker, 'member_removed', $this->channel, $this->base + 44_500);
    };

    $closed = $sweeper->sweep(Carbon::createFromTimestampMs($this->base + 45_000));

    $period = ChannelMeterPeriod::sole();

    expect($closed)->toBe(0)
        ->and($period->ended_at)->toBeNull()
        ->and($period->metadata['below_since'] ?? null)->not->toBeNull();
});
