# Changelog

All notable changes to `webpatser/resonate-channel-meter` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
