---
title: 'Design-token pipeline'
type: 'feature'
created: '2026-08-24'
status: 'done'
review_loop_iteration: 0
context: []
baseline_commit: '144d2ab7492e89b06480c314b16053121b377f08'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `packages/design-tokens` is an empty `.gitkeep`; www and portal have no shared design language, so styles would drift into two dialects (CAP-2).

**Approach:** One DTCG JSON source (fonts, colors, typography, spacing, motion, buttons, links) plus one hand-written base-styles partial, built by Style Dictionary 5 into exactly the four AD-3 artifacts via root `npm run tokens:build`. Both apps consume the artifacts; both setup commands run the build first.

## Boundaries & Constraints

**Always:**

- Artifacts and paths are fixed by AD-3: `apps/www/themes/woptimize-theme/theme.json` (merged into committed `theme.base.json`), `…/woptimize-theme/assets/css/tokens.css`, `apps/portal/resources/css/tokens.theme.css`, `apps/portal/resources/css/tokens.base.css`. All four gitignored, never hand-edited.
- One command: root `package.json` script `tokens:build` runs on host Node ≥ 24 (`engines`, `.nvmrc` = `24`); it installs and builds `packages/design-tokens`. `www-setup` and `portal-setup` run it as their first section, before any asset compile.
- Source is DTCG (`$value`/`$type`); `$value` strings carry their CSS unit (`1rem`, `150ms`); references use `{group.path}` and resolve at build. No brand document exists — ship a small initial value set covering all seven groups.
- One custom-property vocabulary in both apps, following Tailwind 4 namespaces: `--color-*`, `--font-*`, `--text-*` + `--text-*--line-height`, `--font-weight-*`, `--spacing-*`, `--radius-*`, `--ease-*`; plus plain `--duration-*`, `--button-*`, `--link-*`.
- `tokens.theme.css` is one `@theme static { … }` block. `tokens.css` is `:root { … }` + base styles; `tokens.base.css` is the same base styles. Base styles live in `@layer base` so app CSS and utilities win.
- Token-owned `theme.json` keys are exactly `settings.color.palette`, `settings.typography.fontFamilies`, `settings.typography.fontSizes`, `settings.spacing.spacingSizes`; the theme owns every other key in `theme.base.json`.
- Output is byte-deterministic (no timestamps); every artifact starts with a "generated — do not edit" header.
- Stack pins: Style Dictionary `^5.5.2`; Tailwind stays at the portal's 4.3.x; theme.json `version: 3`.

**Never:**

