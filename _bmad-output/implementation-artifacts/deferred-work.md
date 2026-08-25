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

- source_spec: `_bmad-output/specs/spec-repo-structure/stories/5-portal-contract-side.md`
  summary: Configure `trustProxies()` in `apps/portal/bootstrap/app.php` when the portal deploy (story 8) fixes the RunCloud/CDN topology — the refused-key warning throttles on `Request::ip()`, which collapses to the proxy address behind a load balancer.
  evidence: Review grepped `apps/portal/{app,bootstrap,config}` and `.env.example` for `trustProxies|TRUSTED_PROXIES` — nothing. The two per-IP tests set `REMOTE_ADDR` directly, so they cannot show it. The spec's Never list keeps deploy config out of story 5.

- source_spec: `_bmad-output/specs/spec-repo-structure/stories/5-portal-contract-side.md`
  summary: The playground setup (story 6) must make WordPress trust DDEV's mkcert root, or set `sslverify` off for `*.ddev.site` — otherwise the connector's `wp_remote_post` to `portal.woptimize.ddev.site` fails TLS and the phone-home records `transport_error`.
  evidence: The story-5 smoke on www needed a throwaway mu-plugin (`zz-ddev-ca.php`, deleted afterwards) to reach the local portal; WordPress ships its own CA bundle. Story 6's integration suite runs both venues and will hit the same wall.

- source_spec: `_bmad-output/specs/spec-repo-structure/stories/5-portal-contract-side.md`
  summary: Pin the outbound connector call to the address `PublicHost` checked — resolve once, then connect through `CURLOPT_RESOLVE` (Guzzle `curl` options) with the original Host and SNI — so a DNS answer cannot change between the check and the connect.
  evidence: Security review flagged the TOCTOU on `PublicHost`. `ConnectorClient::get()` now re-runs the check right before each call, which closes the days-long window between phone-home and `site:status`; the milliseconds between that check and the TCP connect remain open. Full pinning needs cURL options and SNI care the story did not scope.

- source_spec: `_bmad-output/specs/spec-repo-structure/stories/6-playground-and-integration-suite.md`
  summary: Run `.github/workflows/contract-suite.yml` for real once a git remote exists — the first push, or story 8's deploy work, whichever lands first. Watch for the three things a local run cannot show: `ddev/github-action-setup-ddev@v1` bringing up two DDEV projects on one `ubuntu-latest` runner, inter-project HTTPS by hostname through the CI router (the suite's `wp_remote_post` needs `/mnt/ddev-global-cache/mkcert/rootCA.pem` to exist there), and the wall-clock cost of `ddev portal-setup` plus `ddev playground-setup` in the same job.
  evidence: The repo has no git remote, and the story's Never list forbids pushing to GitHub. The workflow was verified by `actionlint` (exit 0) and by running its one real step, `ddev contract-suite all`, on the host — nothing has ever executed the checkout/setup-node/setup-ddev steps.

- source_spec: `_bmad-output/specs/spec-repo-structure/stories/6-playground-and-integration-suite.md`
  summary: Update the architecture spine for the contract version: the Stack table row (`| OpenAPI | 3.1 |`) and the AD-4 rule text ("`packages/connector/openapi.yaml` (OpenAPI 3.1)") both still read 3.1, while the file now declares `3.0.3`. The Deferred entry that pre-authorized the flip ("if no maintained 3.1 validator fits, write the contract 3.0.3-compatible instead — a build-story call") is now answered and can be struck. Run `bmad-correct-course` — the spine is never hand-edited.
  evidence: `ARCHITECTURE-SPINE.md:71,205,280`. `packages/connector/tests/ContractTest.php` now asserts `3.0.3` — the portal's `ContractTest` asserts no version at all, so the connector's is the only test holding the file to it — and the playground suite validates live responses against the file with `league/openapi-psr7-validator`, which the spine itself names as the 3.0.x-only risk. The story's Never list forbids editing the spine.

- source_spec: `_bmad-output/specs/spec-repo-structure/stories/6-playground-and-integration-suite.md`
  summary: Decide what a client site with plain permalinks should get. `Site_Report::build()` reports `rest_base = rest_url('woptimize/v1')`, which is `https://site/?rest_route=/woptimize/v1` under the plain structure; `PhoneHomeRequest` refuses a `rest_base` carrying a query string, so such a site phones home into a permanent `422` and never appears in the registry — silently, because AD-7 makes any 4xx quiet. Either accept the query form in the contract and in `PublicHost`/`PhoneHomeRequest`, or surface the condition on **Settings → WOptimize** so the human sees why the site never connects.
  evidence: `playground-setup` sets `/%postname%/` precisely to dodge this, and the connector README already notes the plain structure answers at `?rest_route=…`. Nothing tells a real client site's owner. The story's Never list forbids connector PHP changes and new contract paths.

- source_spec: `_bmad-output/specs/spec-repo-structure/stories/6-playground-and-integration-suite.md`
  summary: Pin the three `uses:` lines in `.github/workflows/contract-suite.yml` (`actions/checkout`, `actions/setup-node`, `ddev/github-action-setup-ddev`) to full commit SHAs with the version in a trailing comment, at the first real CI run.
  evidence: Security review — a mutable tag plus the persisted `GITHUB_TOKEN` from `actions/checkout` is the tj-actions/changed-files shape. `permissions: contents: read` is already set; the SHAs need a network check the story's "no push to GitHub" rule keeps out of this build.

- source_spec: `_bmad-output/specs/spec-repo-structure/stories/6-playground-and-integration-suite.md`
  summary: Refresh the `AGENTS.md` managed block with `bmad-project-context` — it still says the contract "is written 3.1", calls `packages/connector` "a third DDEV project", says integration tests "belong on `apps/playground` (story 6)", and states the WordPress pin lives in `www-setup` "and nowhere else" while `playground-setup` now carries its own `WP_VERSION=6.7`.
  evidence: Three review layers flagged the stale block. The story's Never list forbids a hand edit, and its manual check already names the refresh.

- source_spec: `_bmad-output/specs/spec-repo-structure/stories/6-playground-and-integration-suite.md`
  summary: Decide what to do with the `## Doc timeline` section that appeared at the bottom of `AGENTS.md` during this build, outside the managed block — it is unrelated to story 6 and links `_bmad-output/forge/monorepo-vs-multirepo/forget-idea.md`, which does not exist (the file is `forged-idea.md`).
  evidence: The change was not made by this story's agents and was not in the baseline working tree; the story forbids hand edits of `AGENTS.md`, so it was left untouched for the human.
