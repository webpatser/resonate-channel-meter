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
