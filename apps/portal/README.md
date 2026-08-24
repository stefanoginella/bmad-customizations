# apps/portal — Laravel client portal

Local host: `https://portal.woptimize.ddev.site` · Production: `portal.woptimize.io`

## Prerequisites

- **DDEV >= 1.25** with a working Docker provider (Docker Desktop, OrbStack,
  Colima, Rancher Desktop). Install both by following
  <https://ddev.readthedocs.io/>.
- **Node >= 24 on the host** (`.nvmrc` at the repo root says `24`) — for the
  design-token build only. It is the one step that cannot run in this project's
  container, because the container mounts `apps/portal` alone while the build
  reads `packages/` and writes into both apps. `portal-setup` stops with an
  error if `npm` is missing. See
  [`packages/design-tokens/README.md`](../../packages/design-tokens/README.md).

Nothing else. The portal's own PHP, Composer, and Node all run inside the DDEV
containers.

## Quickstart

Two commands from a fresh clone:

```bash
cd apps/portal
ddev start
ddev portal-setup
```

Nothing is set up by hand.

### Starting over

If local state goes bad, throw the install away and rebuild it:

```bash
cd apps/portal
rm -rf vendor node_modules public/build
ddev restart      # recreates .env only if it is missing — see below
ddev portal-setup
```

DDEV's `laravel` project type owns `.env`. It **creates** the file from
`.env.example` only when the file is absent, and patches `APP_URL` and the
`DB_*` keys into it at every start. `portal-setup` never writes those keys, so
if `.env` is missing it stops with an error telling you to run `ddev restart`.

A `.env` that exists but is broken is **not** repaired by a restart — delete it
first, then restart:

```bash
rm .env && ddev restart && ddev portal-setup
```

That loses any local edits to `.env` and generates a fresh `APP_KEY`.

## What `ddev portal-setup` produces

0. Builds the design tokens first, by running `npm run tokens:build` at the repo
   root — always the first build step, and always before Vite compiles
   `app.css`. It writes `resources/css/tokens.theme.css` and
   `resources/css/tokens.base.css`. Missing host `npm` stops the command here.
