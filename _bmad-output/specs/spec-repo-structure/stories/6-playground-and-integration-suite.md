---
title: 'Playground and integration suite'
type: 'feature'
created: '2026-08-25'
status: 'done'
baseline_commit: '09f0715ee07d6962927ffe3a8a0c76666d1f3720'
review_loop_iteration: 0
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The connector (story 4) and the portal (story 5) are each tested alone, with mocks. Nothing runs the real plugin on a real WordPress at the client floor against the real portal, so contract drift or an AD-7 regression would reach a client site unseen. `apps/playground` and `.github/workflows/` are `.gitkeep` placeholders (AD-11, AD-18).

**Approach:** Make `apps/playground` a DDEV WordPress project at PHP 8.1 / WP 6.7 whose whole state comes from `ddev playground-setup`. Put a PHPUnit suite inside it that validates the connector's and the portal's real responses against `openapi.yaml` with `league/openapi-psr7-validator` and drives the AD-7 scenarios through WP-CLI. Wrap it in one host command, `ddev contract-suite`, that starts both projects, plants the fixture site on the portal, runs the current leg and the previous-minor leg, and is the single step of the reusable workflow `.github/workflows/contract-suite.yml`. Flip the contract to `openapi: 3.0.3`: no maintained OpenAPI 3.1 response validator exists, and the spine's Deferred list pre-authorizes the flip.

## Boundaries & Constraints

**Always:**

