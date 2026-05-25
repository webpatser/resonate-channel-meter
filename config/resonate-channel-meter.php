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

];
