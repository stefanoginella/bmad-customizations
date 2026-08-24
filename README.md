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
    connector/      plugin installed on client sites
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

Each app is its own DDEV project and carries its own README with the
quickstart:

- [`apps/www/README.md`](apps/www/README.md) — `ddev start` + `ddev www-setup`
- [`apps/portal/README.md`](apps/portal/README.md) — `ddev start` + `ddev portal-setup`

Both setup commands build the design tokens first, so there is nothing extra to
run by hand. To rebuild them on their own:

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
- Each app keeps its own framework's idioms and tooling.
- Slugs are fixed everywhere: theme `woptimize-theme`, site plugin
  `woptimize-core`, connector `woptimize-connector`.

## Planning artifacts

Architecture, specs, and stories live in `_bmad-output/`. The architecture
spine is the tie-breaker for anything cross-cutting.
