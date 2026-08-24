<!-- bmad:context -->
<!-- Verified 2026-08-24 against 068b46f. Managed by bmad-project-context; edits inside this block are replaced on refresh. Keep anything you want preserved outside the markers. -->

## woptimize.io

Multi-app monorepo for WOptimize: a WordPress marketing site, a Laravel client
portal, a connector plugin for client sites, and one design-token source. Only
`apps/www` has code — the rest of the tree is `.gitkeep` placeholders a later
story fills. Planning artifacts live in `_bmad-output/`.

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

## Where things are

- Architecture invariants AD-1…AD-19, stack versions, structural seed:
  `_bmad-output/planning-artifacts/architecture/architecture-woptimize.io-2026-08-24/ARCHITECTURE-SPINE.md`
- Build contract — capabilities, constraints, non-goals:
  `_bmad-output/specs/spec-repo-structure/SPEC.md`
- Work parked for a later story:
  `_bmad-output/implementation-artifacts/deferred-work.md`
- WP marketing site: `apps/www/` — read `apps/www/README.md` before working there.

## Running and verifying

- No root `composer.json`, no npm or Composer workspaces. Every unit installs
  its own dependencies; there is no repo-wide install, build, or test command.
- `apps/www` setup is `ddev start` then `ddev www-setup`, run from `apps/www`.
  Nothing is set up by hand. Needs DDEV >= 1.25 and a Docker provider — no
  local PHP, Composer, or WordPress.
- After deleting `apps/www/wordpress/`, run `ddev restart` before
  `ddev www-setup`. DDEV writes `wordpress/wp-config.php` and `www-setup` does
  not, so `www-setup` stops with an error without the restart.
- The WordPress pin is `WP_VERSION` at the top of
  `apps/www/.ddev/commands/host/www-setup` and nowhere else.
- TODO — spine AD-3 specifies `npm run tokens:build` from a root
  `package.json` as the first build step everywhere. Neither exists yet.
  Verify the real invocation when the token story lands.

## Conventions that differ from defaults

- An app never imports another app's code. Share through `packages/` at build
  time, or the connector↔portal contract at runtime (AD-1, AD-2).
- Each app keeps its own framework's idioms and tooling. Nothing crosses the
  WordPress/Laravel border except tokens and the contract (AD-15).
- Connector code must run on PHP 8.1 and WordPress 6.7 — no PHP 8.2+ syntax —
  while the own apps target PHP 8.4.
- Change `packages/connector/openapi.yaml` before any contract code, both
  directions in the same PR (AD-4).
- WordPress PHP — theme, site plugin, connector — follows WordPress Coding
  Standards spacing, not PSR-12.
- In `apps/www/`: the DDEV project root must stay `apps/www`. Any other root
  puts the relative symlink targets outside the container mount.
- In `apps/www/`: the `wp-content` symlinks are relative and per item
  (`../../../themes/woptimize-theme`). Absolute targets break in the container.

<!-- /bmad:context -->
