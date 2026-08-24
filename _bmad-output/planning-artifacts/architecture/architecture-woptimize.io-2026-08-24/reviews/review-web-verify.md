# Web Verification Review — ARCHITECTURE-SPINE.md

**Target:** `/Volumes/Main/Projects/woptimize.io/_bmad-output/planning-artifacts/architecture/architecture-woptimize.io-2026-08-24/ARCHITECTURE-SPINE.md`
**Review date:** 2026-08-24
**Method:** WebSearch + WebFetch against primary sources (vendor docs, GitHub, Packagist) — not training-data recall.

## Verdict

All nine committed Stack decisions are current and real as of 2026-08-24; one supporting detail in the Deferred section (the named example OpenAPI validator) is factually wrong for the OpenAPI 3.1 pin the doc itself commits to, and two items (RunCloud web-root symlink pattern, PHP 8.4 support window) carry minor caveats worth flagging at low severity.

## Findings summary

| # | Item | Severity | Status |
| - | --- | --- | --- |
| 1 | `league/openapi-psr7-validator` named as "3.1-compatible" example in Deferred | **Medium** | Wrong — it only validates OpenAPI 3.0.x |
| 2 | PHP 8.4 exits Active Support Dec 31, 2026 (~4 months from today) | **Low** | Not a defect — still the pragmatic pin, see detail |
| 3 | RunCloud "current/public" web-root pattern | **Low** | Plausible/supported by evidence, not explicitly documented by RunCloud for this exact combo |
| 4–9 | PHP 8.4/Laravel 13, WordPress 7.1, Node 24 LTS, Tailwind 4.3, Style Dictionary 5.5, `ddev/github-action-setup-ddev`, DDEV custom providers, RunCloud backups | — | All verified current and real |

---

## Detailed findings

### 1. PHP 8.4 as pin for Laravel 13 — VERIFIED, with a useful nuance

Laravel's own release notes (laravel.com/docs/13.x/releases) state Laravel 13.x requires a **minimum** of PHP 8.3, supporting the range 8.3–8.5, released March 17, 2026, bugfixes until Q3 2027, security until March 17, 2028.

However, `laravel/framework` GitHub issue #59564 documents that **Laravel 13.3.0+ transitively requires PHP 8.4** — it pulls in `symfony/error-handler ^8.0` and `symfony/console ^8.0`, both of which require PHP 8.4, breaking `composer install` on PHP 8.3 despite the framework's stated 8.3 floor. Workarounds offered: upgrade to PHP 8.4, pin Symfony to 7.4, or stay on Laravel 12.

**Conclusion:** pinning PHP 8.4 (rather than the nominal 8.3 floor) is the *correct* pragmatic call for Laravel 13 today — it sidesteps a known, real dependency trap. This is not a flag against the spine; if anything it's the safer choice than the doc's own stated minimum.

Sources:
- https://laravel.com/docs/13.x/releases
- https://github.com/laravel/framework/issues/59564

### 2. PHP 8.4 support window — LOW (informational, not a defect)

PHP 8.4 (released Nov 2024) moves from **Active Support to Security-only support on December 31, 2026** — about 4 months from the doc's date — and remains security-supported until December 31, 2028. PHP 8.5 already exists as the new active-support release.

This is not urgent and does not make PHP 8.4 an unsound pin (2+ years of security patches remain, and RunCloud/hosting environments typically lag on offering brand-new PHP minors anyway). Flagging only so the team knows the clock on "active" support starts now, distinct from EOL.

Sources:
- https://www.php.net/supported-versions.php
- https://www.herodevs.com/blog-posts/php-end-of-life-dates-support-timeline-for-every-version-2026

### 3. WordPress 7.1 — VERIFIED current

WordPress 7.1 "Mary Lou" released August 19, 2026 (RC1 Aug 5 → RC4 Aug 17 → final Aug 19), coinciding with WordCamp US 2026. It is the current stable release as of the doc's date (2026-08-24); WordPress 7.2 is slated for December 10, 2026.

Sources:
- https://make.wordpress.org/core/7-1/
- https://make.wordpress.org/core/2026/07/03/wordpress-7-1-release-party-schedule/
- https://wordpress.org/documentation/wordpress-version/version-7-1/

### 4. Node.js 24 — VERIFIED Active LTS

