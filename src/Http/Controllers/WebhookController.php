<?php

namespace Webpatser\ResonateChannelMeter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Webpatser\ResonateChannelMeter\EventHandler;

/**
 * Receives a Resonate webhook delivery and routes every event through the
 * {@see EventHandler}.
 *
 * Signature verification is the {@see Http\Middleware\VerifyPusherSignature}
 * middleware's job; by the time this controller runs the application has
 * been resolved and attached to the request.
 */
class WebhookController
{
    /**
     * Handle the webhook.
     */
    public function __invoke(Request $request, EventHandler $handler): JsonResponse
    {
        $app = $request->attributes->get('resonate.app');

        if (! is_array($app) || ! isset($app['app_id'])) {
            throw new HttpException(500, 'Signature middleware did not run');
        }

        try {
            $payload = json_decode($request->getContent(), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpException(422, 'Malformed webhook body');
        }

        if (! is_array($payload) || ! isset($payload['events']) || ! is_array($payload['events'])) {
            throw new HttpException(422, 'Webhook body missing events array');
        }

        $timeMs = (int) ($payload['time_ms'] ?? (int) (microtime(true) * 1000));

        foreach ($payload['events'] as $event) {
            if (is_array($event)) {
                $handler->handle((string) $app['app_id'], $event, $timeMs);
            }
        }

        return new JsonResponse(['ok' => true]);
    }
}
