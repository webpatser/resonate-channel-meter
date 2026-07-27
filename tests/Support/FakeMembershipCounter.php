<?php

namespace Webpatser\ResonateChannelMeter\Tests\Support;

use Webpatser\ResonateChannelMeter\Contracts\MembershipCounter;

/**
 * A test double whose per-channel member count the test drives directly.
 */
class FakeMembershipCounter implements MembershipCounter
{
    /** @var array<string, int> */
    public array $counts = [];

    public function set(string $channel, int $count): void
    {
        $this->counts[$channel] = $count;
    }

    public function count(string $channel): int
    {
        return $this->counts[$channel] ?? 0;
    }
}
