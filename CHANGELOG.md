# Changelog

## 1.0.2 — 2026-11-17

- Hebrew (RTL) admin: fields that mix Hebrew, English and {{placeholders}} now display in the right direction; flow list subtitles no longer scramble.

## 1.0.1 — 2026-11-17

- Automatic updates keep the plugin active (WordPress re-activates the new version in the same request).
- Release workflow uses actions/checkout v5.

## 1.0.0 — 2026-11-17

First release.

- Visual flow builder: trigger → conditions → actions, step conditions, test data capture ("Listen"), field picker, step and flow tests.
- Triggers: incoming webhook (token / HMAC / basic auth, IP allowlist, custom or synchronous responses), Elementor Pro, Contact Form 7, WPForms, Gravity Forms, Fluent Forms, Facebook Lead Ads, WooCommerce order status, new user, content published.
- Actions: HTTP request, Google Sheets (add / update row), Excel 365, Meta Conversions API, email, Slack, Telegram.
- Logs with full request/response traces, replay and retry; automatic retries with back-off; failure emails.
- Encrypted connections, SSRF protection, rate limiting, Hebrew (RTL) admin.
- Automatic updates from GitHub releases.
