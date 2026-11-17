# Upgrading

## Unreleased: the `channel_meter_marks` migration

This version adds a `channel_meter_marks` table that holds the newest applied event time per channel; the `last_event_ms` column on `channel_meter_periods` is no longer written. Run the migration before deploying the new code, or webhook handling fails on the missing table:

```bash
php artisan migrate
```

The migration seeds itself from the `last_event_ms` values of your existing periods, so the stale-event guard carries on where it left off.

## From 1.0.0 to 1.0.1

No API, config or schema changes. In fully-occupied mode (`min_members >= 2`) `channel-meter:sweep` now re-evaluates every open period against the bound `MembershipCounter`, not only periods already below the threshold. A period the counter reports under `min_members` is marked `below_since` at the sweep and closed after `grace_seconds`, even when no `member_removed` arrived. Make sure your counter sees the members of every metered channel before upgrading.

## From 0.7 to 1.0

1.0 is a version alignment with `webpatser/resonate` 1.0; there are no API, config or schema changes.

```bash
composer require webpatser/resonate-channel-meter:^1.0
```

No migration is needed. The 0.7.1 behaviour stays: `ignore_channel_prefixes` defaults to `['#']`, and webhook bodies must carry a `time_ms` within `timestamp_window` seconds (default 300, `0` disables). If your sender retries for longer than the window, raise it before upgrading.
