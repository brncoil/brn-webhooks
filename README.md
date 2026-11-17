# BRN Webhooks

Self-hosted automations for WordPress — BRN's replacement for Zapier and Make.
Each site gets a visual flow builder: **trigger → conditions → actions**, with logs, retries and
encrypted credentials. Updates are delivered from this repository's GitHub releases.

## What it does

| Triggers | Actions |
| --- | --- |
| Incoming webhook (unique URL, token / HMAC / basic auth, IP allowlist, custom response) | HTTP request / webhook (JSON, form, raw; API-key connections) |
| Elementor Pro forms | Google Sheets — add or update row (service account) |
| Contact Form 7 | Excel 365 — add row to a table or worksheet (Microsoft Graph) |
| WPForms, Gravity Forms, Fluent Forms | Meta Conversions API — Lead / Purchase / custom (auto-hashed) |
| Facebook / Instagram Lead Ads (Meta webhook + Graph fetch) | Email (wp_mail, works with SMTP plugins) |
| WooCommerce order status | Slack, Telegram |
| New WordPress user, content published | |

- **Conditions** on the flow and per step (AND/OR groups, 20+ operators).
- **Templates**: `{{email}}`, `{{name|upper}}`, `{{phone|phone:972}}`, `{{date|date:d/m/Y}}`,
  previous step outputs `{{_steps.1.body.id}}`, `{{_all_table}}`, and more.
- **Field picker** fed by real captured data ("Listen for data") and form definitions.
- **Logs**: every run with input, filter decisions, each step, raw requests/responses (secrets redacted).
- **Reliability**: background runs, automatic retries with back-off (successful steps are not repeated),
  failure emails, cron sweep for anything left behind.
- **Security**: secrets encrypted with libsodium, never sent back to the browser; SSRF protection on
  outgoing calls; rate limit and payload limit on incoming calls; admin API requires `manage_options`.
- Hebrew (RTL) and English admin.

## Installing on a site

1. Download `brn-webhooks.zip` from the latest [release](https://github.com/brncoil/brn-webhooks/releases)
   and upload it in **Plugins → Add New → Upload Plugin**.
2. The repository is **private**, so each site needs a GitHub token to receive updates. Either:
   - add to `wp-config.php`: `define( 'BRNWH_GITHUB_TOKEN', 'github_pat_…' );`, or
   - paste it in **Webhooks → Settings → Updates**.

   Use a fine-grained token with **read-only "Contents"** access to this repository only.
   (If the repository is ever made public, no token is needed.)
3. Recommended: `define( 'BRNWH_ENCRYPTION_KEY', '<random 64 chars>' );` in `wp-config.php`, so stored
   credentials survive a change of the WordPress salts.

## Releasing a new version

1. Change the code, then raise the version in **both** places in `brn-webhooks.php`:
   the `Version:` header and `BRNWH_VERSION`.
2. Add a `## x.y.z — date` section at the top of `CHANGELOG.md` (it becomes the release notes).
3. Commit, push, tag:

   ```
   git tag v1.0.1
   git push origin main v1.0.1
   ```

4. The **Release** workflow checks the tag matches the version, lints PHP, builds `brn-webhooks.zip`
   (with `git archive`, honouring `.gitattributes`) and publishes the GitHub release.
5. Sites see the update within 12 hours (or immediately via **Settings → Check now**) and install it
   automatically when "Automatic updates" is on (default).

Local build: `bin/build.sh` → `dist/brn-webhooks.zip`.

## Layout

| Path | What |
| --- | --- |
| `brn-webhooks.php` | Bootstrap, version, autoloader |
| `includes/class-engine.php` | Dispatch, background runs, retries, failure emails |
| `includes/class-incoming.php` | Public endpoint `/wp-json/brn-webhooks/v1/hook/{key}` |
| `includes/class-rest.php` | Admin REST API used by the app |
| `includes/class-template.php`, `class-conditions.php` | `{{placeholder\|filter}}` rendering, condition evaluation |
| `includes/class-flows.php`, `class-connections.php`, `class-logs.php` | Storage (custom tables) |
| `includes/class-crypto.php` | Encryption of secrets |
| `includes/class-http.php` | Outgoing HTTP with SSRF guard and redacted traces |
| `includes/class-updater.php` | Updates from GitHub releases |
| `includes/triggers/`, `includes/actions/`, `includes/connections/` | Integrations |
| `assets/js/` | Admin single-page app (no build step): `lib.js` core, `fields.js` form engine, `views/*` |
| `languages/brn-webhooks-he_IL.l10n.php` | Hebrew translation (PHP and JS) — extend when adding strings |

## Adding an integration

Create a class extending `BRN\Webhooks\Trigger`, `Action` or `Connection_Type`, describe its fields
with the schema documented in `includes/class-integration.php`, and register it in
`Registry::boot()` (or from another plugin via the `brnwh_register` action). The admin UI renders
the form automatically; dynamic dropdowns use `get_options()`.

## Hooks

- `brnwh_register` — register custom triggers / actions / connection types.
- `brnwh_run_finished( $run_id, $status, $flow )` — after every run.
- `brnwh_capability` — capability for the admin (default `manage_options`).
- `brnwh_trusted_ip_headers`, `brnwh_sensitive_key_pattern`, `brnwh_run_after_response`.
