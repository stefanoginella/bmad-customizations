---
title: 'Portal scaffold'
type: 'feature'
created: '2026-08-24'
status: 'done'
review_loop_iteration: 0
baseline_commit: 'edbebdfca85bbd1e0d43614115ac41a7a97aad26'
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `apps/portal` is an empty `.gitkeep` placeholder — no portal codebase, no local dev (CAP-1, portal half).

**Approach:** Scaffold a bare Laravel 13 app in `apps/portal` via `ddev composer create-project`, as its own DDEV project at `https://portal.woptimize.ddev.site`. Replace the skeleton's PHPUnit with Pest 5 + `pest-plugin-laravel`, keep the bundled Pint with an explicit `pint.json`, and commit an idempotent `ddev portal-setup` host command mirroring `www-setup`.

## Boundaries & Constraints

**Always:**

- DDEV project root `apps/portal`, `name: portal.woptimize`, `type: laravel`, `docroot: public` (→ `https://portal.woptimize.ddev.site`, spine Hostnames). Production hostname `portal.woptimize.io` is story 8's concern.
- Stack pins: PHP 8.4; Laravel 13; `nodejs_version: "24"`; MariaDB 11.8 explicit with the same "provisional, mirrors RunCloud" comment as the www config.
- Tests: Pest 5.x + `pest-plugin-laravel` 5.x (the PHP 8.4 line); `php artisan test` must run Pest. Style: bundled Pint plus committed `pint.json` pinning `"preset": "laravel"`.
- DDEV's `laravel` type owns `.env`: creates it from `.env.example`, patches `APP_URL` + `DB_*` (`db`/`db`/`db`, `DB_CONNECTION=mariadb`) every start. `portal-setup` never writes those keys.
- In git, `apps/portal` carries the skeleton with its own `.gitignore` (`vendor/`, `node_modules/`, `.env`, `public/build` stay out); the `.gitkeep` goes away.
- No hand-created state: fresh clone + `ddev start` + `ddev portal-setup` is the whole recipe; re-runs idempotent, exit 0.
- Generalize the root `.gitattributes` LF rule to `apps/*/.ddev/commands/**` so the new command survives Windows checkouts.

**Never:**

- No token artifacts (`resources/css/tokens.*.css` — story 3, AD-3); the scaffolded `app.css`/Tailwind stay as-is.
- No contract code or `/api/connector/v1` routes (story 5); no CI or deploy config (stories 7–8).
- No starter kit, no auth scaffolding — bare skeleton only.
- No root `composer.json`, no workspaces (spine conventions).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Fresh clone | `ddev start` + `ddev portal-setup` | composer install, APP_KEY set, migrations on MariaDB, assets built; site serves 200 | N/A |
| Re-run setup | second `ddev portal-setup` | Idempotent: exits 0, APP_KEY unchanged, no destructive work | N/A |
| Missing `.env` | `.env` deleted after start | Setup stops: DDEV owns the file | exit 1 with "run `ddev restart`, then re-run" |
| Test run | `ddev exec php artisan test` | Pest runs the example tests green on sqlite `:memory:` (skeleton `phpunit.xml` env) | N/A |

</frozen-after-approval>

## Code Map

- `apps/www/.ddev/config.yaml` -- config shape to mirror: explicit pins + provisional MariaDB comment.
- `apps/www/.ddev/commands/host/www-setup` -- host-command pattern: `set -euo pipefail`, `DDEV_APPROOT` guard, fail-loud guards with recovery instructions, idempotent sections.
- `apps/www/README.md` -- README structure to mirror.
- `.gitattributes:6` -- LF rule currently scoped to `apps/www/.ddev/commands/**` only.
- `README.md` -- "Getting started" lists only www; add the portal line.
- `apps/portal/.gitkeep` -- delete when the scaffold lands.
- Researched 2026-08-24 (official sources): `laravel/laravel` 13.x ships PHPUnit 12 + Pint ^1.27 — no Pest, no Sail; `.env.example` defaults to sqlite; `database/database.sqlite` is skeleton-gitignored. DDEV: `ddev composer create-project` is the current form; dotted project names valid per `ValidateProjectName` (source-confirmed — first `ddev start` verifies live); the laravel type never runs `key:generate`, `composer install`, or migrations.

## Tasks & Acceptance

**Execution:**

