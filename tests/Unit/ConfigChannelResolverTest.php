<?php

use Webpatser\ResonateChannelMeter\Resolvers\ConfigChannelResolver;

it('resolves a channel matching a configured pattern', function () {
    $resolver = new ConfigChannelResolver([
        'presence-chat.{id}' => 'App\\Models\\Chat',
    ]);

    expect($resolver->resolve('presence-chat.42'))
        ->toBe(['type' => 'App\\Models\\Chat', 'id' => '42']);
});

it('returns null when no pattern matches', function () {
    $resolver = new ConfigChannelResolver([
        'presence-chat.{id}' => 'App\\Models\\Chat',
    ]);

    expect($resolver->resolve('updates'))->toBeNull()
        ->and($resolver->resolve('presence-call.7'))->toBeNull();
});

it('picks the first matching pattern in declaration order', function () {
    $resolver = new ConfigChannelResolver([
        'presence-chat.{id}' => 'App\\Models\\Chat',
        'presence-{id}' => 'App\\Models\\Other',
    ]);

    expect($resolver->resolve('presence-chat.42'))
        ->toBe(['type' => 'App\\Models\\Chat', 'id' => '42']);
});

it('handles ids with non-numeric characters', function () {
    $resolver = new ConfigChannelResolver([
        'presence-room.{id}' => 'App\\Models\\Room',
    ]);

    expect($resolver->resolve('presence-room.abc-123_x'))
        ->toBe(['type' => 'App\\Models\\Room', 'id' => 'abc-123_x']);
});
