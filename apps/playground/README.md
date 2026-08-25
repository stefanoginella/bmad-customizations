# apps/playground — the client site the suite tests against

Local host: `https://playground.ddev.site` · Production: **none, ever**

A throwaway WordPress that plays the worst client the connector claims to
support: **PHP 8.1 and WordPress 6.7**, the plugin's declared floor. The real
connector plugin runs on it, live from `packages/connector`, and reports to the
real portal at `https://portal.woptimize.ddev.site`. Nothing here is mocked
(AD-18).

The integration suite lives here too. It holds every live response — the
connector's and the portal's — against
[`packages/connector/openapi.yaml`](../../packages/connector/openapi.yaml), and
drives the AD-7 no-op scenarios through WP-CLI.

## Prerequisites

- **DDEV >= 1.25** with a working Docker provider (Docker Desktop, OrbStack,
  Colima, Rancher Desktop). Install both by following
  <https://ddev.readthedocs.io/>.
- **`apps/portal` running.** The suite talks to the real portal. `ddev
  contract-suite` starts it for you; see
  [`apps/portal/README.md`](../portal/README.md).

No host Node here — this project builds no design tokens and has no theme. No
local PHP, Composer, or WordPress either: everything runs inside the DDEV
containers.

### The portal must allow a private `rest_base`

`playground.ddev.site` resolves to `127.0.0.1`, and the portal refuses a
`rest_base` whose host lands anywhere private — that rule
(`App\Connector\Rules\PublicHost`) is what stops a key holder pointing the
portal at `169.254.169.254`. `WOPTIMIZE_ALLOW_PRIVATE_REST_BASE=true` switches
it off, and `apps/portal/.env.example` sets it for exactly this reason, so a
local checkout and a CI run both inherit it with nothing to do.

Without it every phone-home in the suite answers `422` instead of `200`. **It
stays false in production** — `config/connector.php` defaults to false when the
key is absent.

## Quickstart

Three commands from a fresh clone:

```bash
cd apps/playground
ddev start
ddev playground-setup
ddev composer install
```

Nothing is set up by hand. Then run the suite:

```bash
ddev contract-suite            # same as `ddev contract-suite all`
```

### Starting over

If local state goes bad, throw the install away and rebuild it:

```bash
rm -rf wordpress
ddev restart      # DDEV regenerates wordpress/wp-config.php
ddev playground-setup
```

The `ddev restart` is required. `wp-config.php` lives inside `wordpress/`, DDEV
writes it, and `playground-setup` does not — skipping the restart makes
`playground-setup` stop with an error telling you to run it.

## What `ddev playground-setup` produces

1. Brings WordPress core to the pinned version — the pin lives at the top of
   `.ddev/commands/host/playground-setup` and nowhere else. Absent core is
   downloaded; core already at the pin is left alone; core at any other version
   is moved to the pin with `wp core update --force`.
