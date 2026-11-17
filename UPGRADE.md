# Upgrading

## Unreleased: the `channel_meter_marks` migration

This version adds a `channel_meter_marks` table that holds the newest applied event time per channel; the `last_event_ms` column on `channel_meter_periods` is no longer written. Run the migration before deploying the new code, or webhook handling fails on the missing table:

```bash
php artisan migrate
```

The migration seeds itself from the `last_event_ms` values of your existing periods, so the stale-event guard carries on where it left off.

## Unreleased: channels that match no pattern are no longer metered

Old default: every channel not dropped by `ignore_channel_prefixes` was metered; a channel matching no entry in `patterns` got a period with `model_type` and `model_id` null.

New default: a channel matching no pattern is not metered. Its webhook events are dropped before they reach the store, so no period and no `channel_meter_marks` row is written. This applies in both room-occupancy and fully-occupied mode, and also to hosts whose published config file predates the key, because the package default fills in a missing key.

To restore the old behaviour exactly, set the new key to `true`:

```php
// config/resonate-channel-meter.php
'meter_unmatched_channels' => true,
```

or set `CHANNEL_METER_UNMATCHED_CHANNELS=true` in the environment.

Periods that are open for unmatched channels when you deploy are still closed. In room-occupancy mode the next `channel_vacated` for that channel closes the open period at the event's `time_ms`; for a channel without an open period the event writes nothing. In fully-occupied mode the webhook path ignores these channels, and `channel-meter:sweep` closes their periods once the bound counter reports them below `min_members` and `grace_seconds` has passed, so keep the sweep scheduled. You only need to step in by hand for a period whose `channel_vacated` never arrives (for example because it was sent before the deploy), in room-occupancy mode, where no sweep runs: close or delete it yourself.

## Unreleased: the `AppScopedMembershipCounter` contract

`MembershipCounter` is unchanged and a counter written against it keeps working: the handler and the sweep still call `count(string $channel)` on it. That contract receives only the channel name, so when two applications use the same channel name they share one count.

The new `Webpatser\ResonateChannelMeter\Contracts\AppScopedMembershipCounter` is recommended instead:

```php
public function count(string $appId, string $channel): int;
```

Bind it under the existing `MembershipCounter::class` key. The handler detects it with `instanceof` and passes the application id of the webhook event; the sweep passes the `app_id` of each open period. Count only that application's members. The default `NullMembershipCounter` implements both contracts, so code that resolves it and calls `count($channel)` keeps working; it always returns 0 in either call shape. A counter implementing both contracts is called with the application id.

## From 1.0.0 to 1.0.1

No API, config or schema changes. In fully-occupied mode (`min_members >= 2`) `channel-meter:sweep` now re-evaluates every open period against the bound `MembershipCounter`, not only periods already below the threshold. A period the counter reports under `min_members` is marked `below_since` at the sweep and closed after `grace_seconds`, even when no `member_removed` arrived. Make sure your counter sees the members of every metered channel before upgrading.

## From 0.7 to 1.0

1.0 is a version alignment with `webpatser/resonate` 1.0; there are no API, config or schema changes.

```bash
composer require webpatser/resonate-channel-meter:^1.0
```

No migration is needed. The 0.7.1 behaviour stays: `ignore_channel_prefixes` defaults to `['#']`, and webhook bodies must carry a `time_ms` within `timestamp_window` seconds (default 300, `0` disables). If your sender retries for longer than the window, raise it before upgrading.
