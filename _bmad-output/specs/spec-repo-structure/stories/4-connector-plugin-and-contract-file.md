---
title: 'Connector plugin and contract file'
type: 'feature'
created: '2026-08-24'
status: 'done'
review_loop_iteration: 0
context: []
baseline_commit: '6c0db77952ff56f8810cbd9b133415b4a0e8f238'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `packages/connector` is an empty `.gitkeep`. Nothing on a client site can talk to the portal, and no contract file exists for either side to build against (CAP-3, connector half).

**Approach:** Write `packages/connector/openapi.yaml` as the two-direction contract first (AD-4), then build the `woptimize-connector` plugin skeleton: a settings screen that stores the portal-issued key (AD-16), the `woptimize/v1` REST endpoints `ping` and `status`, and a daily phone-home that follows the AD-7 no-op discipline. The portal half is story 5.

## Boundaries & Constraints

**Always:**

- `packages/connector/` contents **are** the plugin folder (AD-17): main file `woptimize-connector.php`, slug `woptimize-connector`, headers `Requires PHP: 8.1`, `Requires at least: 6.7`, `Update URI: https://portal.woptimize.io`, `Version` equal to `WOPTIMIZE_CONNECTOR_VERSION` (`0.1.0`).
- Client-site floor: PHP 8.1 / WP 6.7. No PHP 8.2+ syntax. Tests and lint run at PHP 8.1 inside the connector's own DDEV project (`type: php`, no database).
- `openapi.yaml`: `openapi: 3.1.0`, written 3.0.3-portable (no `nullable`, no type arrays, no `webhooks`) so story 6 can flip one line. Covers connector-hosted `GET /ping`, `GET /status` (server = the reported `rest_base`) and portal-hosted `POST /phone-home` under `/api/connector/v1`. One `SiteReport` schema is both the `status` response and the phone-home body. Errors: WP core envelope on the connector, Laravel default on the portal. `info.version` = connector `MAJOR.MINOR`.
- Auth (AD-5): every `woptimize/v1` route has a `permission_callback` comparing `X-Woptimize-Site-Key` to the stored key with `hash_equals()`; missing header, empty stored key, or mismatch → `WP_Error` `woptimize_unauthorized`, status 401. Every `woptimize/v1` response carries `X-Woptimize-Connector-Version` (added in `rest_post_dispatch`). The version appears in a body only as `SiteReport.connector_version`.
- Key (AD-16): the plugin never generates or changes a key. Settings → WOptimize (`manage_options`, Settings API) stores the pasted 40-char alphanumeric key in option `woptimize_connector_site_key`. Invalid input keeps the old value and adds a settings error; empty clears it.
- Portal base: constant `WOPTIMIZE_PORTAL_URL`, default `https://portal.woptimize.io`, overridable from `wp-config.php` (the playground sets it in story 6).
- Phone-home: WP-Cron `daily` on hook `woptimize_connector_phone_home`, scheduled at activation, cleared at deactivation. `POST {portal}/api/connector/v1/phone-home`, key header, `User-Agent: WOptimize-Connector/<version>`, JSON `SiteReport`, 10 s timeout, `sslverify` untouched. Also runs at once on key save, and as a single event after a self-update of this plugin.
- AD-7: no key → no request. Any `4xx` → record it and stay silent until the next daily slot. `5xx` or `WP_Error` → one retry as a single event on `woptimize_connector_phone_home_retry` 15 min later; the retry never reschedules. A `Throwable` in the cron path is caught and recorded. No admin notice outside the settings page, no `wp_die`, no fatal. The last result (time, status) shows on the settings page only.
- `uninstall.php` deletes both options and clears both hooks.
- Tooling lives in `packages/connector`: `composer.json` with dev-only dependencies and a committed `composer.lock`; PHPUnit 10.5 + Brain Monkey 2.7 unit tests; PHPCS with WPCS 3.4 + PHPCompatibilityWP (`testVersion 8.1-`); `.distignore` keeps tooling and `openapi.yaml` out of the future zip.

**Never:**

