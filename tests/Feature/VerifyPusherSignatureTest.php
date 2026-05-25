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
