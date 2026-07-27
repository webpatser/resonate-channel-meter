<?php

namespace Webpatser\ResonateChannelMeter;

use Webpatser\ResonateChannelMeter\Contracts\MembershipCounter;

/**
 * The default {@see MembershipCounter}: always reports zero.
 *
 * It is the safe no-op binding so the package works out of the box in its
 * legacy room-occupancy mode (min_members <= 1, where the count is never
 * consulted). A host that turns on fully-occupied metering must bind a real
 * counter; otherwise the threshold can never be reached and no period opens.
 */
final class NullMembershipCounter implements MembershipCounter
{
    public function count(string $channel): int
    {
        return 0;
    }
}