- Playground (AD-18): own DDEV project `playground` → `https://playground.ddev.site`; `type: wordpress`, `docroot: wordpress`, `php_version: "8.1"`, MariaDB 11.8, `ddev_version_constraint: ">= 1.25"`. WordPress pinned to `6.7` — the connector floor — as `WP_VERSION` at the top of `playground-setup` and nowhere else. `wordpress/`, `vendor/`, `.previous-connector/` gitignored. No theme, no tokens, no host Node.
- `packages/connector` reaches the container as a read-only directory bind mount at `/mnt/woptimize/connector` (`.ddev/docker-compose.connector.yaml`, outside the Mutagen tree). `playground-setup` symlinks `wordpress/wp-content/plugins/woptimize-connector -> /mnt/woptimize/connector` and `wordpress/wp-content/mu-plugins/woptimize-playground.php -> ../../../mu-plugins/woptimize-playground.php`, waits for both to land in the container (www-setup's flush + five-try loop), installs WP, activates the connector, sets pretty permalinks (`/%postname%/`), and writes the fixture key from `WOPTIMIZE_TEST_SITE_KEY` only when the stored value differs. `WOPTIMIZE_TEST_SITE_KEY=PLAYGROUNDFIXTUREKEY00000000000000000000` is set in one place, `.ddev/config.yaml` `web_environment`; both host commands read it with `ddev exec printenv WOPTIMIZE_TEST_SITE_KEY`, and the tests read the container env directly. Idempotent: a second run exits 0 with no download and no install.
- The committed mu-plugin `apps/playground/mu-plugins/woptimize-playground.php` does exactly three things: defines `WOPTIMIZE_PORTAL_URL` from the env var of the same name (default `https://portal.woptimize.ddev.site` — the only non-production value, connector README), defines `DISABLE_WP_CRON` true so only WP-CLI fires a phone-home, and filters `http_request_args` to set `sslcertificates` to `/mnt/ddev-global-cache/mkcert/rootCA.pem` when that file exists (closes story-5 deferred item D3).
- Suite: PHPUnit ^10.5 in `apps/playground/tests/`, run inside the playground container (PHP 8.1) with Guzzle ^7, `symfony/process` ^6.4, and `league/openapi-psr7-validator` ^0.24. Contract path from `WOPTIMIZE_CONTRACT_FILE` (default `/mnt/woptimize/connector/openapi.yaml`); the suite **fails** when it is unreadable. Every response assertion goes through the validator's `OperationAddress` for the documented path/method/status; a hand-written shape check is never the only check. Cron-driven tests reset the option `woptimize_connector_phone_home` and the `woptimize_connector_phone_home_retry` event in `setUp`, and restore the fixture key in `tearDown`; they never leave a changed key behind.
- One command: `ddev contract-suite [all|current|previous]` (host command in `apps/playground`, default `all`). It runs `ddev start -y` in `apps/portal`, resets the fixture through `ddev exec -p portal.woptimize php artisan …` (`site:offboard https://playground.ddev.site` ignored on failure, then `site:onboard https://playground.ddev.site --key="$WOPTIMIZE_TEST_SITE_KEY"`), and per leg: `ddev exec vendor/bin/phpunit --exclude-group offboarded`, `site:ping https://playground.ddev.site` and `site:status …` on the portal, `site:offboard …`, `ddev exec vendor/bin/phpunit --group offboarded`, then the fixture reset again plus one `ddev wp cron event run woptimize_connector_phone_home`, so the portal shows the playground fresh after the suite. Env vars for the container (`WOPTIMIZE_CONTRACT_FILE`) travel inside the `ddev exec '…'` command string — `ddev exec` forwards no host env. One line per leg — `current: PASS`, `previous: PASS (plugin-v0.0.3)`, `previous: SKIPPED — no plugin-v* tag below 0.1` — and a non-zero exit when any leg fails. Same command, same output, in CI.
- Leg "previous" (AD-11, Karin's rule): `<tag>` = the highest `plugin-v*` tag (`git tag --list 'plugin-v*' --sort=-v:refname`) with the same MAJOR as, and a lower MINOR than, `WOPTIMIZE_CONNECTOR_VERSION` at HEAD. `git archive <tag> packages/connector` is extracted into `apps/playground/.previous-connector/`, the plugin symlink is repointed to `../../../.previous-connector` for the run and restored afterwards — also on failure — and the suite runs with `WOPTIMIZE_CONTRACT_FILE=/var/www/html/.previous-connector/openapi.yaml`. No such tag → print the SKIPPED line, add a `::notice::` and a `$GITHUB_STEP_SUMMARY` line when those variables exist, exit 0.
- Portal: `Site::issueKey(?string $key = null)`; `site:onboard {site_url} {--key=}`. `--key` exits 1 with no row when `$this->laravel->isProduction()` or when the value fails `SiteKey::isValidFormat()`; otherwise the row carries `sha256(key)` and the key is announced once, as today. Pest covers all three.
- Contract flip (AD-4, spine Deferred): `openapi: 3.0.3` in `packages/connector/openapi.yaml`, header comment updated to state the decision; `test_openapi_version` asserts `'3.0.3'` and the portability test's docblock says why the file must stay 3.0.3-valid. Nothing else in the file changes; both `ContractTest`s stay green.
- Workflow `.github/workflows/contract-suite.yml`: `on: workflow_call`, `workflow_dispatch`, and `pull_request` with paths `apps/playground/**`, `apps/portal/**`, `packages/connector/**`, `.github/workflows/contract-suite.yml`. One job `suite` on `ubuntu-latest`: `actions/checkout@v7` with `fetch-depth: 0`; `actions/setup-node@v7` with `node-version: 24`; `ddev/github-action-setup-ddev@v1` with `version: v1.25.3` and `autostart: false`; `ddev start -y` + `ddev portal-setup` in `apps/portal`; `ddev start -y` + `ddev playground-setup` in `apps/playground`; `ddev contract-suite all`. No secrets, no deploy. `actionlint` exits 0.
- Docs: `apps/playground/README.md` in the shape of `apps/www/README.md`; root README says four DDEV projects and names the suite command; `apps/portal/README.md` documents `--key`; `packages/connector/README.md` documents 3.0.3 and replaces "story 6's job" with the playground quickstart. Three entries appended to `deferred-work.md` (first real CI run; spine Stack row now 3.0.3; plain-permalink `rest_base` → 422).

**Never:**

- No playground theme, tokens, content sync, or deploy; no change under `apps/www` or `packages/design-tokens`.
- No connector PHP change; only the `openapi:` line, its header comment, and its test move. No new contract paths (story 9), no `plugin-v*` tag, no `connector.yml` or `portal.yml` (stories 8–9 add the `needs:`).
- No throttle on any connector route; no key in logs or output beyond `site:onboard`'s one-time line; `--key` never works in production.
- No hand edit of `AGENTS.md` (managed block — `bmad-project-context` refreshes it), of the spine, or of `SPEC.md`. No push to GitHub in this story — static check only, by human decision.
- No absolute host paths in symlinks, no hand-created playground state, no `sslverify => false`, no `--no-verify` anywhere.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Ping | `GET {playground}/wp-json/woptimize/v1/ping`, fixture key | 200; validator passes `/ping` `get` 200; header `X-Woptimize-Connector-Version` present | no key or wrong key → 401; validator passes `/ping` 401 |
| Status | `GET …/status`, fixture key | 200; validator passes `/status` 200; `php_version` starts `8.1.`; `rest_base` = `{playground}/wp-json/woptimize/v1`; `connector_version` = the version header | wrong key → 401; validator passes |
| Phone-home OK | `POST {portal}/api/connector/v1/phone-home`, fixture key, body = the live `/status` JSON | 200 `{"ok":true}`; validator passes `/phone-home` 200 | N/A |
| Phone-home refused | same body, unknown 40-char key | 401; validator passes `/phone-home` 401 | N/A |
| Phone-home invalid | fixture key, body with `multisite: "yes"` | 422; validator passes `/phone-home` 422 | N/A |
| Cron happy | `wp cron event run woptimize_connector_phone_home` | option `last_result=ok`, `last_http_status=200`; no `…_retry` event; daily event still listed | N/A |
| Bad key | `wp option update woptimize_connector_site_key <unknown 40-char>` (fires a phone-home) | `client_error` / 401; no retry event; daily event still listed | fixture key restored in `tearDown` |
| Unreachable | `WOPTIMIZE_PORTAL_URL=https://unreachable.invalid wp cron event run woptimize_connector_phone_home` | `transport_error` / 0; exactly one `…_retry` event | then `… wp cron event run woptimize_connector_phone_home_retry` → `transport_error` again and zero retry events |
| Offboarded (`@group offboarded`) | orchestrator ran `site:offboard` after a 200; `wp cron event run woptimize_connector_phone_home` | `client_error` / 401; no retry event | N/A |
| Contract unreadable | `Contract::load('/nowhere.yaml')` | throws `RuntimeException` whose message contains `/nowhere.yaml` | never skips; the configured file loads and lists `/ping`, `/status`, `/phone-home` |
| `--key` accepted | `site:onboard https://x.example --key=<40 alnum>`, `APP_ENV=testing` | exit 0; row with `site_key_hash = sha256(key)`; that key authenticates a phone-home | N/A |
| `--key` refused | production env, or key `abc` | exit 1, one line, no row | N/A |

</frozen-after-approval>

## Code Map

**Connector (read-only except the two version lines)**

- `packages/connector/openapi.yaml:1-18` -- header comment already announces the flip; line 18 is `openapi: 3.1.0`. Paths `/ping`, `/status` (`{rest_base}` server), `/phone-home` (`{portal}/api/connector/v1`); responses 200/401 and 200/401/422/5XX. No 3.1-only construct anywhere (verified keyword by keyword), so the one-line flip yields a valid 3.0.3 document.
- `packages/connector/tests/ContractTest.php:38` -- `assertSame('3.1.0', …)` → `'3.0.3'`; `:276-277` docblock of `test_the_file_is_portable_to_openapi_303` rewords "so story 6 can flip" to "the validator speaks 3.0 only". The sweep itself (`:281-325`) stays.
- `packages/connector/woptimize-connector.php:26,38-41` -- `WOPTIMIZE_CONNECTOR_VERSION = '0.1.0'`; `WOPTIMIZE_PORTAL_URL` is a guarded plain constant — an mu-plugin `define()` wins because mu-plugins load first. The orchestrator parses the version from line 26 to pick the previous minor.
- `packages/connector/includes/class-phone-home.php:35,42,49,56,63,70` -- `HOOK woptimize_connector_phone_home`, `RETRY_HOOK …_retry`, `OPTION woptimize_connector_phone_home` (`last_attempt_at`, `last_result`, `last_http_status`), `PATH /api/connector/v1/phone-home`, `TIMEOUT 10`, `RETRY_DELAY 900`. Branches at `:135-184`: 2xx → `ok` + `clear_retry`; 4xx → `client_error` + `clear_retry`, nothing scheduled; 5xx/`WP_Error` → `maybe_retry` (`:245-255`, one single event, never from the retry itself). Triggers at `:77-87`: the two cron hooks and `add_option_`/`update_option_woptimize_connector_site_key` — so `wp option update` fires a synchronous phone-home inside the CLI process.
- `packages/connector/includes/class-phone-home.php:189-209` -- `wp_remote_post()` with no `sslverify`; core's `http_request_args` filter is the CA override channel.
- `packages/connector/includes/class-rest-controller.php:31,66-91,124-136,149-163` -- namespace `woptimize/v1`, GET `/ping` and `/status`, 401 as `WP_Error('woptimize_unauthorized', …, ['status' => 401])`, version header added post-dispatch to every response.
- `packages/connector/includes/class-site-report.php:26-51` -- the `SiteReport`; `rest_base = rest_url('woptimize/v1')` (`:33`) — plain permalinks would make it `?rest_route=…`, which the portal rejects (422); hence pretty permalinks in setup.
- `packages/connector/includes/class-site-key.php:26,40,67,88` -- option `woptimize_connector_site_key`, regex `^[A-Za-z0-9]{40}$`.
- `packages/connector/.ddev/config.yaml` -- `type: php`, `php_version: "8.1"`, `omit_containers: [db]`: the floor project the playground's PHP pin must match.

**Portal**

- `apps/portal/app/Models/Site.php:79-89` -- `issueKey(): string` → `issueKey(?string $key = null)`, body `$key ??= SiteKey::generate();`. `#[Fillable(['site_url'])]` at `:24` stays.
- `apps/portal/app/Console/Commands/SiteOnboard.php:22,34-57` -- `$signature = 'site:onboard {site_url}'`; URL check at `:68-72`; duplicate → `UniqueConstraintViolationException` → exit 1. Add `{--key= : Use this key instead of a generated one (never in production)}`; guard before `issueKey()`. Production check: `$this->laravel->isProduction()` (no such idiom exists yet in `app/`; `config/app.php:29` defaults `APP_ENV` to `production`, so an unset env is refused too).
- `apps/portal/app/Connector/SiteKey.php:17,28,34` -- `generate()`, `hash()`, `isValidFormat()`. `Contract.php:20-48` -- header names, `KEY_PATTERN`, `PATH_PREFIX`.
- `apps/portal/app/Console/Commands/Concerns/AnnouncesSiteKey.php:19-23` -- prints the key once; reuse unchanged.
- `apps/portal/tests/Feature/Connector/SiteCommandsTest.php:21` -- `runCommand(string, array): array{int,string}`; add the three `--key` cases here. `tests/Feature/Connector/SiteKeyGuardTest.php:28` -- `phoneHomeWithKey()` proves the supplied key authenticates.
- `apps/portal/app/Connector/Rules/PublicHost.php:38`, `config/connector.php:22`, `.env.example:67-68` -- `WOPTIMIZE_ALLOW_PRIVATE_REST_BASE=true` in DDEV; the playground's `rest_base` resolves to the router (private) — CI inherits `.env.example`, so no change.
- `apps/portal/app/Http/Requests/Connector/PhoneHomeRequest.php:36-63` -- the rules the live `/status` body must pass (`rest_base` without query/fragment, `multisite` strict boolean).
- `apps/portal/app/Console/Commands/Concerns/ResolvesSite.php:22-35` -- `{site}` accepts a `site_url`, so the orchestrator never needs an id.
- `apps/portal/.ddev/docker-compose.contract.yaml` -- the read-only bind-mount pattern (`../../../packages/connector/openapi.yaml:/mnt/woptimize/openapi.yaml:ro`) the playground copies as a directory mount.
- `apps/portal/.ddev/commands/host/portal-setup:41-56` -- host-npm + Node 24 guards, then `npm run tokens:build`: CI needs `actions/setup-node` before `ddev portal-setup`.

**www (the pattern to mirror)**

- `apps/www/.ddev/commands/host/www-setup:1-7,9,12,14-17,21-39,62-73,75-82,84-101,103-116,118-124` -- header annotations (`## Description/Usage/Example/ProjectTypes: wordpress/HostWorkingDir: false`), `set -euo pipefail`, `WP_VERSION` pin, `DDEV_APPROOT`/`DDEV_PRIMARY_URL`, `installed_wp_version()`, `wait_for_container_path()` (five tries, `ddev mutagen sync` between), three-way core guard (download / skip / `core update --force`), wp-config assertion with the `ddev restart` message, `ln -sfn` + flush + container assertion, `wp core is-installed` guard, activation, `Done.` line. Copy the structure; drop step 0 (tokens) and both Node guards.
- `apps/www/.gitignore:1` -- `/wordpress/`. `apps/www/.ddev/config.yaml` -- the ten lines to mirror with `name: playground`, `php_version: "8.1"`.
- `.gitattributes:7` -- `apps/*/.ddev/commands/** text eol=lf` already covers the playground.
- `apps/www/README.md:1-30,46-69,71,115` -- headings and the "Two commands from a fresh clone … Nothing is set up by hand." wording; "The command is idempotent…" sentence.
- `README.md:13,32-37` -- the `playground/` map line; "Three DDEV projects live in this repo" → four, plus the suite bullet.

**Facts verified by experiment (2026-08-25, DDEV v1.25.3, OrbStack)**

- From the www web container, `curl https://portal.woptimize.ddev.site/up` → 200: inter-project HTTPS by hostname needs no config; the system store trusts mkcert, so Guzzle in the container works as-is.
- `wp_remote_get()` to the same URL → `cURL error 60`; with `sslcertificates => /mnt/ddev-global-cache/mkcert/rootCA.pem` → 200. `CAROOT=/mnt/ddev-global-cache/mkcert` is set in the container.
- `ddev exec -p portal.woptimize php artisan --version` works from another project's directory; `ddev start <name>` accepts project names.
- No `plugin-v*` tag and no git remote exist; `actionlint` and `gh` are installed on the host.

**Library facts (web research, 2026-08-25)**

- `league/openapi-psr7-validator` v0.24 (2026-05-08), PHP ≥ 7.2, parser `devizzent/cebe-php-openapi` ^1.0 (v1.1.5, 2026-01-23). Validates PSR-7 responses; Guzzle's `Psr7\Response` needs no adapter. 3.1 keywords are known-broken (issues #148, #202, PR #221 unmerged) and the README scopes it to 3.0.x — hence the flip. No maintained PHP or Node alternative validates a response against an operation with 3.1 support.
- `ddev/github-action-setup-ddev` v1.12.1: inputs `ddevDir`, `autostart`, `version`; Docker is preinstalled on `ubuntu-latest`. `actions/checkout` v7.0.1, `actions/setup-node` v7.0.0. WordPress 6.7 lists PHP 8.1 as compatible; 6.7.6 is the latest 6.7 patch.

## Tasks & Acceptance

**Execution:**

- [x] `packages/connector/openapi.yaml`, `packages/connector/tests/ContractTest.php` -- edit -- `openapi: 3.0.3`, header comment states the decision, `test_openapi_version` and the portability docblock follow; `ddev composer test` green in `packages/connector`.
- [x] `apps/portal/app/Models/Site.php`, `apps/portal/app/Console/Commands/SiteOnboard.php` -- edit -- `issueKey(?string $key = null)`; `--key=` with the production and format guards.
- [x] `apps/portal/tests/Feature/Connector/SiteCommandsTest.php` -- edit -- the three `--key` matrix rows.
- [x] `apps/playground/.ddev/config.yaml`, `apps/playground/.ddev/docker-compose.connector.yaml`, `apps/playground/.gitignore` -- create -- project config with `web_environment` (`WOPTIMIZE_TEST_SITE_KEY`, `WOPTIMIZE_PORTAL_URL=https://portal.woptimize.ddev.site`); the `:ro` directory mount; the three ignore lines. Delete `apps/playground/.gitkeep`.
- [x] `apps/playground/mu-plugins/woptimize-playground.php` -- create -- the three definitions/filters above, WordPress Coding Standards, PHP 8.1 syntax.
- [x] `apps/playground/.ddev/commands/host/playground-setup` -- create -- the www-setup structure minus tokens; `WP_VERSION=6.7`; both symlinks; permalinks; fixture key write-if-different; `Done.` line with the URL and the key variable name (never the key).
- [x] `apps/playground/composer.json`, `phpunit.xml.dist`, `tests/bootstrap.php`, `tests/Support/Contract.php` (`load(?string $path = null)` throwing `RuntimeException` with the path, validator builder, `assertResponseMatches(OperationAddress, ResponseInterface)`), `tests/Support/WpCli.php` (`run(array $args, array $env = []): string`, `optionJson(string)`, `cronEvents(): array`), `tests/Support/Playground.php` (URLs, key, Guzzle client with `http_errors => false`) -- create -- platform `php: 8.1.0`; `require-dev` only.
- [x] `apps/playground/tests/ConnectorEndpointsTest.php`, `tests/PortalPhoneHomeTest.php`, `tests/NoOpTest.php`, `tests/OffboardedTest.php` (`@group offboarded`), `tests/ContractFileTest.php` -- create -- one test per matrix row.
- [x] `apps/playground/.ddev/commands/host/contract-suite` -- create -- `## ProjectTypes: wordpress`, `## HostWorkingDir: false`; the fixture reset, the legs, the previous-tag rule, the restore trap, the result lines, the CI notice lines.
- [x] `.github/workflows/contract-suite.yml` -- create -- as specified; delete `.github/workflows/.gitkeep`.
- [x] `apps/playground/README.md`, `README.md`, `apps/portal/README.md`, `packages/connector/README.md` -- create/edit -- as specified.
- [x] `_bmad-output/implementation-artifacts/deferred-work.md` -- append -- the three entries.

**Acceptance Criteria:**

- Given a fresh `apps/playground` after `ddev start`, when `ddev playground-setup` runs twice, then both runs exit 0, the second prints the "already" lines, `ddev exec php -v` reports 8.1, `ddev wp core version` prints `6.7`, `ddev wp plugin list --status=active --field=name` includes `woptimize-connector`, `ddev wp option get permalink_structure` prints `/%postname%/`, and `ddev wp option get woptimize_connector_site_key` equals `WOPTIMIZE_TEST_SITE_KEY`.
- Given both projects running, when `ddev contract-suite all` runs in `apps/playground`, then it exits 0, prints `current: PASS` and `previous: SKIPPED — no plugin-v* tag below 0.1`, and `ddev exec -p portal.woptimize php artisan site:list` shows `https://playground.ddev.site` with `connector_version 0.1.0` and a fresh `last_seen_at`.
- Given a temporary local tag `plugin-v0.0.1` on `HEAD~1` (created and deleted inside the verification, never committed), when `ddev contract-suite previous` runs, then it prints `previous: PASS (plugin-v0.0.1)` and afterwards `readlink apps/playground/wordpress/wp-content/plugins/woptimize-connector` is `/mnt/woptimize/connector` again.
- Given the repo after the build, when `actionlint` runs at the root, then it exits 0; when `git status --porcelain` runs, then no `wordpress/`, `vendor/`, `.env`, `.previous-connector/`, or DDEV runtime file appears.

## Spec Change Log

## Review Triage Log

Eight layers ran (blind, edge-case, verification-gap, security, structure, and three external Codex runs: blind, edge, intent). Every finding was verified at its site before triage. Loop iteration: 0 — no `intent_gap` or `bad_spec` survived.

**Patched (one entry per root cause; severity of the verified consequence)**

- medium — `contract-suite` never verifies the plugin symlink state: no startup restore, no container-side `readlink` check after repoint/restore, stale `.previous-connector` satisfies the existence wait, flag cleared before restore is verified. A previous leg could test HEAD twice and print `PASS`. (verification-gap, blind, edge, external blind, external edge)
- medium — mu-plugin forces `sslcertificates` for every outbound request, so public hosts (wordpress.org during `wp core update --force`) fail verification. Scoped to `*.ddev.site`. (blind, external blind)
- low — `printenv` under `set -euo pipefail` in both host commands kills the script before the "run ddev restart" diagnostic. (blind, external blind)
- low — `INT`/`TERM` trap restores the link but never exits; Ctrl-C continues into the next leg. (blind, edge, external blind, external edge)
- low — empty `connector_version()` yields a malformed `SKIPPED` line and exit 0. (edge, external blind)
- low — `freshen_fixture` skipped on a failed leg; closing cron run not checked for `last_result=ok`. (edge, external intent, external edge)
- low — `WP_VERSION` accepted an environment override against the "nowhere else" pin. (external blind, external edge)
- low — real directory at the plugin path nests the symlink and passes the container check. (edge, external blind, external edge)
- low — `WpCli::cronEvents()` turned bad JSON into `[]`, letting "no retry event" pass on garbage. (external blind)
- low — `liveReport()` asserted a `/status` 200 without the validator. (external intent)
- low — `ContractFileTest` asserted path order; the matrix says "lists". (blind, external edge)
- low — `Site::issueKey()` stored any plaintext; defensive format guard added, command guard unchanged. (blind, external blind)
- low — workflow had no `permissions:` and no `timeout-minutes`. (security, blind, edge)
- low — suite silently depends on `WOPTIMIZE_ALLOW_PRIVATE_REST_BASE=true`; documented in the playground README. (blind)
- low — deferred-work evidence claimed both `ContractTest`s assert `3.0.3`; only the connector's does. (blind)
- low — error messages pointed at the bare `ddev exec vendor/bin/phpunit`, which includes `@group offboarded`. (verification-gap)
- low — structure: `UNKNOWN_KEY` ×3, duplicated setUp/tearDown and hook filter, public `validator($path)` nobody passes, `rtrim('/')` on the key, five duplicated GET calls, `unset($url)`, `CURRENT_MINOR` naming, `.previous-connector` spelled three ways, portal test const claiming cross-app equality, unpinned `executionOrder`. (structure, blind, edge, verification-gap)

**Deferred (not this story's problem, or agent-context documents)**

- Pin the three workflow actions to commit SHAs — needs a network check the story forbids doing before the first real CI run. (security)
- `AGENTS.md` managed block is stale (says "written 3.1", "third DDEV project", "story 6"); a `WP_VERSION` pin now also lives in `playground-setup`. The spec's manual check already says to run `bmad-project-context`. (blind, edge, external blind)
- `AGENTS.md` carries an unrelated "Doc timeline" section with a broken link that appeared in the working tree during this session, not from this story — left untouched, reported to the human. (structure, blind, edge, external intent, external blind)

**Dismissed**

- Missing 5xx (`server_error`) and no-key scenarios; retry delay and daily cadence unasserted; request-side validation; distinguishing the two `--key` refusals — the fix adds or changes matrix rows, i.e. edits the frozen spec.
- `--key` allowed outside the literal `production`; `name: playground`; `node-version: 24`; workflow path list without `packages/design-tokens`; mu-plugin default URL duplicating `config.yaml`; `::notice::` for every leg; `site:ping`/`site:status` exit-code only; no `push:` trigger — each is what the spec specifies.
- `actions/checkout@v7` / `setup-node@v7` "likely do not exist" — refuted by the spec's researched Library facts (v7.0.1 / v7.0.0).
- Previous-leg code "never executed / dead" — refuted: the `plugin-v0.0.1` acceptance run exercised it and passed, symlink restored.
- Previous tag's contract could be 3.1 — no tag exists yet and the file is now 3.0.3, so every future tag carries 3.0.3.
- `WpCli::run()` puts the fixture key in a failure message — the fixture key is committed plaintext in `config.yaml`, not a secret; no consequence.
- Missing mkcert CA makes the suite red as `transport_error` — red, not green; tolerable diagnostics.
- Bare `--key` with no value behaves as absent — Symfony cannot tell it from an absent option; the documented form is `--key=`.
- `wp-config.php` check after the core block; DB kept on downgrade; `DISABLE_WP_CRON` pre-defined false; concurrent suite runs; previous leg skips activation hooks; `WOPTIMIZE_CONTRACT_FILE` meaning per container; `assertResponseMatches` not counting as an assertion; hardcoded header names in `Playground.php`; no composer scripts — no path to the claimed consequence at the named site, or cosmetic.
- Spine still says 3.1; plain-permalink 422 — already deferred by this story.

## Design Notes

- **3.0.3, in the file, not in memory.** The dispatch note says: if no maintained 3.1 validator fits, write the contract 3.0.3-compatible. Every maintained PHP response validator sits on the League engine, which is 3.0.x; rewriting the version in memory would declare one thing and validate another. The file was written portable for exactly this flip (its own header says so).
- **A mount plus a symlink, not a relative symlink.** From `wordpress/wp-content/plugins/`, `packages/connector` is five levels up — outside the `apps/playground` mount, so a relative link is dangling inside the container (the same physics behind the www project-root rule). The portal already proved a `/mnt/woptimize` bind mount; a directory mount also avoids the single-file inode staleness the portal documents. AD-18's "symlink" survives: the plugin folder is a symlink and the code is the repo's, live.
- **WP `6.7` exactly.** The playground plays the worst client the floor allows: PHP 8.1 and the oldest supported WordPress. www pins 7.1 for the same reason in the other direction.
- **`DISABLE_WP_CRON` in the playground.** A browser hit or the portal's `site:ping` must never spawn a background phone-home in the middle of a scenario. WP-CLI runs events regardless of the constant.
- **Two PHPUnit invocations per leg.** The offboarded scenario needs the portal to delete the row between two phone-homes, and artisan is reachable only from the host. The orchestrator is the "one command"; the tests stay pure observations of `wp option` and `wp cron`.
- **`--key` and AD-16.** The portal still issues every key: `site:onboard` writes the hash and the ciphertext exactly as before, and the connector still only caches what it is given. The option merely lets a fixture be reproducible; the production guard keeps it out of `portal.woptimize.io`.
- **`pull_request` beside `workflow_call`.** Until stories 8–9 add `needs:`, nothing would invoke a `workflow_call`-only file. A PR trigger runs the suite before merge; the deploy workflows run it again before flipping. Story 8 may drop the PR trigger if double runs annoy.
- **Previous-minor rule.** Same MAJOR, lower MINOR, highest such tag. A major bump is a contract break (AD-5), so 1.0 has no previous leg, matching the spine's "first minor after a major".

## Verification

**Commands:**

- `cd packages/connector && ddev composer test && ddev composer lint` -- expected: exit 0 with `test_openapi_version` green on `3.0.3`.
- `cd apps/portal && ddev restart && ddev exec php artisan test && ddev exec ./vendor/bin/pint --test` -- expected: green, `ContractTest` ran, the three `--key` tests listed.
- `cd apps/playground && ddev start && ddev playground-setup && ddev playground-setup` -- expected: both exit 0; second run downloads and installs nothing.
- `cd apps/playground && ddev composer install && ddev contract-suite all` -- expected: exit 0, `current: PASS`, `previous: SKIPPED — no plugin-v* tag below 0.1`.
- `git tag plugin-v0.0.1 HEAD~1 && (cd apps/playground && ddev contract-suite previous); git tag -d plugin-v0.0.1` -- expected: `previous: PASS (plugin-v0.0.1)`; symlink restored.
- `actionlint` -- expected: exit 0. `git status --porcelain` -- expected: only intended files.

**Manual checks (if no CLI):**

- After the story, run `bmad-project-context` so `AGENTS.md` learns the playground, the suite command, the `--key` option, and the 3.0.3 decision — never hand-edit the block.

## Suggested Review Order

**Entry point — the one command**

- The orchestrator: fixture reset, two PHPUnit runs per leg, offboard between them, verified freshen.
  [`contract-suite:138`](../../../../apps/playground/.ddev/commands/host/contract-suite#L138)
- Karin's rule: highest `plugin-v*` tag with same MAJOR, lower MINOR — or SKIPPED, exit 0.
  [`contract-suite:185`](../../../../apps/playground/.ddev/commands/host/contract-suite#L185)
- Previous leg: `git archive` → `.previous-connector`, symlink repointed, version verified in-container.
  [`contract-suite:226`](../../../../apps/playground/.ddev/commands/host/contract-suite#L226)
- The link check that stops a previous leg from testing HEAD twice.
  [`contract-suite:78`](../../../../apps/playground/.ddev/commands/host/contract-suite#L78)
- Closing phone-home must record `ok`, on the failure path too.
  [`contract-suite:164`](../../../../apps/playground/.ddev/commands/host/contract-suite#L164)
- Trap: restore the link on EXIT; on INT/TERM restore and exit 130.
  [`contract-suite:288`](../../../../apps/playground/.ddev/commands/host/contract-suite#L288)

**Playground project — the client floor**

- Read-only directory mount: a relative symlink from five levels down would dangle in the container.
  [`docker-compose.connector.yaml:13`](../../../../apps/playground/.ddev/docker-compose.connector.yaml#L13)
- The fixture key and portal URL, defined once, in the container env.
  [`config.yaml:20`](../../../../apps/playground/.ddev/config.yaml#L20)
- `WP_VERSION=6.7` — the oldest WordPress the connector claims; no env override.
  [`playground-setup:14`](../../../../apps/playground/.ddev/commands/host/playground-setup#L14)
- Symlink to the mount, refuses a real directory, waits for the container to see it.
  [`playground-setup:91`](../../../../apps/playground/.ddev/commands/host/playground-setup#L91)
- Fixture key written only when it differs — saving it fires a phone-home.
  [`playground-setup:152`](../../../../apps/playground/.ddev/commands/host/playground-setup#L152)
- mu-plugin: portal URL from env; loads before the connector's guarded define.
  [`woptimize-playground.php:15`](../../../../apps/playground/mu-plugins/woptimize-playground.php#L15)
- `DISABLE_WP_CRON`: only WP-CLI fires a phone-home mid-scenario.
  [`woptimize-playground.php:50`](../../../../apps/playground/mu-plugins/woptimize-playground.php#L50)
- mkcert CA for `*.ddev.site` hosts only — never `sslverify => false`; closes story-5 D3.
  [`woptimize-playground.php:74`](../../../../apps/playground/mu-plugins/woptimize-playground.php#L74)

**Contract flip (AD-4, spine Deferred)**

- `openapi: 3.0.3` — the League validator reads 3.0.x only; header states the decision.
  [`openapi.yaml:20`](../../../../packages/connector/openapi.yaml#L20)
- The connector's own test follows the flip.
  [`ContractTest.php:38`](../../../../packages/connector/tests/ContractTest.php#L38)

**Suite support — every response through the validator**

- Unreadable contract throws with the path; the suite fails, never skips.
  [`Contract.php:65`](../../../../apps/playground/tests/Support/Contract.php#L65)
- One call validates status, body schema, and documented headers for an `OperationAddress`.
  [`Contract.php:121`](../../../../apps/playground/tests/Support/Contract.php#L121)
- WP-CLI is the only way into WordPress state; per-call env plays the unreachable portal.
  [`WpCli.php:40`](../../../../apps/playground/tests/Support/WpCli.php#L40)
- Shared scenario reset: clear the state option and any queued retry; restore the key.
  [`ScenarioTestCase.php:24`](../../../../apps/playground/tests/Support/ScenarioTestCase.php#L24)

**Portal — reproducible fixture without breaking AD-16**

- `--key` refused in production and on bad format; one line, no row.
  [`SiteOnboard.php:82`](../../../../apps/portal/app/Console/Commands/SiteOnboard.php#L82)
- `issueKey(?string $key)`: same hash and ciphertext path; defensive format guard.
  [`Site.php:89`](../../../../apps/portal/app/Models/Site.php#L89)

**Matrix tests**

- Status: PHP 8.1, pretty-permalink `rest_base`, version header equals body.
  [`ConnectorEndpointsTest.php:63`](../../../../apps/playground/tests/ConnectorEndpointsTest.php#L63)
- Phone-home with the live `/status` body, validated both ways.
  [`PortalPhoneHomeTest.php:26`](../../../../apps/playground/tests/PortalPhoneHomeTest.php#L26)
- AD-7: transport error → exactly one retry; the retry never reschedules.
  [`NoOpTest.php:83`](../../../../apps/playground/tests/NoOpTest.php#L83)
- Offboarded: 401 after the portal deleted the row — staged by the orchestrator.
  [`OffboardedTest.php:31`](../../../../apps/playground/tests/OffboardedTest.php#L31)
- Contract unreadable row.
  [`ContractFileTest.php:29`](../../../../apps/playground/tests/ContractFileTest.php#L29)
- The three `--key` rows in Pest.
  [`SiteCommandsTest.php:95`](../../../../apps/portal/tests/Feature/Connector/SiteCommandsTest.php#L95)

**CI and peripherals**

- One job, one real step: the same command a developer runs.
  [`contract-suite.yml:72`](../../../../.github/workflows/contract-suite.yml#L72)
- `pull_request` runs it until stories 8–9 hang `needs:` on `workflow_call`.
  [`contract-suite.yml:15`](../../../../.github/workflows/contract-suite.yml#L15)
- Declaration order pinned — `NoOpTest` reads the previous test's tearDown.
  [`phpunit.xml.dist:13`](../../../../apps/playground/phpunit.xml.dist#L13)
- Dev-only dependencies at platform PHP 8.1.
  [`composer.json:8`](../../../../apps/playground/composer.json#L8)
- README section on the previous leg.
  [`README.md:125`](../../../../apps/playground/README.md#L125)
- Six deferred entries: first CI run, spine 3.0.3, plain permalinks, SHA pins, AGENTS.md refresh, stray AGENTS.md edit.
  [`deferred-work.md:44`](../../../../_bmad-output/implementation-artifacts/deferred-work.md#L44)