1. Installs the Composer dependencies from `composer.lock`.
2. Stops with a clear error if `.env` is missing — DDEV owns that file (see
   [Starting over](#starting-over)).
3. Runs `php artisan key:generate` **only** when `APP_KEY` is empty. A second
   run leaves an existing key alone; rotating it would invalidate every
   encrypted value and session already in the local database.
4. Runs `php artisan migrate --force` against the MariaDB container.
5. Runs `npm install`, flushes the host to container sync, then `npm run build`
   (Vite + Tailwind 4). The flush matters: the token artifacts were just written
   on the host, and Vite reads them through `app.css`.

The command is safe to re-run: it always ends in the same state and exits 0.
That is not the same as doing nothing. `composer install` and `npm install`
run every time and finish as fast no-ops when the lock files are already
satisfied, `migrate` reports nothing to migrate, and the assets are rebuilt
from scratch. The one genuinely skipped step is `key:generate` — an existing
`APP_KEY` is never rotated.

## Tests and style

```bash
ddev exec php artisan test          # Pest 5
ddev exec ./vendor/bin/pint         # fix style
ddev exec ./vendor/bin/pint --test  # check only, exits non-zero on drift
```

Tests are **Pest** — the skeleton's PHPUnit runner is gone. They run on
sqlite `:memory:` from the `phpunit.xml` env block: fast, and no dependency on
the database container. The running app uses MariaDB through the `.env` keys
DDEV patches in, so local mirrors production.

Style is the bundled **Laravel Pint** with a committed `pint.json` pinning
`"preset": "laravel"`.

`tests/Feature/ContractTest.php` parses the contract file itself, so it needs
the read-only mount `.ddev/docker-compose.contract.yaml` sets up. After a fresh
clone run `ddev restart` once, then check it:

```bash
ddev exec test -r /mnt/woptimize/openapi.yaml
```

The test **fails** — it never skips — when that path is unreadable. Point it
somewhere else with `WOPTIMIZE_CONTRACT_FILE` if you ever need to.

It is a **single-file** bind mount, and those go stale: an editor that saves by
writing a temp file and renaming it over `openapi.yaml` leaves the container
holding the old inode. After editing the contract, run `ddev restart` before
trusting what `ContractTest` says.

## Connector contract side

The portal owns the `sites` registry and the portal half of
[`packages/connector/openapi.yaml`](../../packages/connector/openapi.yaml).

```
POST /api/connector/v1/phone-home     auth: X-Woptimize-Site-Key header
```

A client site posts its `SiteReport` there once a day. The request is
authenticated by the `connector` guard — a `viaRequest` guard that hashes the
presented key, finds the row by `site_key_hash`, and confirms it with
`hash_equals()`. Anything else gets Laravel's default
`401 {"message":"Unauthenticated."}` and touches no row. A bad body gets a
`422`. There is deliberately **no throttle** on the route: a connector treats
any 4xx as permanent-quiet until the next daily slot (AD-7), so a 429 caused by
a shared client IP would silence an honest site for a day.

Onboarding is Artisan for now — the portal has no login yet, and only the
portal may ever issue a key (AD-16):

```bash
ddev exec php artisan site:onboard https://client.example   # row + key, printed once
ddev exec php artisan site:list                             # id, site_url, connector_version, last_seen_at
ddev exec php artisan site:rotate-key <site>                # new key; the old one dies at once
ddev exec php artisan site:offboard <site>                  # row deleted; its key 401s from then on
ddev exec php artisan site:ping <site>                      # GET {rest_base}/ping
ddev exec php artisan site:status <site>                    # GET {rest_base}/status
```

`<site>` is an id or a `site_url`. A failure exits 1 with one line. `site:list`
never prints a key, and neither does any log line.

`rest_base` is whatever the site reported — the portal never derives it from
`site_url`, and refuses to call a site that has not phoned home yet. A pull is
read-only: `last_seen_at` means "phoned home", so `site:ping` and `site:status`
never write the registry.

Because a key holder writes its own report, `rest_base` is attacker input, and
the portal is the thing that fetches it. `App\Connector\Rules\PublicHost`
therefore refuses a `rest_base` (and a `home_url`) whose host resolves anywhere
private: loopback, the private ranges, link-local — so
`http://169.254.169.254/` and a friendly name pointed at `127.0.0.1` are both
422. A host that resolves nowhere is refused too, and `ConnectorClient` keeps
`withoutRedirecting()` so a public host cannot bounce the call somewhere
private.

That rule would refuse every local client site, since `*.ddev.site` resolves to
`127.0.0.1`. `WOPTIMIZE_ALLOW_PRIVATE_REST_BASE=true` turns it off and is set
in `.env.example` for exactly that reason. **Keep it false in production** —
`config/connector.php` defaults to false when the key is absent.

## Layout

```
apps/portal/
  app/ bootstrap/ config/
  database/          migrations, factories, seeders
  public/            docroot; public/build is gitignored
  resources/         css, js, blade views
    css/app.css      the portal's own stylesheet             — in git
    css/tokens.theme.css   Tailwind @theme static block      — GENERATED
    css/tokens.base.css    shared base styles                — GENERATED
  routes/ storage/ tests/
  vendor/            gitignored
  node_modules/      gitignored
  .env               written by DDEV — gitignored
  .ddev/             DDEV project root; docroot: public         — in git
    docker-compose.contract.yaml   mounts openapi.yaml read-only — in git
```

## Design tokens

`resources/css/tokens.theme.css` and `resources/css/tokens.base.css` are
**generated and gitignored — never hand-edit them**. Both are built from
[`packages/design-tokens`](../../packages/design-tokens/README.md) by
`npm run tokens:build` at the repo root, and each carries a "GENERATED FILE — DO
NOT EDIT" notice at the top.

`app.css` — which stays the portal's file — imports them right after Tailwind:

```css
@import 'tailwindcss';
@import './tokens.theme.css';   /* @theme static { --color-*, --spacing-*, … } */
@import './tokens.base.css';    /* @layer base { html, body, a, .wo-button, … } */
```

`@theme static` puts **every** token on `:root`, not only the ones a utility
happens to reference, so `tokens.base.css` and your own CSS can read all of
them. The namespaces are Tailwind's, so `bg-primary`, `text-lg`, `p-4`,
`rounded-md`, and `ease-out` work straight away.

To change a colour, a size, a radius, or a duration, edit the DTCG source in
`packages/design-tokens/tokens/` and rebuild. Base styles sit in `@layer base`,
so Tailwind utilities and the portal's own CSS always win.

## Rules

- The DDEV project root must stay `apps/portal`, with `name: portal.woptimize`.
  The primary URL drives the `APP_URL` that DDEV writes into `.env`.
- Never commit `vendor/`, `node_modules/`, `public/build/`, or `.env`.
- `.env` is DDEV's file. Never add `APP_URL` or `DB_*` to `portal-setup` or to
  any other script — they are rewritten at every start.
- Stack: PHP 8.4, Laravel 13, Node 24, MariaDB 11.8 (provisional — it mirrors
  RunCloud once the production server is provisioned).
- The portal never imports another app's code. Design tokens arrive through
  `packages/design-tokens`; the connector link is the versioned contract in
  `packages/connector/openapi.yaml`.
- Contract facts live once, in `app/Connector/Contract.php`. `ContractTest`
  ties four of them to `openapi.yaml` — the two header names, the path prefix,
  the `SiteReport` required lists (top level and nested), and the documented
  response statuses. The key length, the key pattern, and the timeout are
  **portal** facts: the contract carries them only as prose, so no test can
  compare them. Keep those three in step by hand, and change `openapi.yaml`
  first, both directions in one PR.
- A site key is never stored in plaintext, never logged, and never printed by
  `site:list`. It is shown once, when it is issued.
