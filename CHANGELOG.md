# Changelog

All notable changes to `webpatser/resonate-channel-meter` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **Duplicate open periods under concurrent or retried webhooks.** `open()` did
  an unlocked check-then-insert, so two deliveries of the same
  `channel_occupied` (a retry landing while the first delivery was still in
  flight, or two deliveries picked up by two workers) could both insert a
  period. The second one stayed open forever, and every roll-up that later
  closed it billed time the channel was never occupied. There are now two
  guards: a unique index on `(app_id, channel, open_slot)` and a locked read
  inside a transaction, with `insertOrIgnore` letting the delivery that loses
  the race drop out quietly.
- **Phantom periods from redelivered events.** Events were applied in arrival
  order, so a `channel_occupied` redelivered after its `channel_vacated`
  reopened a period nothing would ever close. Each channel now carries a
  `last_event_ms` high-water mark and events older than the newest one applied
  to that channel are ignored. Events at the mark itself still apply, since
  every event in one delivery shares the envelope's `time_ms`.
- **Negative periods from a late `channel_vacated`.** A delayed vacated could
  write an `ended_at` before `started_at`, and `totalChannelMeterSeconds()`
  silently skips such a period, so the whole session vanished from the bill.
  `ended_at` is now clamped to `started_at`: worst case a zero-length period is
  recorded, which is visible and bills nothing.
- **Sweep closing a period that was just reoccupied.** The sweep decided on the
  periods it had read up front, so a `member_added` arriving mid-sweep could
  have its reopen overwritten and the session cut short. Each period is now
  processed in its own transaction and re-read under `lockForUpdate()`, and
  `evaluate()` locks the period the same way before its read-modify-write of
  `metadata.below_since`.

### Added

- Migration `2026_07_30_000001_add_open_period_guard_to_channel_meter_periods_table`:
  adds `open_slot` and `last_event_ms`, then the unique index. `open_slot` is a
  nullable discriminator (1 while open, null once closed) rather than a partial
  index or a generated column, because every driver treats NULLs as distinct in
  a unique index, so the same schema holds on SQLite, MySQL, MariaDB,
  PostgreSQL, and SQL Server. `ChannelMeterPeriod` keeps `open_slot` in step
  with `ended_at` on save, so host code writing a period directly cannot break
  the invariant. The migration also repairs data the old code could already
  have written: per channel the earliest open period stays open and the
  duplicates are closed at their own `started_at`, so they bill nothing.

## [0.2.0] - 2026-06-09

### Added

- Fully-occupied metering mode. With `min_members >= 2`, the `EventHandler`
  re-evaluates the live member count on every membership event
  (`member_added`, `member_removed`, `channel_occupied`, `channel_vacated`)
  and opens a period only while the channel is at/above the threshold —
  for example billing a two-party reading only while both parties are present.
- `grace_seconds` config: keep an open period running for a window after the
  count drops below the threshold, closing it at `dropped-below + grace` if the
  count does not recover (absorbs brief reconnects). The drop moment is stored
  on the period's `metadata.below_since`.
- `MembershipCounter` contract plus the default `NullMembershipCounter` binding,
  so the package stays dependency-free; a host binds a real counter (e.g. one
  backed by `webpatser/resonate-roster`) to drive fully-occupied metering.
- `Support\MeterDecision` / `Support\MeterAction`: the pure open/close/grace
  decision, unit-tested in isolation.
- `EventHandler::sweep()` and the `channel-meter:sweep` console command: the
  backstop that durably closes periods whose grace elapsed with no further
  webhook (re-checks the live count first, so a returned member is kept open).

### Unchanged

- Room-occupancy mode (`min_members <= 1`, the default) behaves exactly as in
  0.1.0: `channel_occupied` opens, `channel_vacated` closes.

## [0.1.0] - 2026-05-25

Initial release.

### Added

- `WebhookController` and `VerifyPusherSignature` middleware: receives
  Pusher-format webhook deliveries from `webpatser/resonate-webhooks`,
  verifies the `X-Pusher-Signature` header against the app secret in
  `reverb.apps`, and dispatches every event.
- `EventHandler`: idempotent `channel_occupied` and `channel_vacated`
  handling, recording one period per occupancy with `started_at` and
  `ended_at` timestamps.
- `ChannelMeterPeriod` Eloquent model: one row per period, with a
  polymorphic `model` relation to the domain entity the channel maps to,
  and a `metadata` JSON column.
- `HasChannelMeter` trait: adds `channelMeterPeriods()`,
  `openChannelMeterPeriods()`, and a `totalChannelMeterSeconds(from, to)`
  roll-up to any domain model.
- `ChannelResolver` interface and `ConfigChannelResolver` default
  implementation: maps a channel like `presence-chat.{id}` to an Eloquent
  model via a configurable patterns array.
- Migration for the `channel_meter_periods` table, publishable via
  `vendor:publish --tag=resonate-channel-meter-migrations`.

[Unreleased]: https://github.com/webpatser/resonate-channel-meter/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/webpatser/resonate-channel-meter/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/webpatser/resonate-channel-meter/releases/tag/v0.1.0