- No `update_plugins_*` filter, no update-check or download paths in the YAML, no playground, no CI, no portal code (stories 5, 6, 9).
- No license logic, no key generation, no schedule tightening, no admin notice on remote failure, no multisite network mode.
- No Composer runtime dependencies, no `vendor/` in git, no local PHP — everything through `ddev`.
- No PSR-12: WordPress Coding Standards.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Ping | `GET /woptimize/v1/ping`, valid key | 200 `{"ok":true}` + version header | N/A |
| Bad auth | any route; wrong or missing header, or no stored key | 401 `{"code":"woptimize_unauthorized","message":…,"data":{"status":401}}` + version header | silent, nothing logged |
| Status | `GET /woptimize/v1/status`, valid key | 200 `SiteReport`: `connector_version`, `site_url`, `home_url`, `rest_base` (`rest_url('woptimize/v1')`), `site_name`, `wp_version`, `php_version`, `locale`, `timezone`, `multisite`, `theme{slug,name,version}`, `updates{wordpress,plugins,themes}` counted from the `update_core`/`update_plugins`/`update_themes` site transients | N/A |
| No key | cron slot, option empty | no HTTP request; state `no_key` | N/A |
| Portal OK | cron slot, portal 200 | state `ok` + timestamp; no retry | N/A |
| Portal 4xx | cron slot, portal 401 or 404 | state records the status; no retry event | permanent-quiet |
| Portal down | cron slot, 503 or `WP_Error` | one `…_phone_home_retry` event at +15 min; the retry records its result and never reschedules | quiet |
| Key save | 40 alphanumeric chars | stored; immediate phone-home; page shows the result | N/A |
| Bad key | 39 chars, or symbols | old value kept | settings error names the format |
| Key cleared | empty field | option empty; cron stays scheduled and no-ops | N/A |
| Deactivate / uninstall | — | hooks cleared / options and hooks removed | N/A |

</frozen-after-approval>

## Code Map

- `packages/connector/.gitkeep` -- delete; the plugin fills the folder.
- `apps/www/plugins/woptimize-core/woptimize-core.php:1-19` -- house style for the plugin header and `defined( 'ABSPATH' ) || exit;`. It has `Update URI: false`; the connector points at the portal host.
- `apps/www/.ddev/config.yaml`, `apps/portal/.ddev/config.yaml` -- `ddev_version_constraint: ">= 1.25"` with its comment; reuse. The connector project: `name: woptimize-connector`, `type: php`, `php_version: "8.1"`, `omit_containers: [db]`, no `docroot`. Host DDEV is 1.25.3; `8.1` is an allowed value.
- `.gitattributes` -- LF rule covers only `apps/*/.ddev/commands/**`; the connector adds no host commands, so no change.
- `README.md:6-24` repo map, `AGENTS.md` "Where things are" and "Running and verifying" -- add the connector lines.
- Smoke venue: `apps/www/wordpress/wp-content/plugins/` is gitignored; a copy of the plugin there runs on the live www DDEV site (PHP 8.4 — the floor guard is the connector container, not this).
- Verified 2026-08-24 (core source + Packagist): `update_plugins_{$host}` uses `wp_parse_url( UpdateURI, PHP_URL_HOST )`. `rest_post_dispatch( $result, $server, $request )` runs after `error_to_response()`, so errors get the header too. `get_header()` lower-cases and turns `-` into `_`. `wp_remote_retrieve_response_code()` returns `''` for a `WP_Error`. `wp_get_update_data()` is capability-gated (zero counts in REST) — count `update_plugins->response`, `update_themes->response`, and `update_core->updates[*]->response === 'upgrade'` instead. `wp_unschedule_hook` since 4.9. `hash_equals()` needs two strings — cast the header. Versions: `phpunit/phpunit ^10.5` (last major on 8.1), `brain/monkey ^2.7` (Mockery-based, PHPUnit-agnostic; `Functions\stubTranslationFunctions()` / `stubEscapeFunctions()`), `wp-coding-standards/wpcs ^3.4`, `phpcompatibility/phpcompatibility-wp ^2.1` (PHPCompatibility 9.3.5 — partial 8.2+ coverage; the 8.1 container run is the real guard), `squizlabs/php_codesniffer ^3.13`, `dealerdirect/phpcodesniffer-composer-installer ^1.2`, `symfony/yaml ^6.4` (last 8.1 line). Brain Monkey ships no `WP_*` classes: minimal stubs for `WP_Error`, `WP_REST_Request`, `WP_REST_Response`, `WP_REST_Controller`, `WP_REST_Server` go in `tests/stubs/`.

## Tasks & Acceptance

**Execution:**

