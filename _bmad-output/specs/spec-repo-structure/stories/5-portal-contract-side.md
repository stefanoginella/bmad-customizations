---
title: 'Portal contract side'
type: 'feature'
created: '2026-08-24'
status: 'done'
review_loop_iteration: 0
baseline_commit: '575730b08ff8fd40127feea94f92d624faedf164'
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The portal has no site registry, issues no keys, and hosts no `/api/connector/v1` route. The connector built in story 4 phones home to a URL that 404s, and the portal cannot call a client site (CAP-3, portal half).

**Approach:** Add the `sites` registry (AD-6), Artisan commands as the v1 onboarding surface that issue, rotate, and revoke keys (AD-16 — a web UI arrives with the auth story), `POST /api/connector/v1/phone-home` behind a `connector` auth guard on `X-Woptimize-Site-Key`, and a `ConnectorClient` that calls the reported `rest_base` for `ping` and `status`. A Pest `ContractTest` reads `openapi.yaml` through a read-only DDEV bind mount. No contract change: everything here is already on the wire.

## Boundaries & Constraints

**Always:**

- Registry (AD-6): table `sites` — `site_url` (unique, human-entered), `home_url`, `rest_base`, `site_key`, `site_key_hash` (unique), `connector_version`, `last_seen_at`, `last_report` (JSON), timestamps. Rows are created only by `site:onboard`. `home_url`, `rest_base`, `connector_version`, `last_seen_at`, `last_report` are written only by a phone-home. The portal never derives `rest_base` from `site_url`.
- Key (AD-16, AD-5): `Str::random(40)` — alphanumeric, matches `^[A-Za-z0-9]{40}$`. Shown once on the console, stored with the `encrypted` cast, looked up by `site_key_hash = sha256(key)`, then confirmed with `hash_equals()` against the decrypted value. `site:rotate-key` replaces it at once; the old key is dead the same second.
- Auth: guard `connector` (`Auth::viaRequest('site-key', …)`), route middleware `auth:connector`. Missing header, malformed, unknown, rotated-away, or offboarded key → Laravel's default `401 {"message":"Unauthenticated."}`, no row written or touched, one `warning` log line per client IP per minute via `RateLimiter::attempt` (AD-6).
- Phone-home: validates the `SiteReport` exactly as `openapi.yaml` types it (FormRequest → default `422 {message, errors}`); `theme.version` may be an empty string; unknown fields are ignored, not stored (AD-8). On success it updates the authenticated row — `home_url`, `rest_base`, `connector_version`, `last_seen_at = now()`, `last_report` = the validated data — and answers `200 {"ok":true}`.
- Outbound (AD-5, AD-6): `ConnectorClient::ping(Site)` / `status(Site)` → `GET {rest_base}/ping|/status`, header key, `Accept: application/json`, `User-Agent: WOptimize-Portal`, 10 s timeout, no redirects. Refuses when `rest_base` is null. A transport error is a result, not an exception. A pull never writes the registry — `last_seen_at` means "phoned home".
- Commands: `site:onboard {site_url}`, `site:list`, `site:rotate-key {site}`, `site:offboard {site}`, `site:ping {site}`, `site:status {site}`; `{site}` is an id or a `site_url`. Failures exit 1 with one line; `site:list` never prints a key.
- Contract facts (header names, key length and pattern, timeout) live once in `App\Connector\Contract`; `tests/Feature/ContractTest.php` parses the file at `WOPTIMIZE_CONTRACT_FILE` (default `/mnt/woptimize/openapi.yaml`, mounted read-only by `.ddev/docker-compose.contract.yaml`) and **fails** when it is missing.
- Migration is expand-only (AD-10). Pest on sqlite `:memory:` covers every matrix row; Pint clean.

**Never:**

