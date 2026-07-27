<?php

namespace Webpatser\ResonateChannelMeter\Contracts;

/**
 * Answers "how many distinct members are in this channel right now".
 *
 * Fully-occupied metering needs a live member count to decide when a channel
 * crosses the configured threshold. The package keeps this abstract so it does
 * not depend on any particular presence source: a host binds an implementation
 * (for example one backed by webpatser/resonate-roster's Redis roster).
 */
interface MembershipCounter
{
    /**
     * The number of distinct members currently in the channel.
     */
    public function count(string $channel): int;
}
