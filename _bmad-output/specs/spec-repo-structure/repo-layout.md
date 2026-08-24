# Repo Layout

```
woptimize/
  apps/
    www/            WP marketing site — themes/ + plugins/ in git, WP install gitignored
    portal/         Laravel client portal
    playground/     throwaway WP "client site" for connector integration tests
  packages/
    design-tokens/  single token source → builds all style artifacts (CAP-2)
    connector/      plugin installed on client sites
  infra/
    umami/          compose + env only — deployment, not a codebase
  .github/
    workflows/      per-app CI with path filters
```

## apps/www layout

- `themes/` and `plugins/` sit at the `apps/www` root; the full WP install is gitignored in `wordpress/`.
- `themes/` is plural — room for a future child theme beside `themes/woptimize-theme/`.
- Slugs are fixed everywhere (local symlinks, server, DB): theme `woptimize-theme`, site plugin `woptimize-core`.
- `wordpress/wp-content/` links to the root folders per item with **relative** symlinks: `wp-content/themes/woptimize-theme` and `wp-content/plugins/woptimize-core`.
- DDEV project root is `apps/www` with `docroot: wordpress`. Required — with any other project root, the symlink targets fall outside the container mount.

Full tree with per-path build/commit notes: the Structural Seed in `ARCHITECTURE-SPINE.md`.