- [x] `packages/connector/openapi.yaml` -- create first -- the contract before any code (AD-4): `SiteKey` apiKey scheme, `X-Woptimize-Connector-Version` header, schemas `Ping`, `SiteReport`, `PhoneHomeAck` (`{"ok":true}`), `WpRestError`, `LaravelError`; per-path `servers` for the two directions.
- [x] `packages/connector/.ddev/config.yaml` -- create -- PHP 8.1, no DB, version constraint.
- [x] `packages/connector/composer.json`, `composer.lock`, `.gitignore`, `.distignore`, `phpcs.xml.dist`, `phpunit.xml.dist` -- create -- dev deps above; scripts `test`, `lint`, `lint:fix`; ruleset `WordPress` + `PHPCompatibilityWP`, prefixes `woptimize_connector` / `WOPTIMIZE_CONNECTOR` / `WOptimize\Connector`, text domain `woptimize-connector`, `minimum_wp_version` 6.7; ignore `vendor/`.
- [x] `packages/connector/woptimize-connector.php` -- create -- header, constants (`WOPTIMIZE_CONNECTOR_VERSION`, `_FILE`, `WOPTIMIZE_PORTAL_URL` behind `defined()`), `require_once` list, activation/deactivation hooks, `Plugin::boot()`.
- [x] `packages/connector/includes/class-site-key.php` -- create -- `get()`, `is_valid_format()`, `verify( ?string )` via `hash_equals()`.
- [x] `packages/connector/includes/class-site-report.php` -- create -- builds the `SiteReport` array; the one writer for both consumers.
- [x] `packages/connector/includes/class-rest-controller.php` -- create -- extends `WP_REST_Controller`; `register_routes()`, `ping()`, `status()`, `check_key()`; `rest_post_dispatch` adds the version header on `/woptimize/v1` routes.
- [x] `packages/connector/includes/class-phone-home.php` -- create -- `schedule()`, `unschedule()`, `run( bool $is_retry )`, `send()`; state option `woptimize_connector_phone_home` (`last_attempt_at`, `last_result`, `last_http_status`); hooks on both cron events, `add_option_`/`update_option_woptimize_connector_site_key`, `upgrader_process_complete`.
- [x] `packages/connector/includes/class-settings.php` -- create -- `register_setting` + sanitize callback, options page, password-type field, last-result line.
- [x] `packages/connector/uninstall.php` -- create -- delete both options, clear both hooks.
- [x] `packages/connector/tests/bootstrap.php`, `tests/stubs/`, `tests/*Test.php` -- create -- Brain Monkey; every matrix row; `ContractTest`: YAML paths equal registered routes, header names equal the PHP constants, `info.version` equals plugin `MAJOR.MINOR`, plugin header values (`Requires PHP`, `Requires at least`, `Update URI` host).
- [x] `packages/connector/README.md`, `README.md`, `AGENTS.md` -- create/edit -- quickstart (`ddev start`, `ddev composer install`, `ddev composer test`, `ddev composer lint`), contract rules, the floor, what `.distignore` drops.

**Acceptance Criteria:**

- Given `packages/connector` after `ddev start` and `ddev composer install`, when `ddev composer test` and `ddev composer lint` run, then both exit 0 and `ddev exec php -v` reports 8.1.
- Given the plugin copied into the www smoke venue, activated, with a key saved, when `curl -H 'X-Woptimize-Site-Key: <key>' https://woptimize.ddev.site/wp-json/woptimize/v1/status` runs, then 200 with every `SiteReport` field and the version header; the same call without the header returns 401 in the WP envelope.
- Given that smoke site with `WOPTIMIZE_PORTAL_URL` pointing at an unreachable host, when `ddev wp cron event run woptimize_connector_phone_home` runs, then it exits 0, the site still serves 200, and `ddev wp cron event list` shows one `woptimize_connector_phone_home_retry`; after running it, no further retry exists.
- Given the repo after setup, when `git status --porcelain` runs, then no `vendor/` or DDEV runtime file appears.

## Spec Change Log

## Review Triage Log

Review 1 (iteration 0). Layers: blind-hunter, edge-case-hunter, verification-gap, security-audit (no findings), external-blind, external-edge, external-intent (all three Codex gpt-5.6-sol). No intent_gap or bad_spec.

**Patched (low/medium):**

