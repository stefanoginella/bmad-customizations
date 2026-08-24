# Deferred Work

- source_spec: `_bmad-output/specs/spec-repo-structure/stories/1-monorepo-skeleton-and-www-local-dev.md`
  summary: Add an automated clean-room check in the future www CI workflow (`www.yml`) that runs the fresh-clone recipe (`ddev delete -Oy`, remove `wordpress/`, `ddev start`, `ddev www-setup`), asserts `woptimize-theme` and `woptimize-core` are active, and asserts `git check-ignore -q apps/www/wordpress/wp-load.php`. It must land in story 7.
  evidence: Review found the Mutagen-race fix and the `/wordpress/` ignore rule are verified only by one-time manual runs; a regression would land on the next person who clones. The spec's Never list excludes CI workflows from story 1.

- source_spec: `_bmad-output/specs/spec-repo-structure/stories/2-portal-scaffold.md`
  summary: Add an automated clean-room check in the future portal CI workflow (`portal.yml`, story 7) that runs the fresh-clone recipe (`ddev delete -Oy`, `ddev start`, `ddev portal-setup`, then `ddev portal-setup` again), asserts both runs exit 0 with `APP_KEY` byte-identical, asserts the missing-`.env` case exits 1 with the restart instruction, and asserts `git check-attr eol -- apps/portal/.ddev/commands/host/portal-setup` reports `lf` on a fresh checkout.
  evidence: Review found the portal-setup idempotence/`.env` guards and the root `.gitattributes` LF rule are verified only by one-time manual runs; locally the LF rule is shadowed by a DDEV-generated untracked `.ddev/commands/.gitattributes`, so only a fresh-clone CI job can observe it. Story 2's Never list excludes CI, and story 1's existing entry names only `www.yml`.

- source_spec: `_bmad-output/specs/spec-repo-structure/stories/2-portal-scaffold.md`
  summary: Decide the portal's public-surface policy before story 8 flips the deploy — `public/robots.txt` currently allows all indexing of a private client portal, and the skeleton's welcome page and unauthenticated `/up` health endpoint would go live on `portal.woptimize.io` as-is.
  evidence: Review flagged all three; they are skeleton defaults with zero local consequence, and the spec assigns production hostname and deploy exclusively to story 8.

- source_spec: `_bmad-output/specs/spec-repo-structure/stories/3-design-token-pipeline.md`
  summary: Decide whether the portal resets Tailwind 4's default namespaces (`--color-*: initial; --spacing: initial; --text-*: initial; …` at the head of `@theme static`) so `bg-red-500`, `p-5`, `text-7xl` stop working off the token scale — or record that the defaults stay on purpose.
  evidence: Review found `--color-red-500` and `--spacing:0.25rem` in the compiled `apps/portal/public/build/assets/app-*.css`; the token artifacts add to `@theme` rather than replace it. The story's Never list forbids `tailwindcss` changes and the spec does not name a reset, so this is a design decision, not a defect of the build.

- source_spec: `_bmad-output/specs/spec-repo-structure/stories/3-design-token-pipeline.md`
  summary: The portal's only page, `resources/views/welcome.blade.php`, carries the skeleton's inlined Tailwind 4.0.7 build and never loads `app.css`, so no rendered portal page consumes the tokens yet; replace the skeleton page (and drop the Bunny `Instrument Sans` fetch in `vite.config.js`, now referenced by nothing) when the first real portal view lands (story 5).
  evidence: Review grepped the portal — the compiled bundle contains `--color-primary`, but `welcome.blade.php` is Laravel's skeleton page; the story's Never list limits portal edits to the `app.css` imports and the Design Notes keep the webfont as portal-owned.

- source_spec: `_bmad-output/specs/spec-repo-structure/stories/3-design-token-pipeline.md`
  summary: Make a missing token build loud on the www side — `theme.json` and `assets/css/tokens.css` are gitignored, and `functions.php` silently skips the enqueue when `tokens.css` is absent; the www deploy pipeline (story 7) must run `npm run tokens:build` before the symlink flip, and a `WP_DEBUG` admin notice for the missing file is worth three lines then.
  evidence: Review traced the `file_exists` guard: a deploy that skips the build serves a theme with no palette and no tokens and no error anywhere. The Never list excludes CI from story 3.

- source_spec: `_bmad-output/specs/spec-repo-structure/stories/3-design-token-pipeline.md`
  summary: Add a lint/format script to `packages/design-tokens` (the portal has Pint, www follows WPCS; the token package enforces nothing).
  evidence: Review noted the 4-space JS/JSON style has no tool behind it; AGENTS.md says each unit keeps its own tooling. Cosmetic today, drift later.

- source_spec: `_bmad-output/specs/spec-repo-structure/stories/4-connector-plugin-and-contract-file.md`
  summary: AGENTS.md "Running and verifying" still opens with "Both DDEV apps need DDEV >= 1.25" and "Every app command goes through `ddev`" now that `packages/connector` is a third DDEV project; reword through `bmad-project-context` (the lines sit inside the managed block).
  evidence: Review found the wording drift; the block is owned by the project-context skill, so the build must not patch it.
