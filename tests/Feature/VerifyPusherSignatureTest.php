<?php

it('passes a request signed with the configured app secret', function () {
    $payload = signedWebhook([]);

    postSignedWebhook($this, $payload['body'], $payload['signature'])->assertOk();
});

it('rejects a request with an invalid signature', function () {
    postSignedWebhook($this, '{"events":[]}', 'not-the-right-signature')->assertStatus(401);
});

it('rejects a request with an unknown app key', function () {
    $payload = signedWebhook([]);

    postSignedWebhook($this, $payload['body'], $payload['signature'], 'unknown-app')->assertStatus(422);
});

it('refuses to verify against an application configured with an empty secret', function () {
    config()->set('reverb.apps.apps', [
        ['key' => 'no-secret-app', 'secret' => '', 'app_id' => 'no-secret-id'],
    ]);

    $body = '{"events":[]}';

    // The signature an attacker could compute once they know the secret is empty.
    $forged = hash_hmac('sha256', $body, '');

    postSignedWebhook($this, $body, $forged, 'no-secret-app')->assertStatus(500);
});

it('accepts a fresh timestamp', function () {
    $payload = signedWebhook([], timeMs: (int) (microtime(true) * 1000) - 60_000);

    postSignedWebhook($this, $payload['body'], $payload['signature'])->assertOk();
});

it('rejects a stale timestamp', function () {
    $payload = signedWebhook([], timeMs: (int) (microtime(true) * 1000) - 600_000);

    postSignedWebhook($this, $payload['body'], $payload['signature'])->assertStatus(422);
});

it('rejects a timestamp too far in the future', function () {
    $payload = signedWebhook([], timeMs: (int) (microtime(true) * 1000) + 600_000);

    postSignedWebhook($this, $payload['body'], $payload['signature'])->assertStatus(422);
});

it('rejects a signed body without time_ms', function () {
    $body = '{"events":[]}';

    postSignedWebhook($this, $body, hash_hmac('sha256', $body, 'app-secret'))->assertStatus(422);
});

it('skips the timestamp check when the window is 0', function () {
    config()->set('resonate-channel-meter.timestamp_window', 0);

    $body = '{"events":[]}';

    postSignedWebhook($this, $body, hash_hmac('sha256', $body, 'app-secret'))->assertOk();
});
