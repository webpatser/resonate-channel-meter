# Changelog

All notable changes to `webpatser/resonate-channel-meter` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/webpatser/resonate-channel-meter/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/webpatser/resonate-channel-meter/releases/tag/v0.1.0
