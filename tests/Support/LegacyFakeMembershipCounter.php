<?php

namespace Webpatser\ResonateChannelMeter\Tests\Support;

use Webpatser\ResonateChannelMeter\Contracts\MembershipCounter;

/**
 * A test double written against the 1.x contract: it only receives the channel name.
 */
class LegacyFakeMembershipCounter implements MembershipCounter
{
    /** @var array<string, int> */
    public array $counts = [];

    /** @var list<array<int, mixed>> */
    public array $calls = [];

    public function set(string $channel, int $count): void
    {
        $this->counts[$channel] = $count;
    }

    public function count(string $channel): int
    {
        $this->calls[] = func_get_args();

        return $this->counts[$channel] ?? 0;
    }
}
