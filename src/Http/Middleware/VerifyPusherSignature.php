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

        $expected = hash_hmac('sha256', $body, (string) ($app['secret'] ?? ''));

        if (! hash_equals($expected, $signature)) {
            throw new HttpException(401, 'Invalid signature');
        }

        $request->attributes->set('resonate.app', $app);

        return $next($request);
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
