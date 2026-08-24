# packages/connector — the WOptimize connector plugin

Two things live here:

- **`openapi.yaml`** — the connector↔portal contract, for **both** directions.
  It is the only runtime coupling in the whole system.
- **everything else** — the `woptimize-connector` plugin, installed on client
  WordPress sites. The contents of this folder **are** the plugin folder
  (AD-17), so the release zip is this folder minus `.distignore`.

The plugin is not a WOptimize app. It runs on somebody else's production site,
so the rules below are not style preferences.

## The two rules that never bend

**1. The contract comes first (AD-4).** Change `openapi.yaml` **before** any
code, and change both directions in the same PR. Never invent a shape in code
and document it afterwards. `tests/ContractTest.php` fails when the file and
the code disagree.

**2. The connector never breaks a client site (AD-7).** Every remote failure
degrades to a silent no-op:

| What happened | What the connector does |
| --- | --- |
| No key stored | Sends nothing at all. |
| Any `4xx` | Records it and stays silent until the next daily slot. Permanent-quiet — the schedule is never tightened. |
| `5xx` or a transport error | One retry, 15 minutes later, as a single event. The retry never schedules another. |
| A `Throwable` in the cron path | Caught and recorded. |

No fatals, no `wp_die()`, no admin notices. The only visible trace of a failure
is the last-result line on **Settings → WOptimize**.

## The floor

Client sites are not our servers: **PHP 8.1, WordPress 6.7**. No PHP 8.2+
syntax. That is why this package has its own DDEV project — the own apps run
PHP 8.4, and the tests and lint have to run at the floor.

## Quickstart

```bash
cd packages/connector
ddev start
ddev composer install
ddev composer test     # PHPUnit 10.5 + Brain Monkey
ddev composer lint     # WordPress Coding Standards + PHP 8.1+ compatibility
ddev composer lint:fix # what PHPCS can fix on its own
```

`ddev exec php -v` reports 8.1. Everything runs in the container — no local PHP
and no local Composer, same as the two apps.

The DDEV project is `type: php` with no database and no docroot. It is a
runtime for the tooling, not a site. Real-WordPress integration tests are story
6's job, on `apps/playground`.

## What is in here

```
packages/connector/
  openapi.yaml              the contract, both directions        — NOT in the zip
  woptimize-connector.php   plugin header + constants + boot
  includes/
    class-plugin.php          registers every hook, does no work
    class-site-key.php        read / format-check / hash_equals the key
    class-site-report.php     the one writer of the SiteReport payload
    class-rest-controller.php GET /ping, GET /status, the version header
    class-phone-home.php      the daily report and the AD-7 branches
    class-settings.php        Settings → WOptimize
  uninstall.php             deletes both options, clears both hooks
  tests/                    Brain Monkey unit suite               — NOT in the zip
  .ddev/ composer.* phpcs.xml.dist phpunit.xml.dist               — NOT in the zip
```

`.distignore` lists everything the release zip drops: the DDEV project, the
Composer files, the PHPCS and PHPUnit configs, `tests/`, `vendor/`, this
README, and `openapi.yaml`. The plugin has **no Composer runtime
dependencies**, so a zip without `vendor/` is a complete plugin.

## The contract in one page

Auth is one credential in both directions: the portal-issued site key, in the
`X-Woptimize-Site-Key` header, compared with `hash_equals()` (AD-5). The
connector reports its version in the `X-Woptimize-Connector-Version` response
header and in the phone-home body — never in an ad-hoc body field.

**Connector-hosted**, under `woptimize/v1` on the client site:

| Route | Answer |
| --- | --- |
| `GET /ping` | `200 {"ok":true}` |
| `GET /status` | `200` with the `SiteReport` |
| either, bad auth | `401` in the WordPress core error envelope, nothing logged |

The portal calls the REST base the connector **reported** (`rest_base` in the
site report). It never builds that URL from the site URL (AD-6).

**Portal-hosted**, `POST {portal}/api/connector/v1/phone-home`: the same
`SiteReport`, sent daily by WP-Cron, once more right after a key is saved, and
once after the connector self-updates.

Changes inside `v1` are additive only, on both sides. `v2` is a major event.

## The site key

The **portal** issues every key (AD-16). This plugin never generates, derives,
or changes one — its `wp_options` copy is a cache of a portal-issued fact.

A human pastes the key into **Settings → WOptimize** (`manage_options`). Format
is 40 alphanumeric characters. A malformed paste **keeps the old key** and adds
a settings error; an empty field clears it, which disconnects the site and
leaves the connector doing nothing at all. Offboarding is uninstall — there is
no license system anywhere.

## The portal base

`WOPTIMIZE_PORTAL_URL`, default `https://portal.woptimize.io`, overridable from
`wp-config.php`. It is a constant and not a setting: one less field a human can
mistype. The only non-production value is the playground's (story 6).

## Changing things

- **A contract change** — edit `openapi.yaml` first, both directions, then the
  code. It needs a **minor** bump: Karin's rule (AD-8) says the portal supports
  the current and previous connector minor, and the connector ships first.
- **The version** — `WOPTIMIZE_CONNECTOR_VERSION` in `woptimize-connector.php`
  and the `Version:` plugin header must stay equal, and `info.version` in
  `openapi.yaml` is their `MAJOR.MINOR`. `ContractTest` enforces all three.
- **Style** — WordPress Coding Standards, not PSR-12 (AD-15). `ddev composer
  lint` is the arbiter.

## Verifying by hand on a real site

`apps/www/wordpress/wp-content/plugins/` is gitignored, so it doubles as a
smoke venue (at PHP 8.4 — the 8.1 guard is this package's container):

```bash
rsync -a --exclude '.ddev' --exclude vendor --exclude tests \
  packages/connector/ apps/www/wordpress/wp-content/plugins/woptimize-connector/
cd apps/www
ddev wp plugin activate woptimize-connector

# Point the connector away from the real portal FIRST — see the warning below.
ddev wp config set WOPTIMIZE_PORTAL_URL https://unreachable.invalid --type=constant

ddev wp option update woptimize_connector_site_key <40 alphanumeric chars>
curl -H 'X-Woptimize-Site-Key: <key>' \
  https://woptimize.ddev.site/wp-json/woptimize/v1/status

ddev wp plugin uninstall woptimize-connector --deactivate
ddev wp config delete WOPTIMIZE_PORTAL_URL
```

**Set the constant before you save a key.** Saving one fires an immediate
phone-home, so a test key on a scratch site would otherwise POST a real
`SiteReport` to the production portal. `https://unreachable.invalid` cannot
resolve — `.invalid` is reserved for exactly this — which also exercises the
AD-7 transport-error branch for free.

`wp plugin delete` removes the files without running `uninstall.php`. Use
`wp plugin uninstall` when you want to check the cleanup.

The site needs pretty permalinks for `/wp-json/…` URLs; with the plain
structure the same routes answer at `/?rest_route=/woptimize/v1/status`.

## Out of scope here

No update-check or download paths, no `update_plugins_*` filter, no CI, no
playground, no portal code. Those are stories 5, 6 and 9.