- A pending `…_phone_home_retry` survived a later `ok`/`4xx`/`no_key` run, so a 4xx could still be followed by a retry (contradicts the matrix). Now cleared on non-retry runs.
- `record()` inside the `catch` could throw again and escape the cron path. Now guarded.
- `additionalProperties: false` on the wire schemas rejects the additive minor changes AD-8 requires. Removed.
- `/phone-home` documented no `5XX`, the branch that drives the retry. Added with `LaravelError`.
- `Ping.ok` / `PhoneHomeAck.ok` accepted `false`. Now `enum: [true]` (3.0-portable).
- `Site_Key::verify()` accepted any non-empty stored string; a malformed option written outside the settings screen authenticated. Now requires the portal format on the stored side too.
- Re-saving an unchanged key fired no `update_option_*` hook, so "runs at once on key save" failed for that case. The sanitize callback now triggers the report when the valid key equals the stored one.
- 3.0.3-portability test blacklist extended with more 3.1-only keywords.
- Tests: `BootTest` now asserts callbacks and priorities, the suite asserts it runs on PHP 8.1, `ContractTest` resolves every `$ref` and checks the HTTP method per path, a 3xx test documents the permanent-quiet choice, the two `assertTrue( true )` tests use `expectNotToPerformAssertions()` and the strict PHPUnit flag is restored.
- README: the smoke walkthrough now overrides `WOPTIMIZE_PORTAL_URL` before saving a key (it posted to production), and "Both setup commands" wording clarified.

