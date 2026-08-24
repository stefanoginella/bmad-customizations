# apps/www — WordPress marketing site

Local host: `https://woptimize.ddev.site` · Production: `www.woptimize.io`

## Prerequisites

- **DDEV >= 1.25** with a working Docker provider (Docker Desktop, OrbStack,
  Colima, Rancher Desktop). Install both by following
  <https://ddev.readthedocs.io/>.
- **Node >= 24 on the host** (`.nvmrc` at the repo root says `24`) — for the
  design-token build only. It is the one step that cannot run in this project's
  container, because the container mounts `apps/www` alone while the build reads
  `packages/` and writes into both apps. `www-setup` stops with an error if
  `npm` is missing. See
  [`packages/design-tokens/README.md`](../../packages/design-tokens/README.md).

Nothing else. No local PHP, Composer, or WordPress is needed — everything else
runs inside the DDEV containers.

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

0. Builds the design tokens first, by running `npm run tokens:build` at the repo
   root — always the first build step, before anything else. It writes this
   theme's `theme.json` and `assets/css/tokens.css`. Missing host `npm` stops
   the command here, before WordPress core is touched.
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
    woptimize-theme/            the custom theme (slug: woptimize-theme) — in git
      theme.base.json           the theme's half of theme.json           — in git
      theme.json                base + tokens, merged                    — GENERATED
      assets/css/tokens.css     custom properties + base styles          — GENERATED
  plugins/
    woptimize-core/             the one site plugin (slug: woptimize-core) — in git
  wordpress/                    the full WP install                        — gitignored
  .ddev/                        DDEV project root; docroot: wordpress      — in git
```

`themes/` is plural: a future child theme gets its own folder beside
`woptimize-theme/` and its own symlink, same pattern.

Slugs are fixed everywhere — local symlinks, server, database. Do not rename
them.

## Design tokens

`theme.json` and `assets/css/tokens.css` are **generated and gitignored — never
hand-edit them**. Both are rebuilt from
[`packages/design-tokens`](../../packages/design-tokens/README.md) by
`npm run tokens:build` at the repo root, and each carries a "GENERATED FILE — DO
NOT EDIT" notice at the top.

The two halves of `theme.json`:

- The **theme** owns `theme.base.json` — committed, hand-edited, everything
  except the four token sections.
- The **tokens** own `settings.color.palette`,
  `settings.typography.fontFamilies`, `settings.typography.fontSizes`, and
  `settings.spacing.spacingSizes`. Adding one of them to `theme.base.json`
  fails the build with a message naming the key.

To change a colour, a size, a radius, or a duration, edit the DTCG source in
`packages/design-tokens/tokens/` and rebuild. `functions.php` enqueues
`assets/css/tokens.css` as the handle `woptimize-tokens`, and `style.css`
(handle `woptimize-theme`) depends on it, so theme CSS always loads after the
tokens.

## Rules

- The DDEV project root must stay `apps/www`. With any other root the relative
  symlink targets fall outside the container mount and break.
- Never commit anything under `wordpress/`, and never commit the symlinks. The
  setup command creates them.
- Stack: PHP 8.4, MariaDB 11.8 (provisional — it mirrors RunCloud once the
  production server is provisioned).
