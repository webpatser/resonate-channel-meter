# Upgrading

## From 1.0.0 to 1.0.1

No API, config or schema changes. In fully-occupied mode (`min_members >= 2`) `channel-meter:sweep` now re-evaluates every open period against the bound `MembershipCounter`, not only periods already below the threshold. A period the counter reports under `min_members` is marked `below_since` at the sweep and closed after `grace_seconds`, even when no `member_removed` arrived. Make sure your counter sees the members of every metered channel before upgrading.

## From 0.7 to 1.0

1.0 is a version alignment with `webpatser/resonate` 1.0; there are no API, config or schema changes.

```bash
composer require webpatser/resonate-channel-meter:^1.0
```

No migration is needed. The 0.7.1 behaviour stays: `ignore_channel_prefixes` defaults to `['#']`, and webhook bodies must carry a `time_ms` within `timestamp_window` seconds (default 300, `0` disables). If your sender retries for longer than the window, raise it before upgrading.
