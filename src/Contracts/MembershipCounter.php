<?php

namespace Webpatser\ResonateChannelMeter\Contracts;

/**
 * Answers "how many distinct members are in this channel right now".
 *
 * Fully-occupied metering needs a live member count to decide when a channel
 * crosses the configured threshold. The package keeps this abstract so it does
 * not depend on any particular presence source: a host binds an implementation
 * (for example one backed by webpatser/resonate-roster's Redis roster).
 *
 * This 1.x contract receives only the channel name, so two applications that
 * use the same channel name share one count. Prefer
 * {@see AppScopedMembershipCounter}, which also receives the application id.
 */
interface MembershipCounter
{
    /**
     * The number of distinct members currently in the channel.
     */
    public function count(string $channel): int;
}
