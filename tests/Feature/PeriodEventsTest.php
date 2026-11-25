<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Webpatser\ResonateChannelMeter\ChannelMeterPeriod;
use Webpatser\ResonateChannelMeter\Contracts\MembershipCounter;
use Webpatser\ResonateChannelMeter\EventHandler;
use Webpatser\ResonateChannelMeter\Events\PeriodBelowThreshold;
use Webpatser\ResonateChannelMeter\Events\PeriodClosed;
use Webpatser\ResonateChannelMeter\Events\PeriodOpened;
use Webpatser\ResonateChannelMeter\Events\PeriodRestored;
use Webpatser\ResonateChannelMeter\Resolvers\ChannelResolver;
use Webpatser\ResonateChannelMeter\Support\PeriodCloseCause;
use Webpatser\ResonateChannelMeter\Tests\Support\FakeMembershipCounter;
use Webpatser\ResonateChannelMeter\Tests\Support\StaleReadEventHandler;
use Webpatser\ResonateChannelMeter\Tests\Support\TestChat;

const PERIOD_EVENTS = [PeriodOpened::class, PeriodBelowThreshold::class, PeriodRestored::class, PeriodClosed::class];

beforeEach(function () {
    $this->channel = 'presence-chat.42';
    $this->base = 1_700_000_000_000; // ms, a whole second
});

describe('room occupancy', function () {
    beforeEach(function () {
        Event::fake(PERIOD_EVENTS);
        $this->handler = app(EventHandler::class);
    });

    it('dispatches PeriodOpened once with the inserted period', function () {
        meterEvent($this->handler, 'channel_occupied', $this->channel, $this->base);

        $period = ChannelMeterPeriod::sole();

        Event::assertDispatchedTimes(PeriodOpened::class, 1);
        Event::assertDispatched(PeriodOpened::class, fn (PeriodOpened $e): bool => $e->appId === 'app-id'
            && $e->channel === $this->channel
            && $e->periodId === (int) $period->getKey()
            && $e->modelType === TestChat::class
            && $e->modelId === '42'
            && $e->at->getTimestampMs() === $this->base);
    });

    it('dispatches PeriodClosed with cause channel_vacated', function () {
        meterEvent($this->handler, 'channel_occupied', $this->channel, $this->base);
        meterEvent($this->handler, 'channel_vacated', $this->channel, $this->base + 60_000);

        $period = ChannelMeterPeriod::sole();

        Event::assertDispatchedTimes(PeriodClosed::class, 1);
        Event::assertDispatched(PeriodClosed::class, fn (PeriodClosed $e): bool => $e->periodId === (int) $period->getKey()
            && $e->cause === PeriodCloseCause::ChannelVacated
            && $e->startedAt->getTimestampMs() === $this->base
            && $e->endedAt->getTimestampMs() === $this->base + 60_000
            && $e->at->getTimestampMs() === $this->base + 60_000
            && $e->modelId === '42');
    });

    it('dispatches nothing more for a redelivered webhook with the same time_ms', function () {
        meterEvent($this->handler, 'channel_occupied', $this->channel, $this->base);
        meterEvent($this->handler, 'channel_occupied', $this->channel, $this->base);
        meterEvent($this->handler, 'channel_vacated', $this->channel, $this->base + 60_000);
        meterEvent($this->handler, 'channel_vacated', $this->channel, $this->base + 60_000);

        Event::assertDispatchedTimes(PeriodOpened::class, 1);
        Event::assertDispatchedTimes(PeriodClosed::class, 1);
    });

    it('dispatches nothing for an open ignored by the unique index', function () {
        $stale = app(StaleReadEventHandler::class);

        meterEvent($stale, 'channel_occupied', $this->channel, $this->base);
        meterEvent($stale, 'channel_occupied', $this->channel, $this->base + 1_000);

        expect(ChannelMeterPeriod::count())->toBe(1);
        Event::assertDispatchedTimes(PeriodOpened::class, 1);
    });

    it('dispatches nothing when the surrounding transaction rolls back', function () {
        try {
            DB::transaction(function () {
                meterEvent($this->handler, 'channel_occupied', $this->channel, $this->base);

                throw new RuntimeException('outer failure');
            });
        } catch (RuntimeException) {
            //
        }

        expect(ChannelMeterPeriod::count())->toBe(0);
        Event::assertNotDispatched(PeriodOpened::class);
    });

    it('waits for the outermost commit before dispatching', function () {
        DB::transaction(function () {
            meterEvent($this->handler, 'channel_occupied', $this->channel, $this->base);

            Event::assertNotDispatched(PeriodOpened::class);
        });

        Event::assertDispatchedTimes(PeriodOpened::class, 1);
    });
});