Node.js 24 is in Active LTS as of 2026, supported through April 2028. Node 22 is now in Maintenance LTS; Node 26 becomes Active LTS October 28, 2026 — which the spine's own Deferred section already correctly flags ("Node 26 LTS bump — when it enters Active LTS (October 2026)"). No issue.

Sources:
- https://endoflife.date/nodejs
- https://gethired.dev/blog/nodejs-26-vs-nodejs-24-lts-upgrade/

### 5. Tailwind CSS 4.3 — VERIFIED current, CSS-first

Tailwind CSS v4.3 is real; latest patch is v4.3.3. The CSS-first `@theme` configuration model (replacing `tailwind.config.js`) has been Tailwind's core v4 paradigm since v4.0 and continues unchanged through v4.3, which additionally adds scrollbar utilities, new neutral color palettes, and a first-class webpack plugin.

Sources:
- https://tailwindcss.com/blog/tailwindcss-v4-3
- https://github.com/tailwindlabs/tailwindcss/releases/tag/v4.3.3
- https://tailwindcss.com/blog/tailwindcss-v4

### 6. Style Dictionary 5.5 — VERIFIED, DTCG-native

Style Dictionary 5.5 exists and adds support for DTCG v2025.10's dimension-token object-value syntax (while staying backward compatible with string-form dimensions), extends CSS-shorthand transforms to composed types (typography, border, shadow), and adds `emitEmptyFiles`. This aligns with the spine's DTCG `$value`/`$type` token-format convention.

Sources:
- https://github.com/style-dictionary/style-dictionary/issues/1590
- https://styledictionary.com/info/dtcg/
- https://styledictionary.com/versions/v5/migration/

### 7. `ddev/github-action-setup-ddev` — VERIFIED real, official, maintained

This is the official DDEV-org action (moved from the community `jonaseberle/github-action-setup-ddev`, which is now explicitly deprecated in favor of the org-owned repo). It starts DDEV from a project's `.ddev` config for CI, reusing the same environment as local dev — exactly the AD-11 "one command, two venues" claim. Current usage is `ddev/github-action-setup-ddev@v1`.

Sources:
- https://github.com/ddev/github-action-setup-ddev
- https://github.com/ddev/github-action-setup-ddev/blob/main/README.md
- https://github.com/jonaseberle/github-action-setup-ddev

### 8. OpenAPI 3.1 as the pin — VERIFIED as pragmatic (not stale)

The OpenAPI Specification has since moved to **3.2.0** (released September 2025), and a 4.0 "Moonwalk" major is in early design as of 2026 — so 3.1 is one minor behind current. However, 3.2 is an explicitly **non-breaking, strictly-compatible superset of 3.1** ("zero-breaking migration"), so 3.1 documents remain fully valid under any 3.2-aware tooling, and PHP ecosystem tooling generally lags spec releases by 1+ years. The spine's own language — "the pragmatic pin for PHP tooling" — accurately describes this reality. **Not flagged as a defect**, this is a deliberate, verified, defensible choice.

Sources:
- https://github.com/OAI/OpenAPI-Specification/milestone/12
- https://www.friedrichs-it.de/blog/openapi-3.2/
- https://www.speakeasy.com/openapi/release-notes/

### 9. `league/openapi-psr7-validator` as the named 3.1-compatible example — **FLAG: Medium**

The Deferred section reads: *"OpenAPI validator library — the build story picks any maintained 3.1-compatible one (e.g. `league/openapi-psr7-validator`)."*

This is **factually incorrect**. `thephpleague/openapi-psr7-validator`'s own README states it validates PSR-7 messages "against OpenAPI (3.0.x) specifications" — it does not support 3.1. It is built on top of `cebe/php-openapi`, whose own OpenAPI 3.1 support request (issue #101, PR #128) has sat unmerged for years; 3.1 support only exists in forks (`devizzent/cebe-php-openapi`, `php-openapi/openapi`) that provide the underlying **spec parser**, not a PSR-7 request/response **validator**. As of this review, no direct PSR-7 validator with confirmed native OpenAPI 3.1 support surfaced in the PHP ecosystem search; closest current candidates for the team to evaluate are `gertjuhh/symfony-openapi-validator` (Symfony-test-oriented, last updated 2026-01) and the Membrane validation library (updated 2026-05).

