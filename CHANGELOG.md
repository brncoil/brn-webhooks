# Changelog

## 1.1.0 — 2026-11-18

Visual canvas builder with decision logic — the Zapier / Make experience, inside WordPress.

- New flow editor: a pannable / zoomable canvas. Add steps with +, drag from a port to connect, drop on empty space to add a step there, insert a step on any connection, auto-arrange, undo / redo, keyboard shortcuts. The selected step is edited in a side panel.
- Logic steps: If / else (Yes / No branches), Paths (named branches with their own rules, "first match" or "every match", plus an Otherwise path), Filter (anywhere in the flow), Delay (for a time or until a date — the run waits and resumes without repeating finished steps), Loop (run steps for each item of a list, then "When done").
- Formatter step: set values, lookup table, safe math, dates (add / format) and text splitting.
- Error paths: drag from an action's red dot to add steps that run only when it fails.
- Test runs light up on the canvas: every step gets ✓ / ✗ and the branches taken are highlighted. Logs show nested branches and which path was chosen and why.
- New template filters: add, subtract, multiply, divide, round, abs.
- Existing flows keep working unchanged and open on the canvas automatically; step references now use stable step ids.
- No configuration needed: the GitHub repository is public, so updates need no token; the encryption key in wp-config.php is optional.

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
