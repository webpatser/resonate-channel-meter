<?php

use Webpatser\ResonateChannelMeter\ChannelMeterPeriod;

it('records an occupancy period from a signed webhook delivery', function () {
    $payload = signedWebhook([
        ['name' => 'channel_occupied', 'channel' => 'presence-chat.42'],
    ]);

    postSignedWebhook($this, $payload['body'], $payload['signature'])->assertOk();

    expect(ChannelMeterPeriod::count())->toBe(1)
        ->and(ChannelMeterPeriod::first()->channel)->toBe('presence-chat.42');
});

it('opens then closes a period across two deliveries', function () {
    $open = signedWebhook(
        [['name' => 'channel_occupied', 'channel' => 'presence-chat.42']],
        timeMs: 1_700_000_000_000,
    );

    $close = signedWebhook(
        [['name' => 'channel_vacated', 'channel' => 'presence-chat.42']],
        timeMs: 1_700_000_060_000,
    );

    postSignedWebhook($this, $open['body'], $open['signature'])->assertOk();
    postSignedWebhook($this, $close['body'], $close['signature'])->assertOk();

    $period = ChannelMeterPeriod::first();

    expect($period->ended_at)->not->toBeNull()
        ->and($period->ended_at->getTimestampMs() - $period->started_at->getTimestampMs())->toBe(60_000);
});

it('processes several events in one delivery', function () {
    $payload = signedWebhook([
        ['name' => 'channel_occupied', 'channel' => 'presence-chat.1'],
        ['name' => 'channel_occupied', 'channel' => 'presence-chat.2'],
        ['name' => 'channel_vacated', 'channel' => 'presence-chat.1'],
    ]);

    postSignedWebhook($this, $payload['body'], $payload['signature'])->assertOk();

    expect(ChannelMeterPeriod::count())->toBe(2)
        ->and(ChannelMeterPeriod::where('channel', 'presence-chat.1')->first()->ended_at)->not->toBeNull()
        ->and(ChannelMeterPeriod::where('channel', 'presence-chat.2')->first()->ended_at)->toBeNull();
});

it('rejects a malformed JSON body with 422', function () {
    $body = 'not json';

    postSignedWebhook($this, $body, hash_hmac('sha256', $body, 'app-secret'))->assertStatus(422);
});