**Impact:** this is inside "Deferred" (an explicitly non-committed placeholder, not an ADOPTED decision), and the doc's own hedge — "any maintained 3.1-compatible one" — already anticipates picking a real one later. But naming a wrong example risks someone reaching for it by default and discovering only at CI-integration-suite build time (AD-4, AD-11) that it silently only checks 3.0.x semantics against a 3.1 contract file. Recommend either removing the parenthetical example or replacing it with a verified 3.1-capable candidate once the team evaluates one.

Sources:
- https://github.com/thephpleague/openapi-psr7-validator
- https://github.com/thephpleague/openapi-psr7-validator/blob/master/README.md
- https://github.com/cebe/php-openapi/issues/101
- https://github.com/cebe/php-openapi/pull/128
- https://packagist.org/packages/php-openapi/openapi

### 10. RunCloud: Laravel support, per-release symlink web root, app-agnostic backups — VERIFIED with one caveat

- **Laravel support:** RunCloud has an official "Installing Laravel on RunCloud" guide and a Git-deployment guide; both confirm Laravel is a first-class supported app type, and that the "Public Path" web-app setting is what maps the webserver root to Laravel's `public/` directory.
- **Custom web root via `publicPath`:** RunCloud's v3 API documents `publicPath` as a free-form settings field (examples given in docs include values like `/disabled` or `/launch_version`), confirming it accepts arbitrary custom paths, not just a fixed `/public`. RunCloud's Atomic Deployment feature independently confirms the underlying pattern the spine assumes — Nginx web root pointed at `current/public` with a `current` symlink swapped between `releases/*` — as the standard atomic-deploy shape (this is also the universally standard Capistrano/Deployer-style pattern, independently corroborated across multiple deployment guides).
- **Caveat (Low):** No single RunCloud doc was found that explicitly shows "point `publicPath` through a `current` symlink you manage yourself outside RunCloud's own Git Atomic Deployment feature" (the spine's design uses a custom GitHub Actions rsync pipeline, not RunCloud's built-in Git deploy). The pieces (arbitrary `publicPath` string; symlink-swap being RunCloud's own internal mechanism for its Atomic Deployment) both check out individually, and the composite pattern is extremely standard practice — but it is inferred, not found as one documented walkthrough matching this exact self-managed variant.
- **App-agnostic backups:** Confirmed. RunCloud Backup operates "at the server level," covering web app files and databases regardless of framework, with configurable retention and third-party storage destinations (S3, DO Spaces, SFTP) — matching AD-14's "RunCloud Backup covers www and portal, files and databases" framing.

Sources:
- https://runcloud.io/docs/installing-laravel-on-runcloud
- https://runcloud.io/docs/laravel-git
- https://runcloud.io/docs/api/v3/api-8617197 (settings/fpmnginx `publicPath`)
- https://runcloud.io/docs/an-introduction-to-git-atomic-deployment
- https://runcloud.io/docs/creating-backups
- https://runcloud.io/docs/category/backup

### 11. DDEV custom providers (`providers/*.yaml`) — VERIFIED

DDEV's official "Hosting Provider Integration" docs (docs.ddev.com/en/stable/users/providers/) confirm `ddev pull`/`ddev push` read a YAML recipe per provider under `.ddev/providers/<name>.yaml`, with `auth_command`, `db_pull_command`/`db_push_command`, `files_import_command`/`files_push_command` stanzas, and that custom/self-authored provider files (not just built-in ones) are explicitly supported — matching the spine's CAP-7 (`apps/www/.ddev/providers/prod.yaml`) design exactly.

Sources:
- https://docs.ddev.com/en/stable/users/providers/
- https://github.com/ddev/ddev/blob/main/docs/content/users/providers/index.md
- https://github.com/ddev/ddev/pull/3373

---

## Items not independently re-verified (out of explicit scope, low risk)

- MariaDB "as provisioned by RunCloud" — no specific version pinned, nothing to falsify.
- Pest / Laravel Pint / PHPUnit / PHPCS (WordPress Coding Standards) as test/style tooling — all long-standing, unambiguously real and current tools; not checked in depth as none of these represent an unusual or time-sensitive claim.
- WP Umbrella (mentioned once, AD-14) — treated as a known real product, not independently re-verified since it's not on the explicit checklist.
