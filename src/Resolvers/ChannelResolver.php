<?php

namespace Webpatser\ResonateChannelMeter\Resolvers;

/**
 * Maps a channel name to the domain entity it represents, if any.
 */
interface ChannelResolver
{
    /**
     * Resolve a channel to an Eloquent (class, id) pair.
     *
     * Returns null when no rule matches. Such a channel is not metered unless
     * `meter_unmatched_channels` is true, in which case its period is
     * recorded without an attached model.
     *
     * @return array{type: class-string, id: string}|null
     */
    public function resolve(string $channel): ?array;
}
