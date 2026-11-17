<?php

namespace Webpatser\ResonateChannelMeter\Tests\Support;

use Webpatser\ResonateChannelMeter\Contracts\AppScopedMembershipCounter;

/**
 * An app-scoped test double whose per-application, per-channel member count the test drives directly.
 */
class FakeMembershipCounter implements AppScopedMembershipCounter
{
    /** @var array<string, array<string, int>> */
    public array $counts = [];

    public function set(string $channel, int $count, string $appId = 'app-id'): void
    {
        $this->counts[$appId][$channel] = $count;
    }

    public function count(string $appId, string $channel): int
    {
        return $this->counts[$appId][$channel] ?? 0;
    }
}
