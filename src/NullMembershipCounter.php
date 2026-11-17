<?php

namespace Webpatser\ResonateChannelMeter;

use Webpatser\ResonateChannelMeter\Contracts\AppScopedMembershipCounter;
use Webpatser\ResonateChannelMeter\Contracts\MembershipCounter;

/**
 * The default membership counter: always reports zero.
 *
 * It is the safe no-op binding so the package works out of the box in its
 * legacy room-occupancy mode (min_members <= 1, where the count is never
 * consulted). A host that turns on fully-occupied metering must bind a real
 * counter; otherwise the threshold can never be reached and no period opens.
 *
 * It implements both contracts with one method, so host code written against
 * either call shape keeps working: `count($channel)` and
 * `count($appId, $channel)` both return 0.
 */
final class NullMembershipCounter implements AppScopedMembershipCounter, MembershipCounter
{
    public function count(string $appIdOrChannel, ?string $channel = null): int
    {
        return 0;
    }
}