describe('fully occupied', function () {
    beforeEach(function () {
        Event::fake(PERIOD_EVENTS);

        config()->set('resonate-channel-meter.min_members', 2);
        config()->set('resonate-channel-meter.grace_seconds', 30);

        $this->counter = new FakeMembershipCounter;
        $this->app->instance(MembershipCounter::class, $this->counter);
        $this->handler = app(EventHandler::class);
    });

    it('dispatches below threshold and restored once each, naming the same marker', function () {
        $this->counter->set($this->channel, 2);
        meterEvent($this->handler, 'member_added', $this->channel, $this->base);

        $this->counter->set($this->channel, 1);
        meterEvent($this->handler, 'member_removed', $this->channel, $this->base + 10_000);
        // Redelivered: the decision is NoOp end to end, so no second event. The
        // marker guard itself is driven directly in "the marker guards" below.
        meterEvent($this->handler, 'member_removed', $this->channel, $this->base + 10_000);
        meterEvent($this->handler, 'member_removed', $this->channel, $this->base + 15_000); // still in grace

        $this->counter->set($this->channel, 2);
        meterEvent($this->handler, 'member_added', $this->channel, $this->base + 20_000);
        meterEvent($this->handler, 'member_added', $this->channel, $this->base + 20_000); // redelivered: NoOp end to end

        $period = ChannelMeterPeriod::sole();
        $marker = $this->base + 10_000;

        Event::assertDispatchedTimes(PeriodOpened::class, 1);
        Event::assertDispatchedTimes(PeriodBelowThreshold::class, 1);
        Event::assertDispatchedTimes(PeriodRestored::class, 1);
        Event::assertNotDispatched(PeriodClosed::class);

        Event::assertDispatched(PeriodBelowThreshold::class, fn (PeriodBelowThreshold $e): bool => $e->periodId === (int) $period->getKey()
            && $e->at->getTimestampMs() === $marker
            && $e->belowSince->getTimestampMs() === $marker
            && $e->modelType === TestChat::class
            && $e->modelId === '42');
        Event::assertDispatched(PeriodRestored::class, fn (PeriodRestored $e): bool => $e->periodId === (int) $period->getKey()
            && $e->at->getTimestampMs() === $this->base + 20_000
            && $e->belowSince->getTimestampMs() === $marker);
    });

    it('dispatches nothing end to end for webhooks and a sweep at the threshold with no marker', function () {
        $this->counter->set($this->channel, 2);
        meterEvent($this->handler, 'member_added', $this->channel, $this->base);
        meterEvent($this->handler, 'member_added', $this->channel, $this->base + 5_000);
        $this->handler->sweep(Carbon::createFromTimestampMs($this->base + 60_000));

        Event::assertDispatchedTimes(PeriodOpened::class, 1);
        Event::assertNotDispatched(PeriodRestored::class);
        Event::assertNotDispatched(PeriodBelowThreshold::class);
    });

    it('dispatches PeriodClosed with cause grace_expired from a webhook', function () {
        $this->counter->set($this->channel, 2);
        meterEvent($this->handler, 'member_added', $this->channel, $this->base);

        $this->counter->set($this->channel, 1);
        meterEvent($this->handler, 'member_removed', $this->channel, $this->base + 10_000);
        meterEvent($this->handler, 'member_removed', $this->channel, $this->base + 50_000);
        meterEvent($this->handler, 'member_removed', $this->channel, $this->base + 50_000); // redelivered

        Event::assertDispatchedTimes(PeriodClosed::class, 1);
        Event::assertDispatched(PeriodClosed::class, fn (PeriodClosed $e): bool => $e->cause === PeriodCloseCause::GraceExpired
            && $e->startedAt->getTimestampMs() === $this->base
            && $e->endedAt->getTimestampMs() === $this->base + 40_000);
        Event::assertNotDispatched(PeriodRestored::class);
    });

    it('dispatches PeriodClosed with cause sweep_grace_expired from the sweep', function () {
        $this->counter->set($this->channel, 2);
        meterEvent($this->handler, 'member_added', $this->channel, $this->base);

        $this->counter->set($this->channel, 1); // left without a delivered event

        $this->handler->sweep(Carbon::createFromTimestampMs($this->base + 60_000));
        $this->handler->sweep(Carbon::createFromTimestampMs($this->base + 95_000));
        $this->handler->sweep(Carbon::createFromTimestampMs($this->base + 120_000));

        Event::assertDispatchedTimes(PeriodBelowThreshold::class, 1);
        Event::assertDispatched(PeriodBelowThreshold::class, fn (PeriodBelowThreshold $e): bool => $e->at->getTimestampMs() === $this->base + 60_000
            && $e->belowSince->getTimestampMs() === $this->base + 60_000);

        Event::assertDispatchedTimes(PeriodClosed::class, 1);
        Event::assertDispatched(PeriodClosed::class, fn (PeriodClosed $e): bool => $e->cause === PeriodCloseCause::SweepGraceExpired
            && $e->endedAt->getTimestampMs() === $this->base + 90_000);
    });

    it('dispatches PeriodRestored from the sweep when the member returned unannounced', function () {
        $this->counter->set($this->channel, 2);
        meterEvent($this->handler, 'member_added', $this->channel, $this->base);

        $this->counter->set($this->channel, 1);
        meterEvent($this->handler, 'member_removed', $this->channel, $this->base + 10_000);

        $this->counter->set($this->channel, 2);
        $this->handler->sweep(Carbon::createFromTimestampMs($this->base + 45_000));

        Event::assertDispatchedTimes(PeriodRestored::class, 1);
        Event::assertDispatched(PeriodRestored::class, fn (PeriodRestored $e): bool => $e->at->getTimestampMs() === $this->base + 45_000
            && $e->belowSince->getTimestampMs() === $this->base + 10_000);
    });
});

