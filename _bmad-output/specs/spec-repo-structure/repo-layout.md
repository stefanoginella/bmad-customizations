# Repo Layout

```
woptimize/
  apps/
    www/            WP marketing site — themes/ + plugins/ in git, WP install gitignored
    portal/         Laravel client portal + admin dashboard — one app, two hostnames
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

## apps/portal hostnames

- One Laravel app serves two hostnames: the client portal and the admin dashboard (spine AD-20). Not two apps, not two databases.
- Production: one RunCloud web app — `portal.woptimize.io` primary, `admin.woptimize.io` alias, both on one Basic SSL certificate. An alias **domain**, never an alias *web app*.
- Local: `portal.woptimize.ddev.site` and `admin.woptimize.ddev.site`. The second comes from `additional_hostnames: [admin.woptimize]` in `apps/portal/.ddev/config.yaml`. DDEV appends the `.ddev.site` TLD and puts both names in the project certificate — no `/etc/hosts` edit and no sudo.
- Routes split by domain into separate route files. The domain comes from config (`ADMIN_DOMAIN`), never a literal inside a route file.
- Admin identity is its own table (`admin_users`) with its own guard — never a role flag on the client `users` table.

Full tree with per-path build/commit notes: the Structural Seed in `ARCHITECTURE-SPINE.md`.
