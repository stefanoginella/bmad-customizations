<!-- bmad:context -->
<!-- Verified 2026-08-25 against 7d043cb. Managed by bmad-project-context; edits inside this block are replaced on refresh. Keep anything you want preserved outside the markers. -->

## woptimize.io

Multi-app monorepo for WOptimize: a WordPress marketing site, a Laravel client
portal, a connector plugin for client sites, and one design-token source.
`apps/www`, `apps/portal`, `packages/design-tokens`, and `packages/connector`
have code — the rest of the tree is `.gitkeep` placeholders a later story fills.
Planning artifacts live in `_bmad-output/`.

## Policy

- Read the architecture spine before any cross-cutting change. It is the
  tie-breaker, and it supersedes `SPEC.md` where they state the same fact.
- Update `_bmad-output/` only through the BMad skill that owns the file. Never
  hand-edit `SPEC.md` or the spine to match drifted code — change the code, or
  run `bmad-correct-course`.
- Never edit `_bmad/` — installed skill code, gitignored except `_bmad/custom/`.
- Never commit anything under `apps/www/wordpress/`, and never commit the
  `wp-content` symlinks. `ddev www-setup` creates them.
- Never rename the fixed slugs: theme `woptimize-theme`, site plugin
  `woptimize-core`, connector `woptimize-connector`. They are wired into the
  database, the server, and the deploy symlink flip.
- Never hand-edit the four generated style artifacts (AD-3) — they are
  gitignored and rebuilt. Change `packages/design-tokens/tokens/` or
  `src/base.css`, then rebuild:
  `apps/www/themes/woptimize-theme/theme.json`,
  `apps/www/themes/woptimize-theme/assets/css/tokens.css`,
  `apps/portal/resources/css/tokens.theme.css`,
  `apps/portal/resources/css/tokens.base.css`.
- Only the portal issues a site key (AD-16), through
  `php artisan site:onboard`. Store it as a SHA-256 hash, show it once when it
  is issued, and never log or print it — `site:list` never prints a key.
- Keep `WOPTIMIZE_ALLOW_PRIVATE_REST_BASE` false in production. It switches off
  the guard that refuses a `rest_base` resolving to loopback or a private range;
  `.env.example` sets it true for DDEV alone, where `*.ddev.site` is 127.0.0.1.

## Where things are

- Architecture invariants AD-1…AD-20, stack versions, structural seed:
  `_bmad-output/planning-artifacts/architecture/architecture-woptimize.io-2026-08-24/ARCHITECTURE-SPINE.md`
- Build contract — capabilities, constraints, non-goals:
  `_bmad-output/specs/spec-repo-structure/SPEC.md`
- Work parked for a later story:
  `_bmad-output/implementation-artifacts/deferred-work.md`
- WP marketing site: `apps/www/` — read `apps/www/README.md` before working there.
- Laravel client portal: `apps/portal/` — read `apps/portal/README.md` before
  working there.
- Design tokens: `packages/design-tokens/` — read
  `packages/design-tokens/README.md` before touching any colour, size, radius,
  duration, or `theme.json` key.
- Connector plugin and the contract: `packages/connector/` — read
  `packages/connector/README.md` before touching the plugin, `openapi.yaml`, or
  anything the portal calls.

## Running and verifying

- No root `composer.json`, no npm or Composer workspaces, no root dependencies
  and no root lockfile. Every unit installs its own dependencies. The root
  `package.json` holds scripts only: `tokens:build` and `tokens:test`.
- Every DDEV app needs DDEV >= 1.25 and a Docker provider. Every app command goes
  through `ddev` — no local PHP, Composer, or WordPress.
- The one exception is the token build: it needs **host** Node >= 24 (`.nvmrc` =
  `24`) and runs as `npm run tokens:build` from the repo root. It cannot run in
  either container — a DDEV project mounts its own app folder, while the build
  reads `packages/` and writes into both apps. `www-setup` and `portal-setup`
  run it as their first step and stop with an error when host `npm` is missing.
- One mount crosses that line: `apps/portal/.ddev/docker-compose.contract.yaml`
  binds `packages/connector/openapi.yaml` read-only at
  `/mnt/woptimize/openapi.yaml` so the portal's `ContractTest` can parse it.
  That test fails — it never skips — when the path is unreadable. It is a
  single-file bind mount, so an editor that renames a temp file over
  `openapi.yaml` leaves the container on the old inode: run `ddev restart` after
  editing the contract, before trusting what `ContractTest` says.
- `apps/www` setup is `ddev start` then `ddev www-setup`, run from `apps/www`.
  Nothing is set up by hand.
- `apps/portal` setup is `ddev start` then `ddev portal-setup`, run from
  `apps/portal`. Nothing is set up by hand.
