# Upgrading

## From 0.7 to 1.0

1.0 is a version alignment with `webpatser/resonate` 1.0; there are no API, config or schema changes.

```bash
composer require webpatser/resonate-channel-meter:^1.0
```

No migration is needed. The 0.7.1 behaviour stays: `ignore_channel_prefixes` defaults to `['#']`, and webhook bodies must carry a `time_ms` within `timestamp_window` seconds (default 300, `0` disables). If your sender retries for longer than the window, raise it before upgrading.
