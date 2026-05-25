<?php

use Webpatser\ResonateChannelMeter\ChannelMeterPeriod;
use Webpatser\ResonateChannelMeter\Tests\Support\TestChat;

it('exposes the polymorphic period relation on the domain model', function () {
    $chat = TestChat::create(['id' => 42, 'name' => 'lounge']);

    ChannelMeterPeriod::create([
        'app_id' => 'app-id',
        'channel' => 'presence-chat.42',
        'model_type' => TestChat::class,
        'model_id' => '42',
        'started_at' => now()->subMinutes(5),
        'ended_at' => now(),
    ]);

    expect($chat->channelMeterPeriods)->toHaveCount(1)
        ->and($chat->openChannelMeterPeriods)->toHaveCount(0);
});

it('sums total occupied seconds across closed periods', function () {
    $chat = TestChat::create(['id' => 42]);

    $base = now()->startOfHour();

    foreach ([[0, 60], [120, 180], [600, 720]] as [$offsetStart, $offsetEnd]) {
        ChannelMeterPeriod::create([
            'app_id' => 'app-id',
            'channel' => 'presence-chat.42',
            'model_type' => TestChat::class,
            'model_id' => '42',
            'started_at' => $base->copy()->addSeconds($offsetStart),
            'ended_at' => $base->copy()->addSeconds($offsetEnd),
        ]);
    }

    expect($chat->totalChannelMeterSeconds())->toBe(60 + 60 + 120);
});

it('ignores open periods in the total', function () {
    $chat = TestChat::create(['id' => 42]);

    ChannelMeterPeriod::create([
        'app_id' => 'app-id',
        'channel' => 'presence-chat.42',
        'model_type' => TestChat::class,
        'model_id' => '42',
        'started_at' => now()->subMinutes(10),
        'ended_at' => null,
    ]);

    expect($chat->totalChannelMeterSeconds())->toBe(0);
});