- [x] `apps/portal/.ddev/config.yaml` -- create -- `name: portal.woptimize`, `type: laravel`, `docroot: public`, `php_version: "8.4"`, `nodejs_version: "24"`, `database: mariadb:11.8` (provisional comment), `webserver_type: nginx-fpm`.
- [x] `apps/portal/` -- scaffold -- `ddev start`, then `ddev composer create-project laravel/laravel` (bare skeleton → Laravel 13); delete `.gitkeep`; keep the skeleton's `.gitignore`.
- [x] `apps/portal/composer.json` -- rewire tests -- remove `phpunit/phpunit`; require `pestphp/pest` + `pestphp/pest-plugin-laravel` (dev, Pest 5 line, `--with-all-dependencies`); `pest --init`; convert both `ExampleTest.php` files to Pest syntax.
- [x] `apps/portal/pint.json` -- create -- `{"preset": "laravel"}`.
- [x] `apps/portal/.ddev/commands/host/portal-setup` -- create -- idempotent: `composer install` → `.env` guard (exit 1, restart instruction) → `key:generate` only when `APP_KEY` empty → `migrate` → `npm install` + `npm run build`.
- [x] `.gitattributes` -- edit -- generalize the LF rule to `apps/*/.ddev/commands/**`.
- [x] `apps/portal/README.md` -- create -- mirror the www README: prerequisites, two-command quickstart, what setup produces, test/lint commands, rules.
- [x] `README.md` -- edit -- add the portal line to "Getting started".

**Acceptance Criteria:**

- Given a fresh clone, when the developer runs `ddev start` then `ddev portal-setup` in `apps/portal`, then `https://portal.woptimize.ddev.site` serves HTTP 200.
- Given the finished scaffold, when `ddev exec php artisan test` runs, then Pest (not PHPUnit) executes the example tests green.
- Given the finished scaffold, when `ddev exec ./vendor/bin/pint --test` runs, then it exits 0.
- Given the installed project, when running `git status`, then nothing under `vendor/`, `node_modules/`, `public/build/`, or `.env` appears.

## Spec Change Log

## Review Triage Log

Review 2026-08-24 — 6 layers (blind, edge, verification-gap, security, external blind/edge/intent via codex gpt-5.6-sol). Security: no findings. External intent audit: clean ("faithfully implements the only defensible reading"). No intent_gap, no bad_spec.

**Kept — patch:**

- P1 (medium) `composer.json` keeps the skeleton's `"php": "^8.3"` (and the lock's platform block records it) while the story pins PHP 8.4; the 8.4 floor comes only from dev-only Pest, so a `--no-dev` install accepts PHP 8.3. Fix: raise to `^8.4`, sync the lock. [blind, edge, vgap, ext-blind]
- P2 (medium) The skeleton's `scripts.setup` in `composer.json` is a second bootstrap that copies `.env` from `.env.example`, rotates `APP_KEY` unconditionally, and migrates against sqlite — violating the frozen "DDEV owns `.env`" and single-recipe rules. Fix: remove the `setup` script. Create-time-only hooks (`post-create-project-cmd`, `post-root-package-install`) are inert post-scaffold and stay. [blind, vgap, ext-blind]
- P3 (low) `apps/portal/README.md` inaccuracies: "a second run re-installs nothing" (composer/npm do re-run, as no-ops; assets rebuild), the "Starting over" comment implies `ddev restart` always regenerates `.env` (only when missing — a corrupted `.env` must be deleted first), and the `rm -rf` block lacks a `cd apps/portal` guard. [blind, ext-blind]

**Deferred** (see `deferred-work.md`): portal clean-room CI check incl. `git check-attr` (story 7); stale `AGENTS.md` portal facts (agent-context doc — never patched from a build); robots.txt/welcome/`/up` public-surface policy before story 8's deploy flip; `ddev_version_constraint` missing in both apps' DDEV configs.

**Dismissed:**

- `composer install` before the `.env` guard; `npm install` (not `ci`): both are the spec task list's own sequence — fix would edit the spec. With the lock in sync, `npm install` installs lock-faithfully.
- `APP_KEY` parse edge states (`null`, missing line, duplicate lines, malformed key) and the Mutagen read race: every trigger needs a hand-edited `.env` or a same-second re-run; the recipe forbids hand-created state, DDEV regenerates `.env` (with its `APP_KEY=` line) whenever missing, and the live double-run showed the key byte-identical. No reachable path.
- No `config:clear` / stale `public/hot` handling: the recipe never creates a config cache or `public/hot`; both need hand-run commands outside the contract. `www-setup` has no equivalent step either.
- Multi-table migrations without DDL-failure recovery; concurrent first runs; no `DB_*` assertion before migrate: stock skeleton kept per "bare skeleton only"; the `.env` guard already proves DDEV wrote the DB keys.
- Skeleton identity metadata in `composer.json` (`laravel/laravel` name/description): cosmetic, no functional consumer.
- `laravel/framework ^13.17` vs Pest plugin ≥13.23 floors: installs use the lock (13.26.1); no consequence without a deliberate re-resolve.
- No `storage:link`: nothing uses the public disk yet.
- Bunny-fonts build-time fetch, `.npmrc` `ignore-scripts`, `.editorconfig root=true`, no `engines` in `package.json`, `php-http/discovery` allow-plugins, empty `favicon.ico`, welcome-view trailing whitespace, Pest stub helpers, commented `RefreshDatabase`, sqlite `:memory:` tests vs MariaDB runtime, no fixed test `APP_KEY`, seeder fixture credential/rerun, `api/*` JSON rule, untested `/up`, welcome fallback letting the 200 test pass pre-build: all stock skeleton or `pest --init` output kept by the frozen "bare skeleton only" / "app.css/Tailwind stay as-is" boundaries and the Design Notes' deliberate `:memory:` choice; verified no current consequence (tests, Pint, build all green).
- Root `README.md` ".gitkeep placeholders" sentence: still accurate — it describes the folders that remain empty and predates this story with `apps/www` already filled.
- `portal-setup` prints `Done` without an HTTP check: `set -euo pipefail` already fails the run on any step error; the 200 assertion lives in the spec Verification and the story-7 clean-room defer.
- Missing-`.env` matrix row absent from spec Verification commands; Verification lacks "observed results": fixes would edit the spec; the row was exercised and observed (exit 1 + restart message) in this build.
- No ddev-running guard: DDEV's own error names the fix; cosmetic.

