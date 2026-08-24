---
title: 'Monorepo skeleton and www local dev'
type: 'feature'
created: '2026-08-24'
status: 'done'
review_loop_iteration: 0
baseline_commit: 'eaedc72da4ac1b89a638547893c4f7e2b145170d'
context:
  - '{project-root}/_bmad-output/specs/spec-repo-structure/repo-layout.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The repo holds only planning artifacts. No monorepo tree exists, and there is no local dev environment for the WP marketing site (CAP-1, www half).

**Approach:** Create the full directory tree per `repo-layout.md`, then make `apps/www` a working DDEV project: minimal committed theme (`woptimize-theme`) and site plugin (`woptimize-core`), gitignored WP install in `wordpress/`, and a committed `ddev www-setup` command that downloads WP, creates the relative per-item symlinks, installs the site, and activates theme + plugin.

## Boundaries & Constraints

**Always:**

- DDEV project root is `apps/www`, `docroot: wordpress`, project name `woptimize` (→ `https://woptimize.ddev.site`, per the spine's Hostnames convention). Production hostname is `www.woptimize.io` — recorded for stories 7/10, unused here.
- Symlinks are per-item and **relative**: `wordpress/wp-content/themes/woptimize-theme -> ../../../themes/woptimize-theme`, same pattern for `plugins/woptimize-core`. Created by setup, never committed.
- Slugs fixed everywhere: theme `woptimize-theme`, plugin `woptimize-core` (AD-9).
- Stack pins: PHP 8.4; WordPress 7.1 pinned in exactly one place in the setup command; MariaDB 11.8 explicit in DDEV config — provisional until RunCloud provisioning (story 7) fixes the real major, then DDEV mirrors it.
- In git, `apps/www` carries only `themes/`, `plugins/`, `.ddev/`, docs; the whole `wordpress/` install is gitignored.
- Unfilled skeleton dirs from `repo-layout.md` are tracked via `.gitkeep` only.

**Never:**

- No portal/playground scaffolding, token pipeline, CI workflows, or content-sync provider — those are later stories.
- No `theme.base.json` / `theme.json` — story 3 owns the token artifacts (AD-3).
- No hand-created state: fresh clone + `ddev start` + `ddev www-setup` must be the whole recipe.
- No composer/npm workspaces, no root composer.json (spine conventions).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Fresh clone | `ddev start` + `ddev www-setup` | WP 7.1 downloaded, symlinks created, site installed, theme + plugin active, site serves 200 | N/A |
| Re-run setup | `ddev www-setup` on an installed project | Idempotent: skips download + install, re-asserts symlinks and activation, exits 0 | No destructive re-install |
| Partial state | WP downloaded but not installed | Setup detects via `wp core is-installed`, completes remaining steps | N/A |
| Symlink exists | Symlink already present | Re-pointed silently (`ln -sfn`) | N/A |

</frozen-after-approval>

## Code Map

- `_bmad-output/specs/spec-repo-structure/repo-layout.md` -- canonical tree; the spine's Structural Seed adds per-path notes and exact symlink targets.
- `.gitignore` -- root; OS + BMad entries only. Leave as is; www ignores go per-app.
- Repo is otherwise empty (single commit); nothing to reuse or protect.
- Verified locally: DDEV v1.25.3, default DB image mariadb-11.8 — matches the pin.

## Tasks & Acceptance

**Execution:**

- [x] `apps/portal/.gitkeep`, `apps/playground/.gitkeep`, `packages/design-tokens/.gitkeep`, `packages/connector/.gitkeep`, `infra/umami/.gitkeep`, `.github/workflows/.gitkeep` -- create -- track the full `repo-layout.md` tree in git before any app story fills it.
- [x] `apps/www/.gitignore` -- create with `/wordpress/` -- the full WP install stays out of git.
- [x] `apps/www/.ddev/config.yaml` -- create -- `name: woptimize`, `type: wordpress`, `docroot: wordpress`, `php_version: "8.4"`, `database: mariadb:11.8` (comment: provisional, mirror RunCloud at provisioning).
- [x] `apps/www/themes/woptimize-theme/` (`style.css`, `index.php`, `functions.php`) -- create -- minimal activatable classic theme; header slug `woptimize-theme`; no token artifacts.
- [x] `apps/www/plugins/woptimize-core/woptimize-core.php` -- create -- minimal valid plugin header, no-op body.
- [x] `apps/www/.ddev/commands/host/www-setup` -- create -- idempotent setup: download WP (version pinned here, default 7.1) if absent → create both relative symlinks → `wp core install` if not installed (local-only admin creds) → activate `woptimize-theme` + `woptimize-core`.
- [x] `apps/www/README.md` -- create -- the two-command quickstart and what setup produces.
- [x] `README.md` -- create -- one-screen repo map per `repo-layout.md`, pointer to per-app READMEs.

**Acceptance Criteria:**

- Given a fresh clone, when the developer runs `ddev start` then `ddev www-setup` in `apps/www`, then `https://woptimize.ddev.site` serves HTTP 200 with `woptimize-theme` and `woptimize-core` active.
- Given the finished tree, when compared against `repo-layout.md`, then every listed path exists in git.
- Given the installed project, when running `git status`, then nothing under `apps/www/wordpress/` appears (ignored), and both wp-content symlinks resolve relative (`../../../…`).
- Given a second `ddev www-setup` run, when it completes, then it exits 0 without re-downloading or re-installing WP.

## Spec Change Log

## Review Triage Log

**Round 1 (2026-08-24)** — layers: blind-hunter, edge-case-hunter, verification-gap. Kept 6 root-cause entries, all `patch`; 2 `defer` (see `../../../implementation-artifacts/deferred-work.md`); no intent_gap, no bad_spec.

Kept (patch): weak core-presence guard (pin bump silently no-ops, pin never asserted); broken recovery path (`wordpress/` delete removes `wp-config.php`, setup then dies mid-flow); unverified Mutagen sync flush (real failure resurfaces as misleading activation error); missing `Update URI` header (wp.org same-slug update hijack); missing prerequisites in README; missing `.gitattributes` (CRLF breaks the bash command on Windows checkouts).

Resolved (round 1): all 6 patch findings applied and re-verified live — version-aware core guard (drift 6.9 → 7.1 exercised for real), `wp-config.php` guard (exit 1 with the restart instruction, exercised), container-side symlink assertion with bounded retry, `Update URI` on both headers (confirmed via WP's own parsers), README prerequisites + corrected recovery path (`rm -rf wordpress` → `ddev restart` → `ddev www-setup`, exercised end to end), root `.gitattributes`.

Dismissed:

- Second `www-setup` run may exit nonzero on already-active theme/plugin — refuted by a real re-run: wp-cli warns and exits 0.
- `Tested up to: 7.1` is a second WP pin — descriptive theme metadata; it controls nothing the setup installs. Cosmetic.
- Missing LICENSE — private, undistributed repo; header licenses follow WP convention; repo licensing is a business decision with no current consumer impact.
- No phpcs.xml / .editorconfig / lint step — tooling and CI are excluded from this story by the spec's Never list.
- No `load_theme_textdomain`, no screenshot.png/Tags, no silence-is-golden index.php, no pagination, `gmdate('Y')` UTC year — the theme is a placeholder shell by design (story 3 builds the real theme); cosmetic for the local-dev consumer.
- Root README duplicates `repo-layout.md` / map root named `woptimize/` — the spec task mandates the map "per repo-layout.md"; the tree matches the canonical file verbatim.
- `webserver_type: nginx-fpm` not in spec — explicit DDEV default; no behavioral consequence. Same for omitted `performance_mode`/`upload_dirs`.
- `curl -k` hides TLS failures; AC2 has no executable comparison — both fixes would edit this build's spec; dismissed per triage rule.
- Spine edits (AD-18 hostname, Hostnames row, umami bullet) flagged as out-of-scope — pre-existing uncommitted human edits, present before this build's baseline; not made by this change.
- `.ddev/.gitignore` missing between clone and first start — refuted: DDEV writes that file in the same `ddev start` that generates the files it covers.
- Hardcoded `admin`/`admin`, printed to stdout — spec-sanctioned local-only credentials; `DDEV_APPROOT` guard restricts the script to a DDEV context.
- Real directory at a symlink path gets silently nested — refuted: `ln -sfn` fails loudly on a real directory, and that state requires hand-created setup the spec excludes.
- `www-setup` before `ddev start` fails opaquely — refuted: DDEV emits a clear "project not running, run ddev start" error.
- Project rename leaves stale siteurl; hardcoded `SITE_URL` fallback; `admin_email` host coupling — project name is spec-pinned, and DDEV always sets `DDEV_PRIMARY_URL` for host commands.
- `WP_VERSION` env override lets developers diverge — spec-sanctioned ("default 7.1").
- Interrupted download leaving a matching `version.php` over a broken tree — rare residual after the guard fix; the repaired recovery path handles it; low.

## Design Notes

- Setup is a committed DDEV **host** command (`ddev www-setup`), mirroring the spine's `ddev playground-setup` pattern (AD-18): host-side `ln -sfn` for symlinks, `ddev wp …` for WP operations. Order: download (needs a bare dir) → symlinks → install → activate.
- From `wordpress/wp-content/themes/`, `../../../` resolves to `apps/www/`; absolute targets break inside the container mount.
- DDEV self-manages `.ddev/.gitignore`; commit `config.yaml` and `commands/` only.
- Found during implementation: on macOS/Windows DDEV syncs host→container with Mutagen, asynchronously. On a fresh run the host-side `ln -sfn` had not reached the container when `wp theme activate` ran, and setup failed. The setup command therefore flushes with `ddev mutagen sync` right after creating the symlinks (a no-op on plain bind-mount projects).
- `ddev start` creates the docroot, so `wordpress/` exists on both sides before `ddev www-setup` runs — `wp core download` has a valid working directory.
- `wp-config.php` lives inside the gitignored `wordpress/` and is written by DDEV at start/restart, not by `www-setup`. Deleting `wordpress/` therefore takes it too, and the recovery recipe is `rm -rf wordpress` → `ddev restart` → `ddev www-setup`. The command asserts the file and stops with that instruction rather than failing deep inside wp-cli.
- The core guard compares the pin against `$wp_version` parsed from `wordpress/wp-includes/version.php`, so bumping the pin also moves warm checkouts (`wp core update --force`). A presence-only check would let the pin drift unnoticed.
- DDEV ships `.ddev/commands/.gitattributes` (`* -text eol=lf`) but its own `.ddev/.gitignore` excludes it, so it never reaches a fresh clone. The root `.gitattributes` is what actually keeps the bash command LF on a Windows checkout; verified with `git check-attr` against a clone-equivalent state.

## Verification

**Commands:**

- `cd apps/www && ddev start && ddev www-setup` -- expected: exit 0; theme + plugin active per `ddev wp theme|plugin list --status=active`.
- `curl -sko /dev/null -w '%{http_code}' https://woptimize.ddev.site` -- expected: `200`.
- `readlink wordpress/wp-content/themes/woptimize-theme` -- expected: `../../../themes/woptimize-theme` (same for the plugin).
- `git check-ignore -q apps/www/wordpress/wp-load.php` -- expected: exit 0.
- `ddev www-setup` (second run) -- expected: exit 0, no re-download/re-install.

## Suggested Review Order

**Setup command — the heart of the story**

- The single WordPress pin; everything below enforces it.
  [`www-setup:12`](../../../../apps/www/.ddev/commands/host/www-setup#L12)

- Version-aware core guard: download when absent, skip at pin, move drifted core to the pin.
  [`www-setup:42`](../../../../apps/www/.ddev/commands/host/www-setup#L42)

- Fail-loud `wp-config.php` guard; makes the documented recovery path honest.
  [`www-setup:57`](../../../../apps/www/.ddev/commands/host/www-setup#L57)

- Relative per-item symlinks; the only layout that survives the container mount.
  [`www-setup:68`](../../../../apps/www/.ddev/commands/host/www-setup#L68)

- Mutagen flush verified container-side with bounded retry — the fresh-clone race fix.
  [`www-setup:72`](../../../../apps/www/.ddev/commands/host/www-setup#L72)

- Idempotent install + activation; re-runs exit 0 with no destructive work.
  [`www-setup:83`](../../../../apps/www/.ddev/commands/host/www-setup#L83)

**DDEV project shape**

- Project name fixes the local hostname; docroot is the gitignored install; PHP + MariaDB pins.
  [`config.yaml:1`](../../../../apps/www/.ddev/config.yaml#L1)

- One line keeps the whole WP install out of git.
  [`.gitignore:1`](../../../../apps/www/.gitignore#L1)

**Committed theme + plugin**

- Theme identity header; slug comes from the directory; `Update URI` blocks wp.org hijack.
  [`style.css:1`](../../../../apps/www/themes/woptimize-theme/style.css#L1)

- Minimal theme supports + stylesheet enqueue; no token artifacts (story 3 owns those).
  [`functions.php:15`](../../../../apps/www/themes/woptimize-theme/functions.php#L15)

- Self-contained placeholder template; real templates arrive with the theme build.
  [`index.php:1`](../../../../apps/www/themes/woptimize-theme/index.php#L1)

- Valid no-op plugin header with `Update URI: false`.
  [`woptimize-core.php:1`](../../../../apps/www/plugins/woptimize-core/woptimize-core.php#L1)

**Docs and repo hygiene**

- Prerequisites, two-command quickstart, and the corrected restart-first recovery path.
  [`www/README.md:27`](../../../../apps/www/README.md#L27)

- One-screen repo map per `repo-layout.md`; skeleton dirs held by `.gitkeep`.
  [`README.md:1`](../../../../README.md#L1)

- LF forced for the committed DDEV commands; Windows checkouts stay runnable.
  [`.gitattributes:6`](../../../../.gitattributes#L6)
