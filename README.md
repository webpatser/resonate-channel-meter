# Resonate Channel Meter

A Laravel package that records billable and observable **channel occupancy periods** from [`webpatser/resonate-webhooks`](https://github.com/webpatser/resonate-webhooks) events. It turns "this room was occupied for 12 minutes" from a polling problem into a Pusher webhook your backend just reacts to.

It is a webhook consumer, not a Resonate plugin. It runs in your Laravel app.

## Where it fits

```
Resonate process                Laravel app
────────────────                ───────────
RedisRosterPlugin       webhook
WebhookPlugin     ─────────────►  WebhookController + VerifyPusherSignature
                                      │
                                      ▼
                                  EventHandler
                                      │
                                      ▼
                                  channel_meter_periods table
                                      │
                                      ▼
                                  $chat->totalChannelMeterSeconds(...)
```

The roster makes occupancy cluster-correct. The webhooks plugin pushes the edges out as signed HTTP POSTs. This package receives them and stores them as durable periods you can query and bill on.

## How it works

### One period per occupancy

A `channel_occupied` event opens a `channel_meter_periods` row with `started_at = time_ms` and `ended_at = null`. The matching `channel_vacated` closes it. Both are idempotent: a redelivered webhook never duplicates a period or leaves an orphan close.

### Channel to model resolution

The `channel_meter_periods` table carries a polymorphic `model_type` / `model_id`. A small resolver maps a channel name to the domain entity it represents, using a `{id}` placeholder:

```php
// config/resonate-channel-meter.php
'patterns' => [
    'presence-chat.{id}' => \App\Models\Chat::class,
    'presence-call.{id}' => \App\Models\Call::class,
],
```

`presence-chat.42` resolves to `(Chat::class, '42')`, and the period is stored with that link.

Only channels that match a pattern are metered. Events for any other channel are dropped before they reach the store, so no period and no `channel_meter_marks` row is written for them. Any client can occupy a public channel of any name, so metering every channel would let an unauthenticated client create rows without bound. To meter every channel anyway (the behaviour before `meter_unmatched_channels` existed), set:

```php
// config/resonate-channel-meter.php
'meter_unmatched_channels' => true, // or CHANNEL_METER_UNMATCHED_CHANNELS=true
```

An unmatched channel is then recorded with the channel name and no model attached. `ignore_channel_prefixes` still applies either way.

A fresh install with no patterns configured meters nothing. A pattern that matches public channels (for example `'news.{id}'`) still lets an unauthenticated client create one period per channel name it occupies, so prefer patterns on `private-` and `presence-` channels, which need authorisation to join.

Swap the default `ConfigChannelResolver` for your own by binding `ChannelResolver::class` in a service provider if you need richer rules (tenants, composite keys, lookups).

### Models pull their own periods

Apply the `HasChannelMeter` trait to any model the resolver maps to:

```php
use Webpatser\ResonateChannelMeter\Concerns\HasChannelMeter;

class Chat extends Model
{
    use HasChannelMeter;
}
```

You get:

```php
$chat->channelMeterPeriods;            // every period
$chat->openChannelMeterPeriods;        // periods currently in progress
$chat->totalChannelMeterSeconds();     // sum of closed durations
$chat->totalChannelMeterSeconds($from, $to); // clamped to a window
```

## Installation

```bash
composer require webpatser/resonate-channel-meter
```

The package migration is auto-loaded. Run it:

```bash
php artisan migrate
```

Publish the config to register channel patterns:

```bash
php artisan vendor:publish --tag=resonate-channel-meter-config
```

## Registering the webhook endpoint

The package does not auto-register a route, so you mount the controller wherever you want, behind the signature middleware:

```php
use Webpatser\ResonateChannelMeter\Http\Controllers\WebhookController;
use Webpatser\ResonateChannelMeter\Http\Middleware\VerifyPusherSignature;

Route::post('/webhooks/resonate', WebhookController::class)
    ->middleware(VerifyPusherSignature::class);
```

Then point the webhooks plugin at that URL:

```php
// config/resonate-webhooks.php
'endpoints' => [
    [
        'url' => env('APP_URL').'/webhooks/resonate',
        'app_id' => '*',
        'events' => ['channel_occupied', 'channel_vacated'],
    ],
],
```

The middleware verifies the `X-Pusher-Signature` against the app secret in `reverb.apps`, so an unsigned or forged request is rejected with `401`. An unknown `X-Pusher-Key` is rejected with `422`.

## Fully-occupied metering (min members + grace)

By default a period spans plain room occupancy: `channel_occupied` opens it,
`channel_vacated` closes it. Set `min_members` to `2` (or more) to meter only
while the channel is **fully occupied**, for example billing a two-party
reading only while both the customer and the consultant are present.

In this mode every membership event re-evaluates the live member count, so the
package needs a way to read it. Bind an `AppScopedMembershipCounter` under the
`MembershipCounter::class` key; its `count()` receives the application id and
the channel name, and must count only that application's members, since two
applications can use the same channel name. The shipped
`NullMembershipCounter` always returns 0, so a host that uses
`webpatser/resonate-roster` typically binds a small adapter over its roster:

```php
use Webpatser\ResonateChannelMeter\Contracts\AppScopedMembershipCounter;
use Webpatser\ResonateChannelMeter\Contracts\MembershipCounter;
use Webpatser\ResonateRoster\RoomRoster;

$this->app->singleton(MembershipCounter::class, fn () => new class implements AppScopedMembershipCounter {
    public function count(string $appId, string $channel): int
    {
        return (new RoomRoster(config('resonate-roster')))->userCount($channel, $appId);
    }
});
```

A counter written against the 1.x `MembershipCounter` contract, whose
`count(string $channel)` gets only the channel name, still works under the same
key. It cannot tell applications apart, so two applications that use the same
channel name share one count.

`grace_seconds` keeps the period open for a window after the count drops below
the threshold, closing it at `dropped-below + grace` only if the count does not
recover (it absorbs brief reconnects). Because a channel can simply go quiet
after dropping below, or the `member_removed` webhook can be lost altogether,
schedule the backstop sweep. It re-evaluates every open period against the live
count: one under the threshold without a marker is marked below as of the sweep,
and one past its grace deadline is closed at that deadline.

```php
// routes/console.php
Schedule::command('channel-meter:sweep')->everyTenSeconds();
```

For a live in-progress meter, read the open period plus the `metadata.below_since`
marker yourself and cap billable time at `below_since + grace`; the sweep then
persists the same close durably.

## Configuration reference

| Key | Default | Purpose |
|-----|---------|---------|
| `patterns` | `[]` | Channel-to-model patterns. Each maps a channel-name shape with a `{id}` placeholder to an Eloquent model class. |
| `meter_unmatched_channels` | `false` | Whether a channel matching no pattern is metered. `false` drops its events; `true` records a period with no model attached. Env: `CHANNEL_METER_UNMATCHED_CHANNELS`. |
| `min_members` | `1` | Members required to keep a period open. `<= 1` is room occupancy; `>= 2` is fully-occupied metering driven by the counter bound under `MembershipCounter::class`. |
| `ignore_channel_prefixes` | `['#']` | Channel prefixes never metered. The protocol reserves `#` for channels the server owns, such as the `#server-to-user-{id}` channels `webpatser/resonate-users` creates. Applied before patterns and `meter_unmatched_channels`, so a channel with one of these prefixes is never metered. |
| `grace_seconds` | `0` | How long an open period survives below the threshold before closing at `dropped-below + grace`. |

## Notes and caveats

- **Room mode processes only occupancy events.** With `min_members <= 1` the handler records `channel_occupied` / `channel_vacated`; member and client events are ignored. Fully-occupied mode additionally consumes `member_added` / `member_removed` to re-evaluate the count.
- **Idempotent.** Receiving the same webhook twice never duplicates a period.
- **One open period per channel, enforced by the database.** A unique index on `(app_id, channel, open_slot)` allows any number of closed periods but only one open one, so concurrent or retried deliveries cannot leave a second period open. `open_slot` is bookkeeping (1 while open, null once closed); the model keeps it in step with `ended_at`.
- **Events older than the newest one applied are dropped.** Each channel's high-water mark lives in the `channel_meter_marks` table, so a redelivered `channel_occupied` arriving after its `channel_vacated` is ignored rather than opening a phantom period. `ended_at` is also clamped so it can never precede `started_at`.
- **`time_ms` comes from the server.** The `started_at` and `ended_at` timestamps are taken from the webhook envelope's `time_ms`, not the receiver's local clock, so a queued retry still records the original moment.
- **Open periods are excluded from totals.** `totalChannelMeterSeconds()` ignores any period whose `ended_at` is still null. Close stale periods explicitly if you need to bill an in-progress session.

## Requirements

- PHP 8.6+
- Laravel 13
- `webpatser/resonate-webhooks` running against a Resonate server

## Testing

```bash
composer test
```

## License

MIT. See [LICENSE](LICENSE).
