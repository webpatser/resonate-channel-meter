<?php

use Illuminate\Support\Carbon;
use Webpatser\ResonateChannelMeter\Support\MeterAction;
use Webpatser\ResonateChannelMeter\Support\MeterDecision;

$now = Carbon::createFromTimestamp(1_700_000_000);

it('opens a period when the threshold is reached and none is open', function () use ($now) {
    $decision = MeterDecision::for(isOpen: false, belowSince: null, memberCount: 2, minMembers: 2, graceSeconds: 30, now: $now);

    expect($decision->action)->toBe(MeterAction::OpenPeriod);
});

it('does nothing when at the threshold with a healthy open period', function () use ($now) {
    $decision = MeterDecision::for(isOpen: true, belowSince: null, memberCount: 2, minMembers: 2, graceSeconds: 30, now: $now);

    expect($decision->action)->toBe(MeterAction::NoOp);
});

it('clears the below marker when the count recovers to the threshold', function () use ($now) {
    $decision = MeterDecision::for(isOpen: true, belowSince: $now->copy()->subSeconds(5), memberCount: 2, minMembers: 2, graceSeconds: 30, now: $now);

    expect($decision->action)->toBe(MeterAction::ClearBelowSince);
});

it('does nothing below the threshold when no period is open', function () use ($now) {
    $decision = MeterDecision::for(isOpen: false, belowSince: null, memberCount: 1, minMembers: 2, graceSeconds: 30, now: $now);

    expect($decision->action)->toBe(MeterAction::NoOp);
});

it('marks the drop moment when first falling below with grace remaining', function () use ($now) {
    $decision = MeterDecision::for(isOpen: true, belowSince: null, memberCount: 1, minMembers: 2, graceSeconds: 30, now: $now);

    expect($decision->action)->toBe(MeterAction::MarkBelowSince)
        ->and($decision->endedAt->equalTo($now))->toBeTrue();
});

it('holds the period open while still inside the grace window', function () use ($now) {
    $decision = MeterDecision::for(isOpen: true, belowSince: $now->copy()->subSeconds(10), memberCount: 1, minMembers: 2, graceSeconds: 30, now: $now);

    expect($decision->action)->toBe(MeterAction::NoOp);
});

it('closes at drop+grace once the grace window has elapsed', function () use ($now) {
    $belowSince = $now->copy()->subSeconds(40);

    $decision = MeterDecision::for(isOpen: true, belowSince: $belowSince, memberCount: 1, minMembers: 2, graceSeconds: 30, now: $now);

    expect($decision->action)->toBe(MeterAction::ClosePeriod)
        ->and($decision->endedAt->equalTo($belowSince->copy()->addSeconds(30)))->toBeTrue();
});

it('closes immediately when grace is zero', function () use ($now) {
    $decision = MeterDecision::for(isOpen: true, belowSince: null, memberCount: 1, minMembers: 2, graceSeconds: 0, now: $now);

    expect($decision->action)->toBe(MeterAction::ClosePeriod)
        ->and($decision->endedAt->equalTo($now))->toBeTrue();
});