2. Stops with a clear error if `wordpress/wp-config.php` is missing — DDEV owns
   that file (see [Starting over](#starting-over)).
3. Creates the two symlinks, then waits until the web container actually
   resolves both and fails loudly if it never does:
   - `wordpress/wp-content/plugins/woptimize-connector -> /mnt/woptimize/connector`
   - `wordpress/wp-content/mu-plugins/woptimize-playground.php -> ../../../mu-plugins/woptimize-playground.php`
4. Runs `wp core install` if the site is not installed yet.
   Local-only admin credentials: `admin` / `admin`.
5. Activates `woptimize-connector`.
6. Sets pretty permalinks to `/%postname%/`.
7. Stores the fixture site key, and only when the stored value differs — saving
   that option fires a phone-home.

The command is idempotent. A second run re-asserts the symlinks and exits 0
without downloading or installing again.

## What `ddev contract-suite` does

`ddev contract-suite [all|current|previous]`, default `all`. It is the single
step of `.github/workflows/contract-suite.yml`: same command, same output, in CI
and on your machine.

Per leg it

1. plants the fixture site on the portal — `site:offboard` (ignored on failure),
   then `site:onboard … --key=…`,
2. runs `vendor/bin/phpunit --exclude-group offboarded` inside this container,
3. calls `site:ping` and `site:status` from the portal, so the pull direction is
   exercised too,
4. runs `site:offboard`, then `vendor/bin/phpunit --group offboarded` — the one
   scenario only the host can stage, because artisan is not reachable from here,
5. plants the fixture again and fires one phone-home, so the portal ends the run
   showing the playground as a live, healthy site. This step runs whether the
   leg passed or failed — a run that dies in the middle must not leave the
   portal with no row for the playground at all — and it is checked: AD-7 makes
   every failure a silent no-op, so the command reads back
   `woptimize_connector_phone_home` and fails the leg unless `last_result` is
   `ok`.

It prints one line per leg and exits non-zero when any leg fails:

```
current: PASS
previous: SKIPPED — no plugin-v* tag below 0.1
```

### The `previous` leg

Karin's rule (AD-8, AD-11): the portal serves the current **and** the previous
connector minor, so the suite runs twice. `<tag>` is the highest `plugin-v*` tag
with the same MAJOR as, and a lower MINOR than, `WOPTIMIZE_CONNECTOR_VERSION` at
HEAD. A major bump is a contract break (AD-5), so a `.0` minor has no previous
leg.

That tag is extracted with `git archive` into `.previous-connector/`, the plugin
symlink is repointed at it for the run and put back afterwards — also when the
leg fails, and also on Ctrl-C — and the suite validates against **that tag's**
`openapi.yaml`. No matching tag prints the `SKIPPED` line and exits 0.

The symlink is never taken on trust. Every run starts by pointing it back at
`/mnt/woptimize/connector`, whatever a crashed earlier run left behind; after
each repoint the command waits until `readlink` **inside the container** shows
the expected target, and after an extraction it also waits until the container
reads the same `WOPTIMIZE_CONNECTOR_VERSION` that `git archive` just wrote — a
stale folder from an earlier run would satisfy a plain "does it exist" check and
quietly run the wrong plugin for a whole leg. Any of those checks failing fails
the leg.

## Layout

```
apps/playground/
  mu-plugins/
    woptimize-playground.php    the three local-only facts               — in git
  tests/
    Support/                    Contract, WpCli, Playground              — in git
    *Test.php                   one file per group of matrix rows        — in git
  .ddev/                        DDEV project root; docroot: wordpress    — in git
  wordpress/                    the full WP install                      — gitignored
  vendor/                       PHPUnit, Guzzle, the validator           — gitignored
  .previous-connector/          one tagged connector, for one leg        — gitignored
```

## The mu-plugin

`mu-plugins/woptimize-playground.php` does exactly three things, and it is the
only WordPress code this project owns:

- defines `WOPTIMIZE_PORTAL_URL` from the environment variable of the same name
  (default `https://portal.woptimize.ddev.site`). It is a must-use plugin, so it
  loads before the connector, whose own `define()` is guarded — first definition
  wins.
- defines `DISABLE_WP_CRON` true, so only WP-CLI ever fires a phone-home. A
  browser hit, or the portal's `site:ping`, would otherwise spawn a background
  run in the middle of a scenario.
- points the WordPress HTTP API at DDEV's mkcert root
  (`/mnt/ddev-global-cache/mkcert/rootCA.pem`) through `http_request_args`.
  WordPress ships its own CA bundle, so without this every call to
  `*.ddev.site` fails with cURL error 60. **`sslverify => false` is never the
  answer** — a suite that skips TLS proves nothing about TLS.

## The fixture site key

`WOPTIMIZE_TEST_SITE_KEY` is set in exactly one place,
`.ddev/config.yaml` → `web_environment`. `playground-setup` plants it in the
site, `contract-suite` onboards the same value on the portal with
`site:onboard --key=…`, and the tests read it straight from the container
environment.

It is a fixture, not a secret: it exists only inside this local project, and
`--key` is refused in production. The portal still issues every key (AD-16) —
the option only makes this one reproducible, so a re-plant does not invalidate
the site the suite just set up.

## Rules

- Never commit anything under `wordpress/`, `vendor/`, or `.previous-connector/`,
  and never commit the symlinks. The setup command creates them.
- `packages/connector` reaches this container as a **read-only** directory bind
  mount at `/mnt/woptimize/connector`
  (`.ddev/docker-compose.connector.yaml`). From
  `wordpress/wp-content/plugins/`, the package is five levels up — outside this
  project's mount — so a relative symlink would dangle inside the container.
- The contract file the suite validates against comes from
  `WOPTIMIZE_CONTRACT_FILE`, default `/mnt/woptimize/connector/openapi.yaml`.
  An unreadable file **fails** the suite; it never skips. A skipped contract
  check is a green run that proves nothing.
- No theme, no design tokens, no content sync, no deploy. This project exists to
  be thrown away.
- Stack: PHP 8.1, WordPress 6.7, MariaDB 11.8.
