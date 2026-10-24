<?php

namespace Webpatser\ResonateChannelMeter\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Verifies that an inbound webhook request was signed by a configured
 * Resonate application.
 *
 * The header pair `X-Pusher-Key` + `X-Pusher-Signature` is the Pusher
 * convention; the signature is HMAC-SHA256 of the raw body under the
 * application's secret. A matching app is attached to the request as
 * `resonate.app` so the downstream controller does not have to repeat the
 * lookup.
 */
class VerifyPusherSignature
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $key = (string) $request->header('X-Pusher-Key');
        $signature = (string) $request->header('X-Pusher-Signature');
        $body = $request->getContent();

        $app = $this->findAppByKey($key);

        if ($app === null) {
            throw new HttpException(422, 'Unknown application key');
        }

        $secret = (string) ($app['secret'] ?? '');

        if ($secret === '') {
            // An empty secret would make the HMAC key public knowledge and any
            // signature trivially forgeable. Refuse rather than verify against ''.
            throw new HttpException(500, 'Application secret not configured');
        }

        $expected = hash_hmac('sha256', $body, $secret);

        if (! hash_equals($expected, $signature)) {
            throw new HttpException(401, 'Invalid signature');
        }

        $this->assertFresh($body);

        $request->attributes->set('resonate.app', $app);

        return $next($request);
    }

    /**
     * Reject a correctly signed body whose `time_ms` is missing or outside the
     * freshness window, so a captured delivery cannot be replayed later.
     *
     * Runs after the signature check, so the timestamp is authentic.
     */
    protected function assertFresh(string $body): void
    {
        $window = (int) config('resonate-channel-meter.timestamp_window', 300);

        if ($window <= 0) {
            return;
        }

        $decoded = json_decode($body, true);
        $timeMs = is_array($decoded) ? ($decoded['time_ms'] ?? null) : null;

        if (! is_int($timeMs) && ! is_float($timeMs)) {
            throw new HttpException(422, 'Missing timestamp');
        }

        if (abs(microtime(true) * 1000 - $timeMs) > $window * 1000) {
            throw new HttpException(422, 'Stale timestamp');
        }
    }

    /**
     * Find a configured Resonate application by its public key.
     *
     * Reads `reverb.apps.apps`, the standard config shape Resonate already
     * uses, so the consuming app does not have to duplicate its app list.
     *
     * @return array<string, mixed>|null
     */
    protected function findAppByKey(string $key): ?array
    {
        foreach ((array) config('reverb.apps.apps', []) as $app) {
            if (($app['key'] ?? null) === $key) {
                return $app;
            }
        }

        return null;
    }
}