describe('the marker guards', function () {
    beforeEach(function () {
        Event::fake(PERIOD_EVENTS);

        config()->set('resonate-channel-meter.min_members', 2);
        config()->set('resonate-channel-meter.grace_seconds', 30);

        $this->counter = new FakeMembershipCounter;
        $this->app->instance(MembershipCounter::class, $this->counter);

        // Drives the protected marker write directly, so the guard runs even
        // where MeterDecision would have answered NoOp.
        $this->probe = new class(app(ChannelResolver::class), $this->counter) extends EventHandler
        {
            public function changeMarker(?ChannelMeterPeriod $period, ?Carbon $belowSince, Carbon $at): void
            {
                $this->changeBelowSince($period, $belowSince, $at);
            }
        };

        $this->counter->set($this->channel, 2);
        meterEvent($this->probe, 'member_added', $this->channel, $this->base);
        $this->period = ChannelMeterPeriod::sole();
    });

    it('dispatches no PeriodRestored for a clear while no marker is set', function () {
        $this->probe->changeMarker($this->period, null, Carbon::createFromTimestampMs($this->base + 5_000));

        expect($this->period->refresh()->metadata)->toBeNull();
        Event::assertNotDispatched(PeriodRestored::class);
        Event::assertNotDispatched(PeriodBelowThreshold::class);
    });

    it('dispatches PeriodBelowThreshold once when a set marker is set again', function () {
        $first = Carbon::createFromTimestampMs($this->base + 10_000);

        $this->probe->changeMarker($this->period, $first, $first);
        $this->probe->changeMarker($this->period, $first, $first);
        $this->probe->changeMarker($this->period, $first->copy()->addSeconds(5), $first->copy()->addSeconds(5));

        expect($this->period->refresh()->metadata)->toHaveKey('below_since');
        Event::assertDispatchedTimes(PeriodBelowThreshold::class, 1);
        Event::assertNotDispatched(PeriodRestored::class);
    });

    it('dispatches PeriodRestored once when a marker is cleared twice', function () {
        $marker = Carbon::createFromTimestampMs($this->base + 10_000);
        $this->probe->changeMarker($this->period, $marker, $marker);

        $this->probe->changeMarker($this->period, null, Carbon::createFromTimestampMs($this->base + 20_000));
        $this->probe->changeMarker($this->period, null, Carbon::createFromTimestampMs($this->base + 20_000));

        Event::assertDispatchedTimes(PeriodRestored::class, 1);
        Event::assertDispatched(PeriodRestored::class, fn (PeriodRestored $e): bool => $e->belowSince->getTimestampMs() === $this->base + 10_000);
    });
});