**Deferred (not this story's code):** `stories.yaml` gained `spec_checkpoint`/`done_checkpoint` on unrelated stories during the run — origin unknown, left untouched, flagged to the human. AGENTS.md "Both DDEV apps" wording predates the third project (managed block, owned by `bmad-project-context`).

**Dismissed:**

- Self-update one-shot on the daily hook blocks `schedule()` / is dropped as a duplicate: deactivation clears the one-shot before any activation, and WP's 10-minute duplicate window only drops it when the daily fires within 10 minutes anyway — no consequence.
- No self-heal when the daily event is lost; ignored `wp_schedule_event()` return: the frozen spec fixes scheduling to activation/deactivation. Fix would edit the spec.
- Key save runs a blocking 10 s request inside the admin POST: frozen spec says "runs at once on key save".
- 3xx/1xx/status 0 land in permanent-quiet: unspecified statuses default to AD-7 silence; a portal redirect delays a report by one day, tolerable and portal-owned. Now covered by a test that documents it.
- `wp_json_encode()` returning `false`: core sanitises invalid UTF-8 and falls back to partial output; not reachable for this shape.
- Root `servers` block "hazardous" for codegen: required by Redocly's `no-empty-servers`; every path overrides it and `ContractTest` enforces that.
- DDEV `type: php` serves the package root: local-only project with no secrets.
- `WOPTIMIZE_PORTAL_URL` unprefixed / unvalidated / not HTTPS-enforced: name fixed by the frozen spec; a bad `wp-config.php` override is admin-owned and degrades to `transport_error`; forcing HTTPS could break story 6's playground.
- Key echoed in the password field: frozen spec asks for a password-type field; requires an actor who already controls wp-admin.
- Inbound HTTPS not required, multisite, `Retry-After` on 429, `SiteReport` filter, "Send now" button, `load_plugin_textdomain`: out of the frozen scope or feature requests (WP ≥ 4.6 just-in-time loads translations).
- Smoke `rsync` differs from `.distignore`: intentional, no consequence.
- Story Verification ends with `wp plugin delete` while README says `uninstall`: fix edits the spec; flagged to the human.
- Portal `204` / `ok:false` recorded as `ok`; in-flight old-key overwrite; ignored `wp_schedule_single_event()` false: tolerable or unreachable in PHP's synchronous model.
- Plugin bootstrap file and `uninstall.php` never executed by the suite: by design note — real-WP behaviour is story 6's suite; the smoke AC covers activation today.
- Header-case test exercises the stub: harmless.
- `composer.lock` absent from the diff: excluded from the review diff for size only; present on disk and committed with the story.
- `plugin_headers()` regex fragility: a parse miss raises an undefined-index warning and `failOnWarning` fails the suite loudly.

## Design Notes

- Own DDEV project for the package: the spine puts connector integration tests on the playground (story 6) and forbids local PHP; unit tests and lint at the 8.1 floor need a PHP 8.1 runtime today. `type: php`, no DB, no docroot is ten lines, and `.distignore` keeps it out of the zip (story 9).
- Brain Monkey instead of a real-WP harness: no DB, seconds to run, and the AD-7 branches are pure decision logic. Real-WP behaviour is story 6's suite.
- Update-check and download paths enter the YAML in story 9 — additive inside `v1` (AD-5).
- `ping` carries no version in the body: AD-5 forbids ad-hoc body fields; the header is the channel.
- Portal base is a constant, not a setting: one less field a human can mistype; the only non-production value is the playground's.

## Verification

**Commands:**

- `cd packages/connector && ddev start && ddev composer install && ddev exec php -v` -- expected: PHP 8.1.x.
- `ddev composer test` -- expected: green. `ddev composer lint` -- expected: 0 errors.
- `npx --yes @redocly/cli@latest lint packages/connector/openapi.yaml` (host Node, verification only) -- expected: 0 errors; a copy with `openapi: 3.0.3` also lints clean.
- Smoke on www: copy the plugin into `apps/www/wordpress/wp-content/plugins/woptimize-connector`, `ddev wp plugin activate woptimize-connector`, `ddev wp option update woptimize_connector_site_key <40 chars>`, run the two `curl` calls and the cron commands from the ACs, then `ddev wp plugin delete woptimize-connector`.

## Suggested Review Order

**The contract (AD-4) — read this first**

- Two hosts, three paths; per-path `servers` say who serves what.
  [`openapi.yaml:71`](../../../../packages/connector/openapi.yaml#L71)

- Portal-hosted phone-home: the same `SiteReport` as `/status`, plus the `5XX` the retry branch handles.
  [`openapi.yaml:138`](../../../../packages/connector/openapi.yaml#L138)

- Schemas stay open on purpose so a newer connector minor passes an older portal (AD-8).
  [`openapi.yaml:237`](../../../../packages/connector/openapi.yaml#L237)

**Auth (AD-5, AD-16)**

- Stored key must be portal-format too; `hash_equals()` with the stored value first.
  [`class-site-key.php:81`](../../../../packages/connector/includes/class-site-key.php#L81)

- One `permission_callback` for every route; missing, empty, or wrong is one silent 401.
  [`class-rest-controller.php:124`](../../../../packages/connector/includes/class-rest-controller.php#L124)

- Version header added in `rest_post_dispatch`, so core-built 401s carry it too.
  [`class-rest-controller.php:149`](../../../../packages/connector/includes/class-rest-controller.php#L149)

**AD-7 no-op discipline**

- Every branch of the phone-home: no key, 2xx, 4xx quiet, 5xx/transport one retry, Throwable caught twice.
  [`class-phone-home.php:135`](../../../../packages/connector/includes/class-phone-home.php#L135)

- The retry never reschedules; a settled run clears a stale retry.
  [`class-phone-home.php:245`](../../../../packages/connector/includes/class-phone-home.php#L245)

- Outbound request: key header, User-Agent, 10 s, no redirects, `sslverify` untouched.
  [`class-phone-home.php:194`](../../../../packages/connector/includes/class-phone-home.php#L194)

- Self-update queues one report on the daily hook.
  [`class-phone-home.php:304`](../../../../packages/connector/includes/class-phone-home.php#L304)

**Site report — one writer, two consumers**

- Update counts come from the site transients; `wp_get_update_data()` is capability-gated.
  [`class-site-report.php:26`](../../../../packages/connector/includes/class-site-report.php#L26)

**Settings screen**

- Invalid key keeps the old value; empty clears; unchanged re-save still reports at once.
  [`class-settings.php:117`](../../../../packages/connector/includes/class-settings.php#L117)

- The only place a remote failure is ever shown.
  [`class-settings.php:226`](../../../../packages/connector/includes/class-settings.php#L226)

**Plugin shell**

- Header floor, `WOPTIMIZE_PORTAL_URL` override point, activation/deactivation wiring.
  [`woptimize-connector.php:38`](../../../../packages/connector/woptimize-connector.php#L38)

- Runs without the plugin loaded, so names are literals; `ContractTest` keeps them in step.
  [`uninstall.php:14`](../../../../packages/connector/uninstall.php#L14)

**Tests and tooling**

- Contract guard: every `$ref` resolves; paths, methods, headers, and shape match the code.
  [`ContractTest.php:335`](../../../../packages/connector/tests/ContractTest.php#L335)

- 3.0.3-portability blacklist so story 6 can flip one line.
  [`ContractTest.php:281`](../../../../packages/connector/tests/ContractTest.php#L281)

- The AD-7 matrix rows as tests, including the stale-retry case.
  [`PhoneHomeTest.php:236`](../../../../packages/connector/tests/PhoneHomeTest.php#L236)

- Hooks asserted with callback and priority, not just by name.
  [`BootTest.php:38`](../../../../packages/connector/tests/BootTest.php#L38)

- The suite refuses to run anywhere but the PHP 8.1 floor.
  [`FloorTest.php:29`](../../../../packages/connector/tests/FloorTest.php#L29)

- Tooling-only DDEV project: PHP 8.1, no DB, no docroot.
  [`config.yaml:6`](../../../../packages/connector/.ddev/config.yaml#L6)

- What the future release zip drops, `openapi.yaml` included.
  [`.distignore:17`](../../../../packages/connector/.distignore#L17)
