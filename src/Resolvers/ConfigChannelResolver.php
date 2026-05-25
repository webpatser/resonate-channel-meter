<?php

namespace Webpatser\ResonateChannelMeter\Resolvers;

/**
 * Resolves channels via the patterns block in `resonate-channel-meter.php`.
 *
 * A pattern is a channel-name shape with a `{id}` placeholder; the resolver
 * matches the channel against each in declaration order and extracts the id.
 *
 *     'presence-chat.{id}' => App\Models\Chat::class
 *
 * matches `presence-chat.42` and returns `['type' => Chat::class, 'id' => '42']`.
 */
class ConfigChannelResolver implements ChannelResolver
{
    /**
     * Create a new resolver.
     *
     * @param  array<string, class-string>  $patterns
     */
    public function __construct(protected array $patterns)
    {
        //
    }

    /**
     * Resolve a channel to an Eloquent (class, id) pair, or null.
     */
    public function resolve(string $channel): ?array
    {
        foreach ($this->patterns as $pattern => $type) {
            $regex = '/^'.str_replace(preg_quote('{id}', '/'), '(?P<id>[^\s]+)', preg_quote($pattern, '/')).'$/';

            if (preg_match($regex, $channel, $matches)) {
                return ['type' => $type, 'id' => $matches['id']];
            }
        }

        return null;
    }
}
