<?php

use Illuminate\Testing\TestResponse;
use Webpatser\ResonateChannelMeter\Tests\TestCase;

uses(TestCase::class)->in(__DIR__.'/Feature');

/**
 * Build a Pusher-format webhook body and its signature.
 *
 * @param  list<array<string, mixed>>  $events
 * @return array{body: string, signature: string, time_ms: int}
 */
function signedWebhook(array $events, string $secret = 'app-secret', ?int $timeMs = null): array
{
    $timeMs ??= (int) (microtime(true) * 1000);

    $body = json_encode(['time_ms' => $timeMs, 'events' => $events], JSON_THROW_ON_ERROR);

    return [
        'body' => $body,
        'signature' => hash_hmac('sha256', $body, $secret),
        'time_ms' => $timeMs,
    ];
}

/**
 * Post a raw signed webhook body to the test endpoint.
 *
 * Headers are passed via the server array because `withHeaders()` is not
 * applied to {@see TestCase::call()} consistently across testbench versions;
 * `HTTP_*` server entries always translate to request headers.
 */
function postSignedWebhook(TestCase $test, string $body, string $signature, string $key = 'app-key'): TestResponse
{
    return $test->call('POST', '/test-webhook', server: [
        'HTTP_X_PUSHER_KEY' => $key,
        'HTTP_X_PUSHER_SIGNATURE' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], content: $body);
}