- `packages/connector` is a third DDEV project — `type: php`, PHP 8.1, no
  database, no docroot. Setup is `ddev start` then `ddev composer install`, run
  from `packages/connector`. Verify with `ddev composer test` (PHPUnit + Brain
  Monkey) and `ddev composer lint` (WPCS + PHPCompatibilityWP); both must exit
  0. It exists only to run the tooling at the client floor — real-WordPress
  integration tests belong on `apps/playground` (story 6).
- After deleting `apps/www/wordpress/`, run `ddev restart` before
  `ddev www-setup`. DDEV writes `wordpress/wp-config.php` and `www-setup` does
  not, so `www-setup` stops with an error without the restart.
- In `apps/portal/`: `.env` is DDEV's file. DDEV creates it from `.env.example`
  when it is absent, and patches `APP_URL` and `DB_*` into it at every start.
  When `.env` is missing, run `ddev restart` before `ddev portal-setup` — the
  command stops with an error. Never write those keys from a script.
- The WordPress pin is `WP_VERSION` at the top of
  `apps/www/.ddev/commands/host/www-setup` and nowhere else.
- Token changes: edit `packages/design-tokens/tokens/*.json` (DTCG — `$value`
  strings carry their own CSS unit, references are `{group.path}`), then
  `npm run tokens:build` and `npm run tokens:test` from the repo root.
- `theme.json` has two owners. The theme owns the committed
  `apps/www/themes/woptimize-theme/theme.base.json`; the token build owns
  `settings.color.palette`, `settings.typography.fontFamilies`,
  `settings.typography.fontSizes`, and `settings.spacing.spacingSizes`. Putting
  one of the four in `theme.base.json` fails the build on purpose.

## Conventions that differ from defaults

- An app never imports another app's code. Share through `packages/` at build
  time, or the connector↔portal contract at runtime (AD-1, AD-2).
- The admin dashboard is a surface of `apps/portal`, never a new app under
  `apps/` (AD-20). `admin.woptimize.io`, and `admin.woptimize.ddev.site`
  locally, serve the same codebase, database, release, and deploy job as the
  client portal. Split routes by domain into separate route files, reading
  `ADMIN_DOMAIN` from config — never a literal, and never null, because
  `Route::domain(null)` matches every host. The admin gets no CI workflow,
  path filter, or secret prefix of its own: `portal.yml` and `PORTAL_*` cover
  both surfaces.
- Admin identity is its own `admin_users` table with its own Laravel guard,
  never a role flag on the client `users` table (AD-20).
- Each app keeps its own framework's idioms and tooling. Nothing crosses the
  WordPress/Laravel border except tokens and the contract (AD-15).
- Connector code must run on PHP 8.1 and WordPress 6.7 — no PHP 8.2+ syntax —
  while the own apps target PHP 8.4.
- The connector↔portal contract lives at `packages/connector/openapi.yaml`.
  Write or change it before any contract code, both directions in the same PR
  (AD-4). It is written 3.1 but kept 3.0.3-portable — no `nullable`, no type
  arrays, no `webhooks` — so story 6 can flip one line. `ContractTest` fails
  when the file and the plugin disagree.
- The portal hosts `POST /api/connector/v1/phone-home`, and that route carries
  no throttle on purpose. AD-7 makes any 4xx permanent-quiet until the next
  daily slot, so a 429 from a shared client IP silences an honest site for a
  day. Never add rate limiting to a connector route.
- In `apps/portal/`: contract facts live once, in `app/Connector/Contract.php`.
  `ContractTest` ties the two header names, the path prefix, the `SiteReport`
  required lists, and the response statuses to `openapi.yaml`. The key length,
  the key pattern, and the timeout are portal facts the contract carries only as
  prose — no test catches drift there, so keep those three in step by hand.
- In `packages/connector/`: the folder contents **are** the plugin folder
  (AD-17); `.distignore` lists what the release zip drops. The plugin has no
  Composer runtime dependencies. Every remote failure is a silent no-op (AD-7):
  no key → no request; any 4xx → quiet until the next daily slot, never a
  tighter schedule; 5xx or transport error → one retry 15 min later that never
  reschedules. The plugin never generates a site key — the portal issues it
  (AD-16).
- WordPress PHP — theme, site plugin, connector — follows WordPress Coding
  Standards spacing, not PSR-12.
- In `apps/www/`: the DDEV project root must stay `apps/www`. Any other root
  puts the relative symlink targets outside the container mount.
- In `apps/www/`: the `wp-content` symlinks are relative and per item
  (`../../../themes/woptimize-theme`). Absolute targets break in the container.
- In `apps/portal/`: tests are Pest 5, not the skeleton's PHPUnit. Write Pest
  syntax; run `ddev exec php artisan test`.
- In `packages/design-tokens/`: ESM only (`type: module`), Style Dictionary 5,
  tests are `node --test`. Custom-property names follow one rule — `--` plus the
  token path joined by `-`, a whole trailing `size` segment dropped, and
  `text.*.line-height` becoming `--text-*--line-height`.

<!-- /bmad:context -->