- No web UI, no views, no auth scaffolding, no Sanctum (`install:api` not run), no `throttle` on the route (a 429 would silence a site for a day, AD-7).
- No `openapi.yaml` edit, no update-check or zip paths (story 9), no playground or CI (story 6), no connector code change.
- No license logic, no connector-version gating, no plaintext key column, no lookup by decrypting rows, no key in logs or in `site:list`.
- No hand edit of `AGENTS.md` (managed block — `bmad-project-context` refreshes it). No `APP_URL` / `DB_*` writes from any script.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Onboard | `site:onboard https://client.example` | row created, `rest_base` null, key printed once, exit 0 | not a http(s) URL or duplicate `site_url` → exit 1, no row |
| Phone-home OK | valid key + valid `SiteReport` | `200 {"ok":true}`; row gets `home_url`, `rest_base`, `connector_version`, `last_seen_at`, `last_report` | N/A |
| Bad key | header missing, 39 chars, unknown, rotated-away, offboarded | `401 {"message":"Unauthenticated."}`; no row created or touched; one warning per IP per minute | N/A |
| Bad body | valid key; `multisite: "yes"`, or `rest_base` missing | `422 {message, errors}`; `last_seen_at` unchanged | N/A |
| Extra field | valid key; report carries `future_field` | `200`; `last_report` has no `future_field` | N/A |
| Rotate | `site:rotate-key <site>` | new key printed; old key → 401; new key → 200 | unknown site → exit 1 |
| Offboard | `site:offboard <site>` | row deleted; its key → 401 from then on | unknown site → exit 1 |
| Ping / Status | site has phoned home; connector answers 200 | prints `ok` + `X-Woptimize-Connector-Version` / prints the report JSON; exit 0 | connector 401, 5xx, or transport error → prints the status or error, exit 1; registry untouched |
| Ping before first report | `rest_base` null | exit 1: "has not phoned home yet"; no request | N/A |
| List | `site:list` | table: id, site_url, connector_version, last_seen_at | N/A |

</frozen-after-approval>

## Code Map

