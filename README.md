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
    design-tokens/  single token source → builds all style artifacts
    connector/      plugin installed on client sites
  infra/
    umami/          compose + env only — deployment, not a codebase
  .github/
    workflows/      per-app CI with path filters
```

Empty folders above are placeholders held by `.gitkeep`. A later story fills
each one.

## Getting started

Each app is its own DDEV project and carries its own README with the
quickstart:

- [`apps/www/README.md`](apps/www/README.md) — `ddev start` + `ddev www-setup`

There is no root `composer.json` and no npm/composer workspaces. Every unit
manages its own dependencies.

## Rules that bind the whole tree

- An app never imports another app's code. Sharing happens through `packages/`
  at build time, or through the connector↔portal contract at runtime.
- Each app keeps its own framework's idioms and tooling.
- Slugs are fixed everywhere: theme `woptimize-theme`, site plugin
  `woptimize-core`, connector `woptimize-connector`.

## Planning artifacts

Architecture, specs, and stories live in `_bmad-output/`. The architecture
spine is the tie-breaker for anything cross-cutting.
