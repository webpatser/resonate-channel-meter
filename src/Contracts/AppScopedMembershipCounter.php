<?php

namespace Webpatser\ResonateChannelMeter\Contracts;

/**
 * Answers "how many distinct members are in this application's channel right now".
 *
 * The application-aware successor of {@see MembershipCounter}. Channel names
 * are scoped per application, so two applications can each have a channel
 * with the same name; an implementation counts only the given application's
 * members. It does not extend {@see MembershipCounter} because the two
 * `count()` signatures cannot live on one class.
 *
 * Bind it under the existing `MembershipCounter::class` key; the handler
 * detects it with `instanceof` and passes the application id.
 */
interface AppScopedMembershipCounter
{
    /**
     * The number of distinct members currently in the channel of one application.
     */
    public function count(string $appId, string $channel): int;
}
