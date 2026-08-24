# apps/www — WordPress marketing site

Local host: `https://woptimize.ddev.site` · Production: `www.woptimize.io`

## Prerequisites

- **DDEV >= 1.25** with a working Docker provider (Docker Desktop, OrbStack,
  Colima, Rancher Desktop). Install both by following
  <https://ddev.readthedocs.io/>.

Nothing else. No local PHP, Composer, or WordPress is needed — everything runs
inside the DDEV containers.

## Quickstart

Two commands from a fresh clone:

```bash
cd apps/www
ddev start
ddev www-setup
```

Nothing is set up by hand.

### Starting over

If local state goes bad, throw the install away and rebuild it:

```bash
rm -rf wordpress
ddev restart      # DDEV regenerates wordpress/wp-config.php
ddev www-setup
```

The `ddev restart` is required. `wp-config.php` lives inside `wordpress/`, DDEV
writes it, and `www-setup` does not — skipping the restart makes `www-setup`
stop with an error telling you to run it.

## What `ddev www-setup` produces

1. Brings WordPress core to the pinned version — the pin lives at the top of
   `.ddev/commands/host/www-setup` and nowhere else. Absent core is downloaded;
   core already at the pin is left alone; core at any other version is moved to
   the pin with `wp core update --force`. Bumping the pin therefore takes effect
   on warm checkouts too.
2. Stops with a clear error if `wordpress/wp-config.php` is missing — DDEV owns
   that file (see [Starting over](#starting-over)).
3. Creates the two **relative** per-item symlinks:
   - `wordpress/wp-content/themes/woptimize-theme -> ../../../themes/woptimize-theme`
   - `wordpress/wp-content/plugins/woptimize-core -> ../../../plugins/woptimize-core`
   then waits until the web container actually sees both, and fails loudly if
   the host to container sync never lands.
4. Runs `wp core install` if the site is not installed yet.
   Local-only admin credentials: `admin` / `admin`.
5. Activates `woptimize-theme` and `woptimize-core`.

The command is idempotent. A second run re-asserts the symlinks and the
activation, and exits 0 without downloading or installing again.

## Layout

```
apps/www/
  themes/
    woptimize-theme/   the custom theme (slug: woptimize-theme)   — in git
  plugins/
    woptimize-core/    the one site plugin (slug: woptimize-core) — in git
  wordpress/           the full WP install                        — gitignored
  .ddev/               DDEV project root; docroot: wordpress      — in git
```

`themes/` is plural: a future child theme gets its own folder beside
`woptimize-theme/` and its own symlink, same pattern.

Slugs are fixed everywhere — local symlinks, server, database. Do not rename
them.

## Rules

- The DDEV project root must stay `apps/www`. With any other root the relative
  symlink targets fall outside the container mount and break.
- Never commit anything under `wordpress/`, and never commit the symlinks. The
  setup command creates them.
- Stack: PHP 8.4, MariaDB 11.8 (provisional — it mirrors RunCloud once the
  production server is provisioned).
