---
id: SPEC-repo-structure
companions:
  - repo-layout.md
  - release-and-deploy.md
  - ../../planning-artifacts/architecture/architecture-woptimize.io-2026-08-24/ARCHITECTURE-SPINE.md
sources:
  - ../../forge/monorepo-vs-multirepo/forged-idea.md
---

> **Canonical contract.** This SPEC and the files in `companions:` are the complete, preservation-validated contract for what to build, test, and validate. Source documents listed in frontmatter are for traceability — consult them only if you need narrative rationale or prose color this contract intentionally omits. Where this SPEC and `ARCHITECTURE-SPINE.md` (the spine) state the same fact, the spine supersedes.

# WOptimize Repo Structure

## Why

A pain to pre-empt: WOptimize is one product across several surfaces — a WP marketing site, a Laravel client portal, a connector plugin installed on client sites, and one design language. Separate repos would force private-package publishing or copy-drift for the token pipeline and would split the plugin/portal contract from its integration tests; the access-boundary argument for splitting is dead, since any future hire working on the portal needs the site repo too. One GitHub monorepo keeps contract, tokens, and tests together. Decision forged and hardened 2026-08; this spec is the build contract for that structure.

## Capabilities

- **CAP-1**
  - **intent:** All WOptimize code lives in one GitHub monorepo — www theme + site plugin, portal, playground client site, design tokens, connector, Umami infra, CI workflows — laid out per `repo-layout.md`.
  - **success:** A fresh clone plus per-app setup yields working local dev for www and portal; every path in `repo-layout.md` exists.
- **CAP-2**
  - **intent:** One design-token source (fonts, colors, typography, spacing, motion, buttons, links) builds the style artifacts both apps consume: `theme.json` + stylesheet for WP, Tailwind 4 `@theme` CSS file + stylesheet for Laravel.
  - **success:** Editing one token and rebuilding changes the built styles of both apps; no built artifact is hand-edited.
- **CAP-3**
  - **intent:** Connector and portal talk both ways: the connector registers REST endpoints the portal calls with a site key, the connector phones home, and the portal serves connector update zips.
  - **success:** The portal can call a client site and deliver an update; an offboarded site runs with the orphaned connector as pure no-ops.
- **CAP-4**
  - **intent:** A push deploys only the apps whose paths changed.
  - **success:** A commit touching only `apps/portal` triggers no www deploy, and vice versa.
- **CAP-5**
  - **intent:** www and portal deploys land as immutable per-commit releases with instant rollback (mechanics: `release-and-deploy.md`).
  - **success:** One command flips back to the previous release, no rebuild.
- **CAP-6**
  - **intent:** Tagging `plugin-v*` releases the connector: CI builds the zip from its folder and the portal serves it to client sites.
  - **success:** After a tag, client sites see and can install the new version.
- **CAP-7**
  - **intent:** Production content syncs to local dev: pull db + files routinely; push exists for pre-launch only, behind a double confirmation.
  - **success:** One DDEV command refreshes local db + files from production.

## Constraints

- DDEV project root is `apps/www` with `docroot: wordpress`, and the wp-content symlinks are relative — anything else puts symlink targets outside the container mount.
- `apps/www` in git carries only the custom theme and one site plugin; the full WP install is gitignored.
- No raw RunCloud webhooks on the monorepo — every push would deploy everything.
- The portal supports the current + previous connector minor version; a contract change requires a minor (or major) bump and ships connector-first (full policy: `release-and-deploy.md`).
- Portal migrations stay expand/contract — a symlink rollback is always safe without a DB restore (spine AD-10).
- The design system stays tokens + base styles until both apps need the same component — envelope, not truck.
- After launch, content flows one way: production → local. `ddev push` on a live site destroys content edits; the pre-launch push carries a double confirmation — DDEV's own confirm plus a typed production hostname.
- Release rollback covers code only; DB and uploads roll back via backups.

## Non-goals

- Separate repos per app.
- A license system for the connector — offboarding is uninstall; the orphaned plugin becomes pure no-ops.
- Umami as a codebase — `infra/umami` holds compose + env only.
- A shared UI component library ahead of shared need.

## Success signal

- One token edit rebuilds into both apps' styles; a push touching one app deploys only that app; a bad www or portal deploy is undone with one command; an offboarded client site keeps running with the connector doing nothing.