describe('host date class', function () {
    beforeEach(function () {
        Event::fake(PERIOD_EVENTS);

        config()->set('resonate-channel-meter.min_members', 2);
        config()->set('resonate-channel-meter.grace_seconds', 30);

        $this->counter = new FakeMembershipCounter;
        $this->app->instance(MembershipCounter::class, $this->counter);
        $this->handler = app(EventHandler::class);

        // Open, drop below, restore, drop again, close past grace; returns the raw row.
        $this->sequence = function (string $channel): array {
            $this->counter->set($channel, 2);
            meterEvent($this->handler, 'member_added', $channel, $this->base);
            $this->counter->set($channel, 1);
            meterEvent($this->handler, 'member_removed', $channel, $this->base + 10_000);
            $this->counter->set($channel, 2);
            meterEvent($this->handler, 'member_added', $channel, $this->base + 20_000);
            $this->counter->set($channel, 1);
            meterEvent($this->handler, 'member_removed', $channel, $this->base + 30_000);
            meterEvent($this->handler, 'member_removed', $channel, $this->base + 70_000);

            return (array) DB::table('channel_meter_periods')
                ->where('channel', $channel)
                ->sole(['started_at', 'ended_at', 'open_slot', 'metadata']);
        };
    });

    it('closes the period the same way under Date::use(CarbonImmutable::class)', function () {
        $default = ($this->sequence)('presence-chat.42');

        Date::use(CarbonImmutable::class);

        try {
            $immutable = ($this->sequence)('presence-chat.43');
        } finally {
            Date::useDefault();
        }

        expect($immutable)->toBe($default)
            ->and($immutable['ended_at'])->not->toBeNull()
            ->and($immutable['open_slot'])->toBeNull();

        $on43 = fn (object $e): bool => $e->channel === 'presence-chat.43';

        Event::assertDispatchedTimes(PeriodOpened::class, 2);
        Event::assertDispatched(PeriodOpened::class, $on43);
        Event::assertDispatched(PeriodBelowThreshold::class, $on43);
        Event::assertDispatched(PeriodRestored::class, $on43);
        Event::assertDispatched(PeriodClosed::class, fn (PeriodClosed $e): bool => $on43($e)
            && $e->cause === PeriodCloseCause::GraceExpired
            && $e->endedAt instanceof CarbonImmutable
            && $e->endedAt->getTimestampMs() === $this->base + 60_000);
    });
});

describe('payload failures', function () {
    it('reports a throwing event factory without changing the period or the result', function () {
        Exceptions::fake();
        Event::fake(PERIOD_EVENTS);

        config()->set('resonate-channel-meter.min_members', 2);
        config()->set('resonate-channel-meter.grace_seconds', 0);

        $counter = new FakeMembershipCounter;

        // Every payload factory throws, as a broken constructor would.
        $handler = new class(app(ChannelResolver::class), $counter) extends EventHandler
        {
            protected function dispatchAfterCommit(Closure $event): void
            {
                parent::dispatchAfterCommit(static function (): object {
                    throw new RuntimeException('payload failed');
                });
            }
        };

        $counter->set($this->channel, 2);
        meterEvent($handler, 'member_added', $this->channel, $this->base);

        $period = ChannelMeterPeriod::sole();
        expect($period->isOpen())->toBeTrue();

        $counter->set($this->channel, 1);
        $closed = $handler->sweep(Carbon::createFromTimestampMs($this->base + 60_000));

        $period->refresh();
        expect($closed)->toBe(1)
            ->and($period->ended_at->getTimestampMs())->toBe($this->base + 60_000)
            ->and($period->open_slot)->toBeNull();

        Event::assertNotDispatched(PeriodOpened::class);
        Event::assertNotDispatched(PeriodClosed::class);
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'payload failed');
    });
});

describe('listener failures', function () {
    it('reports a throwing listener without changing the period or the result', function () {
        Exceptions::fake();

        config()->set('resonate-channel-meter.min_members', 2);
        config()->set('resonate-channel-meter.grace_seconds', 0);

        $counter = new FakeMembershipCounter;
        $this->app->instance(MembershipCounter::class, $counter);
        $handler = app(EventHandler::class);

        foreach (PERIOD_EVENTS as $event) {
            Event::listen($event, function () use ($event): void {
                throw new RuntimeException('listener failed for '.$event);
            });
        }

        $counter->set($this->channel, 2);
        meterEvent($handler, 'member_added', $this->channel, $this->base);

        $period = ChannelMeterPeriod::sole();
        expect($period->isOpen())->toBeTrue()
            ->and($period->started_at->getTimestampMs())->toBe($this->base);

        $counter->set($this->channel, 1);
        $closed = $handler->sweep(Carbon::createFromTimestampMs($this->base + 60_000));

        $period->refresh();
        expect($closed)->toBe(1)
            ->and($period->ended_at->getTimestampMs())->toBe($this->base + 60_000)
            ->and($period->open_slot)->toBeNull();

        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'listener failed for '.PeriodOpened::class);
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'listener failed for '.PeriodClosed::class);
    });

    it('keeps the webhook response when a listener throws', function () {
        Exceptions::fake();

        Event::listen(PeriodOpened::class, function (): void {
            throw new RuntimeException('listener failed');
        });

        $webhook = signedWebhook([['name' => 'channel_occupied', 'channel' => $this->channel]]);

        postSignedWebhook($this, $webhook['body'], $webhook['signature'])->assertSuccessful();

        expect(ChannelMeterPeriod::sole()->isOpen())->toBeTrue();
        Exceptions::assertReported(RuntimeException::class);
    });
});