- No npm workspaces, no root dependencies, no root `node_modules` in git; root `package.json` holds scripts only.
- No webfont files or `@font-face`; no `vite.config.js` or `tailwindcss` changes; no CI (stories 7–8).
- No components (Tomas's rule); no `styles.*` in `theme.json` from the build.
- No edits to `theme.json` consumers beyond the theme enqueue and the portal `app.css` imports.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Fresh build | root `npm run tokens:build` | Four artifacts written; exit 0 | N/A |
| Token edit | change `color.brand.600`, rebuild | `theme.json`, `tokens.css`, `tokens.theme.css` contain the new value; `tokens.base.css` reads it through `var(--color-primary)` | N/A |
| Re-run, no edits | second build | Artifacts byte-identical | N/A |
| Ownership clash | `theme.base.json` defines `settings.color.palette` | No artifact written for theme.json | exit 1, message names the key |
| Broken reference | `$value: "{color.nope}"` | Build stops | non-zero exit, SD names the token |
| Missing base | `theme.base.json` absent | Build stops | exit 1, names the path |
| No host npm | `ddev www-setup` without Node | Setup stops before WP core | exit 1: "Install Node 24 — see packages/design-tokens/README.md" |

</frozen-after-approval>

## Code Map

- `packages/design-tokens/.gitkeep` -- delete; the package fills the folder.
- `apps/www/themes/woptimize-theme/functions.php:31-39` -- `woptimize_theme_enqueue_assets()` enqueues only `style.css` (handle `woptimize-theme`); add the tokens enqueue before it. No `assets/` dir exists yet.
- `apps/www/.gitignore` -- one line `/wordpress/`; add the two built artifacts.
- `apps/www/.ddev/commands/host/www-setup:14` -- `APPROOT` guard; sections numbered from `:41`; fail-loud pattern at `:58-60`; helper `wait_for_container_path()` `:29`. Repo root = `$APPROOT/../..`.
- `apps/portal/.ddev/commands/host/portal-setup:22-55` -- sections 1–5; `ddev npm run build` at `:55`. Mutagen syncs host→container asynchronously (story 1 race) — flush before Vite.
- `apps/portal/resources/css/app.css:1-9` -- `@import 'tailwindcss'`, two `@source` lines, skeleton `@theme { --font-sans }` block (drop it; tokens own `--font-sans`).
- `apps/portal/.gitignore` -- Laravel defaults; add the two artifacts.
- `apps/portal/package-lock.json:1777` -- tailwindcss 4.3.3 installed; `@theme static` exists since 4.0.5.
- `apps/www/README.md:5-11`, `apps/portal/README.md:5-11` -- prerequisites claim "no local Node"; `:40`/`:51` "what setup produces"; root `README.md:26-35` Getting started.
- Verified 2026-08-24 (official docs): SD 5.5.2 is ESM-only, `usesDtcg` auto-detected, formats `({dictionary, options, file, platform})` may be async, `options.showFileHeader: false` removes the timestamped default header, `log.errors.brokenReferences` defaults to `throw`. Tailwind: only used `@theme` vars reach `:root` unless `@theme static`; no `--duration-*` namespace. theme.json: presets need `{slug, name, …}`; set `spacingScale.steps: 0` beside custom `spacingSizes`; classic themes read `theme.json` since 5.8. Host Node is v26 (≥ 24 OK); both DDEV projects running.

## Tasks & Acceptance

**Execution:**

- [x] `.nvmrc`, `package.json` (root) -- create -- `24`; `private`, `engines.node ">=24"`, scripts `tokens:build` (`npm --prefix packages/design-tokens install --no-audit --no-fund && npm --prefix packages/design-tokens run build`) and `tokens:test` (same, then `test`). Add `/node_modules/` to root `.gitignore`.
- [x] `packages/design-tokens/package.json`, `package-lock.json`, `.gitignore` -- create -- `type: module`, `style-dictionary ^5.5.2`, scripts `build` (`node build.js`), `test` (`node --test`); ignore `node_modules/`.
- [x] `packages/design-tokens/tokens/{font,color,typography,spacing,motion,button,link}.json` -- create -- DTCG source; semantic colors and button/link tokens reference primitives.
- [x] `packages/design-tokens/src/base.css` -- create -- `@layer base` styles for `html/body`, headings, `a`, `.wo-button`, using only the custom properties.
- [x] `packages/design-tokens/build.js` -- create -- exports `buildTokens({ repoRoot, tokensDir })`; runs it when executed directly. Custom `name/wo` transform + explicit transforms (`fontFamily/css`, `cubicBezier/css`); custom formats `css/root+base`, `tailwind/theme-static`, `css/base`, `wp/theme-json-merge` (reads `theme.base.json`, refuses owned keys, injects presets); `showFileHeader: false`, own static header.
- [x] `packages/design-tokens/test/build.test.js` -- create -- `node --test` over a temp repo-shaped dir: covers every I/O Matrix row.
- [x] `apps/www/themes/woptimize-theme/theme.base.json` -- create -- `version: 3`, `$schema`, `settings.appearanceTools: true`, `color.defaultPalette: false`, `spacing.spacingScale.steps: 0`; no token-owned keys.
- [x] `apps/www/themes/woptimize-theme/functions.php` -- edit -- enqueue `assets/css/tokens.css` (handle `woptimize-tokens`, `filemtime` version); make `woptimize-theme` depend on it.
- [x] `apps/www/.gitignore`, `apps/portal/.gitignore` -- edit -- ignore the four artifacts.
- [x] `apps/www/.ddev/commands/host/www-setup`, `apps/portal/.ddev/commands/host/portal-setup` -- edit -- new section `0. Design tokens`: `command -v npm` guard, then `(cd "$APPROOT/../.." && npm run tokens:build)`; portal adds `ddev mutagen sync` before `ddev npm run build`.
- [x] `apps/portal/resources/css/app.css` -- edit -- after `@import 'tailwindcss'` add `@import './tokens.theme.css'` and `@import './tokens.base.css'`; remove the skeleton `@theme` block.
- [x] `packages/design-tokens/README.md`, `apps/www/README.md`, `apps/portal/README.md`, `README.md` -- create/edit -- Node ≥ 24 prerequisite, artifact list, "never hand-edit", how to add a token, naming rule.

**Acceptance Criteria:**

- Given a fresh clone with Node ≥ 24, when `npm run tokens:build` runs at the root, then it exits 0 and the four artifacts exist, each starting with the generated header.
- Given built artifacts, when `ddev www-setup` re-runs, then `https://woptimize.ddev.site` serves 200 with `assets/css/tokens.css` linked, and `ddev wp eval` on `wp_get_global_settings(['color','palette','theme'])` lists the token colors.
- Given built artifacts, when `ddev portal-setup` re-runs, then `public/build/assets/app-*.css` contains `--color-primary` and `--button-radius`, and `ddev exec php artisan test` stays green.
- Given the repo after setup, when `git status --porcelain` runs, then no artifact, `node_modules`, or root lockfile appears.

## Spec Change Log

- 2026-08-24 — implementation — I/O Matrix row "Token edit" reads "All four
  artifacts contain the new value". That cannot hold for `tokens.base.css`: the
  frozen Boundaries say it is "the same base styles", and base styles reference
  custom properties rather than carrying literal values. The build follows the
  Boundaries; the test asserts the edit reaches the three value-carrying
  artifacts and that `tokens.base.css` picks it up through
  `var(--color-primary)`. No code change resolves the contradiction — a human
  should reword the matrix row if the wording matters.
- 2026-08-24 — implementation — theme.json cannot carry a comment, so the
  "generated — do not edit" header is the first key, `__generated`. WordPress
  drops unknown theme.json keys silently.
- 2026-08-24 — implementation — the build pre-validates `{group.path}`
  references itself instead of relying on Style Dictionary's throw. SD's
  `brokenReferences: throw` is still set, but its message only names the count
  unless `log.verbosity` is `verbose`; the matrix wants the token named. The
  local check names every offender and, like the theme.base.json checks, runs
  before any artifact is written.
- 2026-08-24 — matrix test audit — the "No host npm" row now has an automated
  test too: `test/build.test.js` runs both DDEV setup scripts under `/bin/bash`
  with an empty `PATH` and a throwaway `DDEV_APPROOT`, and asserts exit 1, the
  exact message, and empty stdout. The task line's "except No host npm" is
  superseded; the suite covers every matrix row.

## Review Triage Log

Seven layers ran (blind, edge, verification-gap, security, and Codex gpt-5.6 blind/edge/intent). Security: no findings.

**Patched (this story):**

- Host guard checks only `command -v npm`; Style Dictionary needs Node ≥ 22, so Node 20 fails opaquely inside ESM. Verified `engines` in SD 5.5.2. → guard now also checks the Node major ≥ 24 with the same message. (edge, blind, ext-blind)
- Setup-script test cannot observe the `npm run tokens:build` call — removing it keeps the test green. → stub `npm` on PATH records argv. (verification-gap)
- CLI and default `repoRoot` never executed by tests; exit code untested. → `WO_TOKENS_REPO_ROOT` env override, CLI spawn tests for exit 0 and exit 1. (verification-gap)
- `ARTIFACTS` vs the two `.gitignore` files can drift and commit a generated file. → test asserts each artifact is ignored. (blind)
- Two token paths can map to one custom-property name / preset slug and silently shadow. → duplicate-name check throws. (edge, blind, ext-blind, ext-edge)
- `base.css` `var(--x)` references are never checked against the token set. → build throws on an undefined property. (ext-blind)
- Reference pre-check skipped array `$value` (fontFamily, cubicBezier). → arrays are checked too. (edge)
- `theme.base.json` parsing to `null`/array/scalar passes validation. → must be a plain object. (edge, ext-edge)
- Empty token sources build empty artifacts successfully. → throw when no tokens. (edge, ext-edge, ext-blind)
- `__generated` can be overridden by `theme.base.json`. → header set after the spread. (ext-blind)
- Artifact existence never verified after `buildAllPlatforms`. → check each path. (edge)
- CLI catch hides stacks for non-`TokenBuildError`. → print the stack for those. (edge, blind)
- `img,picture,video,canvas,svg { display:block }` breaks inline images and `wp-smiley` in WP post content; `scroll-behavior` applied on `*`. → media rule dropped; reduced-motion keeps only animation/transition. (blind, ext-intent scope)
- `::selection` reads the primitive `--color-brand-100`. → new semantic `color.selection`. (blind)
- Emoji families dropped from the old portal `--font-sans`. → added to `font.sans`. (edge)
- `useRootPaddingAwareAlignments: true` inert in a classic theme with no `styles.*`. → removed. (blind, ext-intent scope)
- `portal-setup` swallows Mutagen failure; Vite can compile stale/missing tokens. → presence check with retry, fail loud. (edge, blind, ext-blind, ext-edge)
- Docs: task line "except No host npm" stale; root README says all four start with a notice (theme.json uses a key); package README "one file per group" vs mixed files. → wording fixed. (blind)

**Dismissed:**

- `package-lock.json` missing from the diff — the diff file excluded it on purpose (67 KB); it exists, is not ignored, and will be committed. (blind, verification-gap, security, ext-blind, ext-edge, ext-intent)
- `npm install` rewrites the lockfile / needs `npm ci` — verified: md5 identical before and after `npm install` with the lock present; the task line fixes `install`. (blind, edge, ext-blind, verification-gap)
- Determinism across machines — lockfile pins 5.5.2 with integrity hashes. (edge)
- Palette lists primitives — Design Notes fix "palette ← `color.*`"; fix edits the spec. (blind)
- `fontSizes` lack `lineHeight` — Design Notes fix "fontSizes ← `text.*.size`"; fix edits the spec. (blind)
- `@layer base` loses to unlayered WP CSS — intended: Boundaries say base styles must lose to app CSS. (blind)
- No `box-sizing` reset — `.wo-button` has no explicit width, so box-sizing does not change its rendered size; no consequence shown. (blind)
- Broken reference named by local check, not by SD — outcome identical; frozen matrix asks for a non-zero exit naming the token; already logged. (ext-intent)
- `__generated` is a fifth owned key — metadata, not a `settings` key; already logged. (ext-intent)
- "Token edit" row vs `tokens.base.css` — human confirmed the Boundaries reading; already logged. (ext-intent)
- `readThemeBase` runs twice — same file, same result; second read cannot fail differently in practice. (blind)
- `tokenName` vs preset slug for a future `spacing.*.size` — no such token exists; hypothetical. (blind)
- Nested token dirs / directories named `*.json`; symlinked argv; `filemtime` TOCTOU; concurrent build lock; `deepMerge` `__proto__`; duplicate paths across files (SD warns itself); schema validation of `theme.base.json` (WordPress ignores unknown keys); Tailwind compile test; `md5` macOS-only; `$schema` trunk; dark-mode dimension; `aria-disabled` still clickable (visual-only by design). (various) — no substantiated consequence at the named site.
- www-setup never flushes tokens to the container — nothing in setup reads them in the container; Mutagen syncs before the first request in practice (verified 200 with `tokens.css` linked). (blind, ext-blind)
- Lists lose markers in portal only — Tailwind Preflight behaviour predates this story. (ext-blind)
- Base-style and `theme.base.json` extras beyond the named seed — reduced (media rule, root-padding flag); remaining extras are theme-owned seed values the Boundaries allow. (ext-intent)

**Deferred (pre-existing, not this story):** see `deferred-work.md` — Tailwind default namespaces stay a second source; portal `welcome.blade.php` never loads `app.css`; dead Bunny webfont; no linter for `packages/design-tokens`; silent absence of built artifacts on deploy.

## Design Notes

- Host Node, not DDEV: each DDEV project mounts only its own app folder, but the build reads `packages/` and writes into both apps. The spine's literal `root npm run tokens:build` is the one invocation local and CI share; `AGENTS.md`'s "no local Node" TODO resolves this way.
- Naming rule (`name/wo`): `--` + path joined by `-`; drop a trailing `-size`; under `text`, `-line-height` → `--line-height`. So `text.base.size` → `--text-base`, `text.base.line-height` → `--text-base--line-height`, `color.brand.600` → `--color-brand-600`.
- Explicit transforms, not the `css` group: `size/rem` and `time/seconds` would rewrite unit-carrying strings.
- theme.json mapping: palette ← `color.*` (slug = sub-path kebab), fontFamilies ← `font.*`, fontSizes ← `text.*.size`, spacingSizes ← `spacing.*`; `name` = title-cased slug.
- `font.sans` is a system stack; the portal's Bunny `Instrument Sans` fetch in `vite.config.js` becomes unused but stays (portal-owned). Webfont delivery waits for a brand font.
- `@theme static` so `tokens.base.css` and unused tokens still resolve; Tailwind would otherwise drop vars no utility references.

## Verification

**Commands:**

- `npm run tokens:build && npm run tokens:build` -- expected: exit 0 both times; `git status --porcelain` unchanged between runs; `md5` of the four artifacts identical.
- `npm run tokens:test` -- expected: green.
- `cd apps/www && ddev www-setup && curl -sk https://woptimize.ddev.site | grep -c tokens.css` -- expected: exit 0; count ≥ 1.
- `cd apps/portal && ddev portal-setup && grep -l -- '--color-primary' public/build/assets/app-*.css` -- expected: one file; then `ddev exec php artisan test` green.

**Manual checks (if no CLI):**

- Edit `color.brand.600` in `tokens/color.json`, run `npm run tokens:build`, `grep` the new hex in `theme.json`, `tokens.css`, and `tokens.theme.css` (`tokens.base.css` carries `var(--color-primary)`, never a literal); then revert.

## Suggested Review Order

**Entry point — the build**

- One function, four artifacts; validates every input before a single write.
  [`build.js:493`](../../../../packages/design-tokens/build.js#L493)

- Explicit transforms, not the `css` group — unit-carrying strings stay untouched.
  [`build.js:519`](../../../../packages/design-tokens/build.js#L519)

- The one naming rule both apps share (`text.base.size` → `--text-base`).
  [`build.js:122`](../../../../packages/design-tokens/build.js#L122)

**theme.json ownership boundary**

- Refuses a `theme.base.json` that claims a token-owned key; names the key.
  [`build.js:165`](../../../../packages/design-tokens/build.js#L165)

- Merge format: notice first, theme half, then the four injected presets.
  [`build.js:264`](../../../../packages/design-tokens/build.js#L264)

- The theme's committed half — no token-owned keys, `spacingScale.steps: 0`.
  [`theme.base.json:29`](../../../../apps/www/themes/woptimize-theme/theme.base.json#L29)

**Guard rails added by review**

- Broken `{ref}` check names every offender, arrays included, before SD runs.
  [`build.js:372`](../../../../packages/design-tokens/build.js#L372)

- Two paths collapsing to one `--name` is an error, not a silent shadow.
  [`build.js:403`](../../../../packages/design-tokens/build.js#L403)

- Every `var(--x)` in `base.css` must exist in the token set.
  [`build.js:463`](../../../../packages/design-tokens/build.js#L463)

- CLI detection goes through `realpath` — a symlinked checkout used to exit 0 silently.
  [`build.js:590`](../../../../packages/design-tokens/build.js#L590)

**Token source and base styles**

- Semantic colours reference primitives; `color.selection` keeps base.css off the ramp.
  [`color.json:30`](../../../../packages/design-tokens/tokens/color.json#L30)

- Button tokens are pure references — the "no components" envelope.
  [`button.json:3`](../../../../packages/design-tokens/tokens/button.json#L3)

- `@layer base` so app CSS and utilities always win.
  [`base.css:1`](../../../../packages/design-tokens/src/base.css#L1)

- The single `.wo-button` class reads only custom properties.
  [`base.css:109`](../../../../packages/design-tokens/src/base.css#L109)

**App wiring**

- Portal Tailwind `@theme static` emitter — unused tokens still reach `:root`.
  [`build.js:257`](../../../../packages/design-tokens/build.js#L257)

- Portal imports right after Tailwind; skeleton `@theme` block gone.
  [`app.css:5`](../../../../apps/portal/resources/css/app.css#L5)

- www enqueues `woptimize-tokens`; `style.css` depends on it.
  [`functions.php:42`](../../../../apps/www/themes/woptimize-theme/functions.php#L42)

**Setup commands — host Node, section 0**

- npm + Node ≥ 24 guard, then `npm run tokens:build` from the repo root.
  [`www-setup:41`](../../../../apps/www/.ddev/commands/host/www-setup#L41)

- Same guard for the portal.
  [`portal-setup:37`](../../../../apps/portal/.ddev/commands/host/portal-setup#L37)

- Waits for both artifacts inside the container before Vite — Mutagen race, fail loud.
  [`portal-setup:42`](../../../../apps/portal/.ddev/commands/host/portal-setup#L42)

- Root scripts only — no workspaces, no root deps.
  [`package.json:10`](../../../../package.json#L10)

**Peripherals**

- 27 tests over a temp repo-shaped tree; every matrix row covered.
  [`build.test.js:119`](../../../../packages/design-tokens/test/build.test.js#L119)

- Setup scripts driven with stub `npm`/`node` on an empty PATH.
  [`build.test.js:3`](../../../../packages/design-tokens/test/build.test.js#L3)

- `ARTIFACTS` must stay gitignored — asserted against `git check-ignore`.
  [`build.test.js:543`](../../../../packages/design-tokens/test/build.test.js#L543)

- The four artifacts are ignored per app.
  [`.gitignore:5`](../../../../apps/www/.gitignore#L5)

- Naming rule and "what the build refuses" for the next person.
  [`README.md:81`](../../../../packages/design-tokens/README.md#L81)

- The "no local Node" TODO resolved — host Node is the one exception.
  [`AGENTS.md:54`](../../../../AGENTS.md#L54)
