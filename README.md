# woptimize.io

Monorepo for WOptimize: the marketing site, the client portal, the connector
plugin, and the shared design tokens.

## Repo map

```
woptimize/
  apps/
    www/            WP marketing site — themes/ + plugins/ in git, WP install gitignored
    portal/         Laravel client portal
    playground/     throwaway WP "client site" for connector integration tests
  packages/
    design-tokens/  single token source → builds all style artifacts — host Node 24
    connector/      the connector plugin for client sites + openapi.yaml, the contract
  infra/
    umami/          compose + env only — deployment, not a codebase
  .github/
    workflows/      per-app CI with path filters
```

Empty folders above are placeholders held by `.gitkeep`. A later story fills
each one.

## Getting started

You need **DDEV >= 1.25** with a Docker provider, plus **Node >= 24 on the
host** (`.nvmrc` says `24`) for the design-token build. Everything else runs in
the containers.

Three DDEV projects live in this repo, each with its own README and quickstart:

- [`apps/www/README.md`](apps/www/README.md) — `ddev start` + `ddev www-setup`
- [`apps/portal/README.md`](apps/portal/README.md) — `ddev start` + `ddev portal-setup`
- [`packages/connector/README.md`](packages/connector/README.md) — `ddev start`
  + `ddev composer install`, then `ddev composer test` and `ddev composer lint`.
  Its own DDEV project runs **PHP 8.1**, the client-site floor, with no
  database.

`ddev www-setup` and `ddev portal-setup` each build the design tokens as their
first step, so the two apps need nothing extra by hand. The connector has no
setup command and no token step — it consumes no tokens, and `ddev composer
install` is all it needs.

To rebuild the tokens on their own:

```bash
npm run tokens:build     # from the repo root
npm run tokens:test
```

There is no root `composer.json` and no npm/composer workspaces. The root
`package.json` holds cross-cutting scripts and nothing else — no dependencies,
no root lockfile. Every unit manages its own dependencies.

## Design tokens

One DTCG source in [`packages/design-tokens`](packages/design-tokens/README.md)
builds four style artifacts. All four are **generated, gitignored, and never
hand-edited**. Each carries a "GENERATED FILE — DO NOT EDIT" notice: the three
CSS files open with it as a comment, and `theme.json` — which cannot hold a
comment — carries it as its first key, `__generated`.

- `apps/www/themes/woptimize-theme/theme.json` (merged into the committed
  `theme.base.json`)
- `apps/www/themes/woptimize-theme/assets/css/tokens.css`
- `apps/portal/resources/css/tokens.theme.css`
- `apps/portal/resources/css/tokens.base.css`

The build runs on the host, not in DDEV: each DDEV project mounts only its own
app folder, while the build reads `packages/` and writes into both apps.

## Rules that bind the whole tree

- An app never imports another app's code. Sharing happens through `packages/`
  at build time, or through the connector↔portal contract at runtime.
- That contract lives in
  [`packages/connector/openapi.yaml`](packages/connector/openapi.yaml) and
  covers both directions. Change the file **before** the code, both directions
  in one PR.
- The connector runs on client sites, so its floor is PHP 8.1 / WP 6.7 — no PHP
  8.2+ syntax — while the two apps target PHP 8.4. Every remote failure in the
  connector degrades to a silent no-op; it must never break a client's site.
- Each app keeps its own framework's idioms and tooling.
- Slugs are fixed everywhere: theme `woptimize-theme`, site plugin
  `woptimize-core`, connector `woptimize-connector`.

## Planning artifacts

Architecture, specs, and stories live in `_bmad-output/`. The architecture
spine is the tie-breaker for anything cross-cutting.