- `apps/portal/bootstrap/app.php:9-13` -- `withRouting(web:, commands:, health:)`; add `api: __DIR__.'/../routes/api.php'`. `shouldRenderJsonWhen` already covers `api/*` (line 19). Do not call `throttleApi()`.
- `apps/portal/routes/api.php` -- absent; create. `withRouting(api:)` wraps it in the `api` group under prefix `api` (`vendor/laravel/framework/src/Illuminate/Foundation/Configuration/ApplicationBuilder.php:216`). The default `api` group holds only `SubstituteBindings` — no throttle unless `throttleApi()` is called (`…/Configuration/Middleware.php:495-498`).
- `apps/portal/config/auth.php:37-42` -- add guard `'connector' => ['driver' => 'site-key']`. A `viaRequest` guard needs no `provider` (`…/Auth/AuthManager.php:233`; `CreatesUserProviders.php:24-41` returns null silently).
- `apps/portal/app/Providers/AppServiceProvider.php` -- `boot()`: `Auth::viaRequest('site-key', fn (Request $r) => …)`. Return the `Site` or null. The `auth` middleware turns null into `AuthenticationException` → `401 {"message":"Unauthenticated."}` for JSON (`…/Foundation/Exceptions/Handler.php:843-853`).
- `apps/portal/app/Models/User.php` -- house style for a model; `Site` implements `Illuminate\Contracts\Auth\Authenticatable` via the `Illuminate\Auth\Authenticatable` trait (all 7 methods, `vendor/…/Auth/Authenticatable.php`). Casts: `site_key => 'encrypted'`, `last_report => 'array'`, `last_seen_at => 'immutable_datetime'`; `$hidden = ['site_key', 'site_key_hash']`.
- Encrypted cast is `Crypt::encrypt($v, false)` with a fresh IV per call (`…/Encryption/Encrypter.php:106`) — never indexable; hence `site_key_hash`.
- `Str::random(40)` strips `/`, `+`, `=` from base64 and trims to length (`…/Support/Str.php:1137-1154`) — strictly `[A-Za-z0-9]`.
- `RateLimiter::attempt($key, $maxAttempts, Closure $callback, $decaySeconds = 60)` (`…/Cache/RateLimiter.php:106`); `CACHE_STORE=array` in tests.
- FormRequest failure → `422 {message, errors}` (`Handler.php:897-903`, message "The given data was invalid." or the first error). Rules mirror `openapi.yaml` `SiteReport`: `connector_version` `regex:/^\d+\.\d+\.\d+$/`, three `url`s, strings, `multisite` `boolean` (strict — reject `"yes"`), `theme.slug|name` `string`, `theme.version` `present|string`, `updates.*` `integer|min:0`. Store `$request->validated()` — unknown keys drop out.
- HTTP client: `withHeaders`, `acceptJson`, `withUserAgent`, `withoutRedirecting`, `timeout`, `get` (`…/Http/Client/PendingRequest.php:432-863`); transport failure **throws** `Illuminate\Http\Client\ConnectionException` (`PendingRequest.php:1113-1119`, `2083-2096`) — catch it in the client. `Response::status()`, `successful()`, `json()`, `header()` (`…/Http/Client/Response.php:107-230`). `Http::fake()` / `Http::assertSent()` for tests.
- Commands in `app/Console/Commands/` are auto-discovered (`ApplicationBuilder.php:334-345`). Option syntax `{--name=}` (`…/Console/Parser.php:109`).
- `packages/connector/tests/ContractTest.php` -- the guard to mirror on the portal side: `Yaml::parseFile`, `paths_hosted_by('{portal}')`, `walk()`. `symfony/yaml` is **not** installed in the portal (`vendor/symfony/` has no `yaml`); the portal runs Symfony 8.1 → `ddev composer require --dev symfony/yaml:^8.1`.
- `packages/connector/includes/class-phone-home.php:194-210` -- what the portal receives: `Content-Type`/`Accept: application/json`, `X-Woptimize-Site-Key`, `User-Agent: WOptimize-Connector/<v>`, JSON body from `Site_Report::build()` (`class-site-report.php:26-52`).
- `packages/connector/openapi.yaml:138-190` -- the `/phone-home` responses (`200` PhoneHomeAck, `401`/`422`/`5XX` LaravelError); `:190-200` the `SiteKey` header scheme; `:237` the open-schema note (AD-8).
- `apps/portal/.ddev/` -- no custom compose file yet. `docker-compose.contract.yaml`: `services.web.volumes: ["../../../packages/connector/openapi.yaml:/mnt/woptimize/openapi.yaml:ro"]`; relative paths resolve from `.ddev/`; outside the Mutagen tree, so no `mutagen.yml` change; the same file works under `ddev/github-action-setup-ddev` (docs: custom-compose-files, performance). Verified on DDEV v1.25.3 / OrbStack; `portal.woptimize` is running.
- `apps/portal/tests/Pest.php:17-19` -- `RefreshDatabase` is commented out; enable it for `Feature`. `phpunit.xml:27-28` sqlite `:memory:`.
- `apps/portal/README.md` "Layout" and "Rules", `README.md` "Getting started" -- add the contract-side lines and the mount fact ("each DDEV project mounts only its own app folder" gains one exception).

## Tasks & Acceptance

**Execution:**

