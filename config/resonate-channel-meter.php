<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Channel to model patterns
    |--------------------------------------------------------------------------
    |
    | Each pattern maps a channel name shape to an Eloquent model. The
    | resolver matches a channel against every pattern in order and extracts
    | the placeholders, so for example
    |
    |     'presence-chat.{id}' => App\Models\Chat::class
    |
    | turns the channel `presence-chat.42` into `(App\Models\Chat, '42')`,
    | which is stored on the recorded period so a model can pull its periods
    | back out with the `HasChannelMeter` trait.
    |
    | A channel that matches no pattern is still recorded; it just has no
    | model attached.
    |
    | Supported placeholders: `{id}` (a single capture group).
    |
    */

    'patterns' => [
        // 'presence-chat.{id}' => App\Models\Chat::class,
        // 'presence-call.{id}' => App\Models\Call::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Minimum members ("fully occupied" metering)
    |--------------------------------------------------------------------------
    |
    | The default mode (min_members <= 1) records a period for plain room
    | occupancy: `channel_occupied` opens it, `channel_vacated` closes it.
    |
    | Set min_members to 2 (or more) to meter only while the channel is
    | "fully occupied": for example, billing a two-party reading only while
    | both the customer and the consultant are present. In that mode the
    | handler re-evaluates the live member count (via the bound
    | MembershipCounter) on every membership event and opens a period when the
    | count reaches the threshold, closing it when it drops below.
    |
    | Member counting needs a MembershipCounter binding. The package ships a
    | NullMembershipCounter (always 0); a host that wants fully-occupied
    | metering binds its own, e.g. one backed by webpatser/resonate-roster.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Ignored channel prefixes
    |--------------------------------------------------------------------------
    |
    | Events for channels whose name starts with one of these are dropped
    | before they reach the store, so no period is ever opened for them.
    |
    | The Pusher protocol reserves "#" for channels the server owns rather than
    | the application. webpatser/resonate-users puts a signed-in connection on
    | "#server-to-user-{id}" so a message can be addressed to a person; that is
    | a user's session, not a room. A channel matching no pattern is still
    | recorded, so without this every sign-in would open a billable period.
    |
    | Set it to an empty array to meter everything.
    |
    */

    'ignore_channel_prefixes' => ['#'],

    'min_members' => (int) env('CHANNEL_METER_MIN_MEMBERS', 1),

    /*
    |--------------------------------------------------------------------------
    | Grace seconds
    |--------------------------------------------------------------------------
    |
    | When the member count drops below `min_members`, keep the open period
    | running for this many seconds before closing it. If the count climbs
    | back to the threshold within the window the period continues unbroken;
    | otherwise it is closed at "dropped-below + grace_seconds". Use this to
    | absorb brief reconnects, or to give a party a short window to come back
    | before the meter pauses.
    |
    */

    'grace_seconds' => (int) env('CHANNEL_METER_GRACE_SECONDS', 0),

];