## Design Notes

- `name: portal.woptimize` (not `additional_hostnames`) because the primary URL drives the `APP_URL` DDEV writes into `.env`; the alternative leaves `APP_URL=https://portal.ddev.site`.
- Pest 5 (not 4): own apps target PHP 8.4, Pest 5's floor. Its Laravel plugin needs framework ≥13.23; fresh create-project resolves the latest 13.x.
- Tests keep the skeleton's `phpunit.xml` env (sqlite `:memory:`): fast, no DB-container dependency. The running app uses MariaDB via DDEV's `.env` patch — local mirrors production.
- `portal-setup` is a committed host command mirroring `www-setup` — no `post-start` hooks; starts stay side-effect-free, the recipe stays explicit.

## Verification

**Commands:**

- `cd apps/portal && ddev start && ddev portal-setup` -- expected: exit 0.
- `curl -sko /dev/null -w '%{http_code}' https://portal.woptimize.ddev.site` -- expected: `200`.
- `ddev exec php artisan test` -- expected: Pest output, green. Then `ddev exec ./vendor/bin/pint --test` -- expected: exit 0.
- `ddev portal-setup` (second run) -- expected: exit 0, `APP_KEY` unchanged.
- `git status --porcelain` -- expected: no `vendor/`, `node_modules/`, `.env`, `public/build/` entries.

## Suggested Review Order

**Local dev recipe (entry point)**

- The whole fresh-clone recipe; mirrors `www-setup` — fail-loud guards, numbered idempotent sections
  [`portal-setup:17`](../../../../apps/portal/.ddev/commands/host/portal-setup#L17)

- `.env` guard: DDEV owns the file, so a missing one exits 1 with the restart instruction
  [`portal-setup:30`](../../../../apps/portal/.ddev/commands/host/portal-setup#L30)

- `key:generate` runs only when `APP_KEY` is empty — re-runs never rotate the key
  [`portal-setup:39`](../../../../apps/portal/.ddev/commands/host/portal-setup#L39)

- Migrations hit the MariaDB container via the `DB_*` keys DDEV patches at every start
  [`portal-setup:48`](../../../../apps/portal/.ddev/commands/host/portal-setup#L48)

**DDEV project shape**

- `name: portal.woptimize` drives the primary URL and therefore the `APP_URL` DDEV writes
  [`config.yaml:1`](../../../../apps/portal/.ddev/config.yaml#L1)

- Same provisional MariaDB 11.8 comment as www; PHP 8.4 and Node 24 pinned explicitly
  [`config.yaml:7`](../../../../apps/portal/.ddev/config.yaml#L7)

**Dependency contract**

- PHP floor raised to `^8.4` (review patch) so a `--no-dev` install cannot land on 8.3
  [`composer.json:9`](../../../../apps/portal/composer.json#L9)

- PHPUnit dropped from `require-dev`; Pest 5 + Laravel plugin take its place
  [`composer.json:20`](../../../../apps/portal/composer.json#L20)

- Skeleton `setup` script removed (review patch): it rivaled `portal-setup` and rotated `APP_KEY`
  [`composer.json:35`](../../../../apps/portal/composer.json#L35)

**Tests and style**

- `pest --init` output binds Feature tests to the Laravel `TestCase`
  [`Pest.php:17`](../../../../apps/portal/tests/Pest.php#L17)

- Tests stay on sqlite `:memory:` from the skeleton env — no DB-container dependency
  [`phpunit.xml:27`](../../../../apps/portal/phpunit.xml#L27)

- Both example tests converted to Pest syntax
  [`ExampleTest.php:3`](../../../../apps/portal/tests/Feature/ExampleTest.php#L3)

- Pint pinned to the `laravel` preset
  [`pint.json:2`](../../../../apps/portal/pint.json#L2)

**Docs and repo plumbing**

- Portal README: quickstart, the `.env` ownership rule, and the corrected "starting over" recipe
  [`README.md:26`](../../../../apps/portal/README.md#L26)

- Accurate idempotency description after the review patch
  [`README.md:51`](../../../../apps/portal/README.md#L51)

- LF rule generalized to every app's DDEV commands
  [`.gitattributes:6`](../../../../.gitattributes#L6)

- Root README gains the portal quickstart line
  [`README.md:32`](../../../../README.md#L32)