- [x] `apps/portal/composer.json`, `composer.lock` -- `ddev composer require --dev symfony/yaml:^8.1` -- the contract test's parser.
- [x] `apps/portal/database/migrations/<ts>_create_sites_table.php` -- create -- columns above; `site_url` unique, `site_key_hash` `string(64)` unique, `site_key` `text`, nullable `home_url`, `rest_base`, `connector_version`, `last_seen_at`, `last_report` json.
- [x] `apps/portal/app/Connector/Contract.php` -- create -- `SITE_KEY_HEADER`, `VERSION_HEADER`, `KEY_LENGTH`, `KEY_PATTERN`, `TIMEOUT_SECONDS`, `USER_AGENT`, `PATH_PREFIX = '/api/connector/v1'`.
- [x] `apps/portal/app/Connector/SiteKey.php` -- create -- `generate()`, `hash()`, `isValidFormat()`.
- [x] `apps/portal/app/Models/Site.php`, `database/factories/SiteFactory.php` -- create -- Authenticatable; casts; `findByKey(?string)` (format check → hash lookup → `hash_equals`); `issueKey()` returns the plaintext once.
- [x] `apps/portal/config/auth.php`, `app/Providers/AppServiceProvider.php` -- edit -- guard `connector`; `viaRequest` callback with the rate-limited warning on a miss.
- [x] `apps/portal/app/Http/Requests/Connector/PhoneHomeRequest.php`, `app/Http/Controllers/Connector/PhoneHomeController.php`, `routes/api.php`, `bootstrap/app.php` -- create/edit -- the endpoint and its wiring.
- [x] `apps/portal/app/Connector/ConnectorClient.php`, `ConnectorResult.php` -- create -- `ping()`, `status()`; result carries `ok`, `status`, `body`, `connectorVersion`, `error`.
- [x] `apps/portal/app/Console/Commands/Site{Onboard,List,RotateKey,Offboard,Ping,Status}.php` -- create -- one class each; shared `{site}` resolver.
- [x] `apps/portal/.ddev/docker-compose.contract.yaml` -- create -- the read-only mount; then `ddev restart`.
- [x] `apps/portal/tests/Pest.php`, `tests/Feature/Connector/{PhoneHomeTest,SiteKeyGuardTest,SiteCommandsTest,ConnectorClientTest}.php`, `tests/Feature/ContractTest.php` -- create/edit -- every matrix row; `ContractTest`: `/phone-home` is the only `{portal}` path and its `servers[0].url` is `{portal}` + `Contract::PATH_PREFIX`, method `post`, `securitySchemes.SiteKey.name` = `Contract::SITE_KEY_HEADER`, `components.headers.ConnectorVersion` name = `Contract::VERSION_HEADER`, `SiteReport.required` = the FormRequest's top-level rule keys, documented responses `200/401/422/5XX`, `info.version` pattern.
- [x] `apps/portal/README.md`, `README.md` -- edit -- commands, the endpoint, the mount, the `AGENTS.md` refresh hint.

**Acceptance Criteria:**

- Given `apps/portal` after `ddev restart`, when `ddev exec php artisan test` and `ddev exec ./vendor/bin/pint --test` run, then both exit 0 and `ContractTest` ran (not skipped).
- Given a site onboarded with `ddev exec php artisan site:onboard https://woptimize.ddev.site`, when `curl -s -o /dev/null -w '%{http_code}' -X POST -H "X-Woptimize-Site-Key: <key>" -H 'Content-Type: application/json' -d @report.json https://portal.woptimize.ddev.site/api/connector/v1/phone-home` runs with a body copied from the connector's `/status`, then `200`, and `site:list` shows `connector_version` and a fresh `last_seen_at`; the same call with a wrong key returns `401` and `last_seen_at` is unchanged.
- Given that site and the connector smoke install on www with the same key, when `ddev exec php artisan site:ping <id>` runs, then it prints `ok` and the connector version and exits 0.
- Given the repo after the build, when `git status --porcelain` runs, then no `vendor/`, `.env`, or DDEV runtime file appears.

## Spec Change Log

- 2026-08-24, after review iteration 1 (human decision): the security layer's SSRF finding on `rest_base` was first dismissed because the Design Notes accepted a key-holder-controlled `rest_base`. The human overruled: the portal now rejects a `rest_base` / `home_url` whose host resolves to a loopback, private (RFC1918), link-local, unique-local, or `0.0.0.0/8` address (`App\Connector\Rules\PublicHost`), gated by `WOPTIMIZE_ALLOW_PRIVATE_REST_BASE` (true only in DDEV, where `*.ddev.site` → 127.0.0.1). Known-bad state avoided: a validated key holder turning `site:status` into an internal-network read. KEEP: no host comparison against `site_url`/`home_url` (still meaningless), `withoutRedirecting()`, human-run pulls only, the frozen matrix unchanged.

## Review Triage Log

Review iteration 1 — 8 layers (blind, edge-case, verification-gap, security, structure, external blind/edge/intent via Codex gpt-5.6-sol). Every finding was verified at its site before the verdict.

**Patched (this story's problem):**

- P1 `url` rule admits any scheme (`file://`, `ftp://`…) on `site_url`/`home_url`/`rest_base` → `url:http,https`. [security, blind, edge, ext-blind] — high
- P2 Non-strict `boolean`/`integer` accept `1`, `"1"`, `"2"` although the contract types booleans/integers → `boolean:strict`, `integer:strict`. [ext-intent, ext-blind, ext-edge, edge] — medium
- P3 URLs and version unbounded vs `varchar(255)` columns (MariaDB 500); `$` regex anchor admits a trailing newline → `max:255`, `\z`/`D` anchors on both patterns. [edge, ext-edge, ext-blind] — medium
- P4 Plain strings use `required` but the contract has no `minLength`; with `ConvertEmptyStringsToNull` off an empty site title gets a 422 → `present|string`. [ext-blind] — medium
- P5 `DecryptException` (rotated `APP_KEY`, corrupt row) and a cache failure inside the refused-key warning escape the guard as 500, which AD-7 turns into a 15-min retry instead of quiet → both caught, guard returns null. [blind, edge, ext-blind] — medium
- P6 Any 2xx with a non-JSON/absent body (204, HTML) is `ok`; `site:status` prints `null` and exits 0; a ping without the required version header reads as healthy; a `rest_base` with query/fragment breaks the path join → `ok` = 200 + JSON object (+ version header on ping); `rest_base` rejects query/fragment. [edge, ext-edge, ext-blind] — medium
- P7 Two `site:onboard` for one URL race past `exists()` into a stack trace → catch `UniqueConstraintViolationException`, exit 1. [edge, ext-edge, ext-blind] — low
- P8 `ctype_digit("0123")` makes `site:offboard 0123` delete site 123 → id branch only when `(string)(int)$x === $x`. [edge] — medium
- P9 `#[Fillable]` lists the two secret columns; only `site_url` is ever mass-assigned → trimmed. [structure, blind, ext-blind] — low
- P10 `Str::after(PATH_PREFIX,'/api/')` fails open and `bootstrap/app.php` slices the same constant a second way → both forms derived once in `Contract`. [structure, blind] — low
- P11 Connector guard logic (30 lines) sits in the generic `AppServiceProvider` → invokable `App\Connector\SiteKeyGuard`. [structure] — low
- P12 `ContractTest` compares only top-level `required`, does not sort the status list, dereferences `servers[0]` unguarded, never checks `/ping` `/status` or the no-throttle rule → extended. [verification, blind, ext-blind, edge] — medium
- P13 Missing tests: padded string survives (`TrimStrings` exemption), raw DB column is not plaintext, warning context carries no key, nested-required and bad-version 422s. [verification, blind, ext-blind] — medium
- P14 Tests/factory repeat `Str::random(40)`, `hash('sha256')`, three near-identical site helpers, a `withPlainKey` state the `encrypted` cast makes redundant, a generic `walkContract` with one caller → consolidated. [structure, blind, ext-intent] — low
- P15 `apps/portal/README.md` and the `Contract` docblock claim `ContractTest` ties key length/pattern/timeout to the file (it cannot — the file carries them only as prose); root README calls all of `AGENTS.md` a managed block (only the marked block is); single-file bind mount goes stale on write-and-rename until `ddev restart` → wording fixed, restart note added. [verification, blind, ext-blind] — low
- P16 `composer.json` `php ^8.4` while `symfony/yaml` requires `>=8.4.1` → `^8.4.1`. [ext-blind] — low

**Deferred (not this story's problem / agent-context document):**

- D1 `AGENTS.md` managed block no longer states the portal's read-only contract mount, the `site:*` commands, or the `symfony/yaml` dev dependency — refresh via `bmad-project-context`, never hand-edit. [blind]
- D2 Per-IP warning throttle keys on `Request::ip()` with no `trustProxies()`; behind RunCloud/CDN it collapses to one warning per minute fleet-wide — deployment config, story 8. [verification, blind, edge]
- D3 WordPress ships its own CA bundle without DDEV's mkcert root, so a connector on `www` cannot reach `portal.woptimize.ddev.site` over TLS without a local `sslverify` override — story 6 playground setup. [GREEN agent smoke]

**Dismissed:**

- ~~SSRF private-range check on `rest_base`~~ — first dismissed as a spec decision, then **patched on human instruction** (P17, see Spec Change Log): `PublicHost` rule, env-gated for DDEV. The "cloned site overwrites its row" variant stays dismissed: the Design Notes reject a host comparison against `site_url`. [security, blind, ext-blind]
- `site:onboard` URL canonicalisation (trailing slash, case, default port): the spec defines `site_url` as human-entered and unique as typed. [edge, ext-blind]
- `site:offboard` confirmation / `--force`: not in the spec; `confirmToProceed()` only prompts in production. Low. [blind, ext-blind]
- Concurrency: two rotations, two refused requests inside one rate-limit tick, offboard between auth and save, crash before the key prints — single-operator human-run commands; consequence tolerable. [ext-edge]
- Timeout / no-redirect not asserted: `Http::fake()` exposes neither option and never follows redirects, so no fake-based test can observe them; a value pin proves nothing. [verification, blind, ext-blind]
- Header/path literals in `tests/Pest.php`: deliberate — a test that reads `Contract::*` cannot catch a drift in `Contract::*`. Fixture generation is patched (P14). [ext-intent, edge]
- `env()` in `ContractTest`, `RefreshDatabase` cost on `ContractTest`, response size cap, JSON content-type enforcement, `SitePing` fallback text: cosmetic or no consequence (`SitePing` is covered by P6). [blind, ext-blind]
- AD-8 version-window check: the spec's Never list forbids connector-version gating. [blind]
- No CI, story file untracked, the two `ContractTest`s duplicate each other: stories 6/8, the spec is committed with the work, AD-1 forbids sharing test code across apps. [blind]

## Design Notes

- Artisan as the v1 onboarding surface: the portal has no auth yet and the spine defers portal internal architecture; a key-issuing web page without login cannot exist on `portal.woptimize.io`. The commands are the "portal-side onboarding" AD-6 requires, and AD-16's core rule — the portal issues every key — holds.
- A `viaRequest` guard rather than a custom middleware: it is Laravel's own API-token idiom (AD-15), gives `$request->user()` the `Site`, and the 401 shape is the framework default the contract documents.
- `sha256(key)` as the lookup column: 40 alphanumeric characters ≈ 238 bits, so a plain hash is not reversible in practice; an HMAC would add a second secret for no gain.
- No route throttle: AD-7 makes any 4xx permanent-quiet for a day, so a 429 caused by a shared client IP would silence honest sites. The key space makes brute force irrelevant; the rate limit goes on the log line instead.
- A pull (`site:status`) never writes the registry: AD-19 reads `last_seen_at` as "the cron still works", which a manual pull would fake.
- The portal calls whatever `rest_base` a site reported: a key holder controls its own report, so a host check against `home_url` proves nothing. What the portal does refuse is a `rest_base` on a non-public address (loopback, RFC1918, link-local, ULA, `0.0.0.0/8`) — the only thing a hostile key holder gains from `rest_base` is a read into the portal's own network. `WOPTIMIZE_ALLOW_PRIVATE_REST_BASE=true` lifts that check in DDEV only. Outbound calls happen only from a human-run command, with a 10 s cap and no redirects.

## Verification

**Commands:**

- `cd apps/portal && ddev restart && ddev exec test -r /mnt/woptimize/openapi.yaml` -- expected: exit 0.
- `ddev exec php artisan migrate --force` -- expected: `sites` created. `ddev exec php artisan test` -- expected: green, `ContractTest` listed. `ddev exec ./vendor/bin/pint --test` -- expected: exit 0.
- Smoke: `site:onboard`, then the connector on www (`packages/connector/README.md` walkthrough) with `WOPTIMIZE_PORTAL_URL` set to `https://portal.woptimize.ddev.site` and the printed key; `ddev wp cron event run woptimize_connector_phone_home` on www; `site:list`, `site:ping`, `site:status` on the portal; then `site:offboard` and the cron run again → the www settings page shows `client_error 401`.
- `git status --porcelain` -- expected: only intended files.

## Suggested Review Order

**Entry point — the key on the wire**

- Format check → sha256 lookup → `hash_equals`; a decrypt failure is a miss, never a 500
  [`Site.php:51`](../../../../apps/portal/app/Models/Site.php#L51)

- The `viaRequest` guard: null → framework 401; one rate-limited warning per IP, never the key
  [`SiteKeyGuard.php:23`](../../../../apps/portal/app/Connector/SiteKeyGuard.php#L23)

- `issueKey()` saves before it returns — the old key is dead the same second
  [`Site.php:79`](../../../../apps/portal/app/Models/Site.php#L79)

- 40 alphanumerics from `Str::random`; the `D` anchor rejects a trailing newline
  [`Contract.php:34`](../../../../apps/portal/app/Connector/Contract.php#L34)

**Phone-home — the portal half of the contract**

- Strict JSON types, `http(s)` only, no query on `rest_base`, empty strings allowed where the contract has no `minLength`
  [`PhoneHomeRequest.php:33`](../../../../apps/portal/app/Http/Requests/Connector/PhoneHomeRequest.php#L33)

- Stores only `validated()` — unknown fields drop out (AD-8); never touches `site_url`
  [`PhoneHomeController.php:20`](../../../../apps/portal/app/Http/Controllers/Connector/PhoneHomeController.php#L20)

- `auth:connector`, deliberately no `throttle` (AD-7)
  [`api.php:9`](../../../../apps/portal/routes/api.php#L9)

- Connector prefix exempt from `TrimStrings` / `ConvertEmptyStringsToNull` — reports arrive character for character
  [`app.php:20`](../../../../apps/portal/bootstrap/app.php#L20)

- One wire path, two derived forms — `routePrefix()` fails loud if the prefix ever leaves `/api/`
  [`Contract.php:58`](../../../../apps/portal/app/Connector/Contract.php#L58)

**Outbound — the portal calls the reported `rest_base`**

- Refuses a null `rest_base`; transport error is a result; key, `Accept`, `User-Agent`, 10 s, no redirects
  [`ConnectorClient.php:27`](../../../../apps/portal/app/Connector/ConnectorClient.php#L27)

- `ok` means 200 + JSON object (+ version header on ping); anything else exits 1
  [`ConnectorClient.php:80`](../../../../apps/portal/app/Connector/ConnectorClient.php#L80)

- One template for `site:ping` / `site:status`: a pull never writes the registry (AD-19)
  [`SitePullCommand.php:25`](../../../../apps/portal/app/Console/Commands/SitePullCommand.php#L25)

**Onboarding surface (Artisan, AD-16)**

- `http(s)` check, then the unique index is the duplicate guard — one line, exit 1
  [`SiteOnboard.php:34`](../../../../apps/portal/app/Console/Commands/SiteOnboard.php#L34)

- `{site}` is an id only when it round-trips through `(int)` — `0123` never hits site 123
  [`ResolvesSite.php:26`](../../../../apps/portal/app/Console/Commands/Concerns/ResolvesSite.php#L26)

**Contract guard**

- Parses the mounted `openapi.yaml`; fails (never skips) when the mount is missing
  [`ContractTest.php:119`](../../../../apps/portal/tests/Feature/ContractTest.php#L119)

- Top-level and nested `required` lists must equal the FormRequest rule keys
  [`ContractTest.php:156`](../../../../apps/portal/tests/Feature/ContractTest.php#L156)

- The no-throttle rule is asserted, not just commented
  [`ContractTest.php:213`](../../../../apps/portal/tests/Feature/ContractTest.php#L213)

- The one exception to "each DDEV project mounts only its own folder" — read-only
  [`docker-compose.contract.yaml:8`](../../../../apps/portal/.ddev/docker-compose.contract.yaml#L8)

**Peripherals**

- Expand-only `sites` table; `site_key` text (ciphertext), `site_key_hash` char(64) unique
  [`create_sites_table.php:14`](../../../../apps/portal/database/migrations/2026_08_24_000000_create_sites_table.php#L14)

- Shared fixtures: `reportedSite()`, `assertRegistryUntouched()`, `siteReport()`
  [`Pest.php:74`](../../../../apps/portal/tests/Pest.php#L74)
