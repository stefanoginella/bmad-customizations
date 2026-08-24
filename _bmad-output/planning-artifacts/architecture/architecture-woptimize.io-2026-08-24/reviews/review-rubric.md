# Rubric Review — ARCHITECTURE-SPINE.md (woptimize.io)

- **Reviewed:** `ARCHITECTURE-SPINE.md` (2026-08-24 draft)
- **Against:** `_bmad-output/specs/spec-repo-structure/SPEC.md` + companions (`repo-layout.md`, `release-and-deploy.md`)
- **Lens:** rubric walk, 8 checklist items
- **Respected context:** greenfield, solo intermediate dev, one Netcup VPS + RunCloud, GitHub free, prod + local only by explicit user decision. None of these are flagged.

## Verdict

Approve with changes. No critical findings. The spine fixes almost every real divergence point, resolves both open questions the spec deferred to it, and is terse and well-formed. Three high findings remain: one silent dimension (monitoring/observability) and two undecided cross-unit seams (the connector-zip hand-off, the connector's client-site PHP/WP floor).

## Checklist walk

| # | Item | Result |
| --- | --- | --- |
| 1 | Fixes real divergence points, misses none | Mostly. 15 ADs cover tokens, contract, state, failure, releases, deploys, migrations, tests, server state, secrets, backups, idioms. Missed: connector-zip hand-off (F-2), connector runtime floor (F-3), JS package management (F-6). |
| 2 | Every Rule is enforceable | Mostly. AD-3, AD-4, AD-8, AD-11 have hard CI enforcement. AD-7 is stated but has no test obligation (F-5). AD-1 and AD-10 are review-enforced — acceptable for a solo dev at this altitude. |
| 3 | Deferred cannot let two units diverge | Pass. Each deferred item is internal to one unit (portal internals, validator lib, phone-home cadence, umami exposure, CI internals) or gated on a trigger (staging, Node 26, shared UI). Nothing deferred sits on a seam between two units. |
| 4 | Named tech versions pinned | Mostly. PHP 8.4, Laravel 13, WordPress 7.1, Node 24 LTS, Tailwind 4.3, Style Dictionary 5.5, OpenAPI 3.1 — pinned. MariaDB names no major; DDEV floats on "latest" (F-7). Web-verification is another lens's job — not done here. |
| 5 | Covers CAP-1..CAP-7 | Pass. The Capability → Architecture Map assigns every capability. Both SPEC open questions (portal deploy mechanics, playground tests in CI) are answered: AD-9 and AD-11. CAP-6's hand-off is assigned but under-decided (F-2). |
| 6 | Every owned dimension decided / deferred / open | One whole dimension is silent: monitoring/observability (F-1). Covered: deployment & environments (diagram + explicit prod/local decision), infra/provider (Stack), operations (AD-12), backups (AD-14), secrets (AD-13). |
| 7 | Diagrams valid mermaid, real structure | Pass. Both `graph` blocks parse (quoted labels, `-. text .->` dotted edges, labeled subgraphs) and carry real structure: the dependency graph encodes the paradigm's edge rules; the deployment graph shows CI → VPS → clients → local. No placeholders. |
| 8 | Terse, decisions not rationale | Pass. Prevents/Rule pairs are one to three lines, no filler, no template comments, rationale kept out. |

## Findings

### Critical

None.

### High

**F-1 — Monitoring/observability is a silent dimension.**
- **Points at:** whole spine — no AD, no Deferred entry, no Open Question. The rubric's operational envelope names it explicitly.
- **Problem:** Nothing says who or what notices a down portal, a fatal on www, a failed deploy, a dead umami container, or a client site that stopped phoning home. WP Umbrella appears only as a backup layer (AD-14). Umami is product analytics, not observability.
- **Fix:** Add one AD (or at minimum a Deferred entry with a trigger). Solo-dev-sized is fine, e.g.: "Uptime: WP Umbrella (www) + one external check on portal. Errors: Laravel log at `shared/storage/logs`, WP debug log path fixed; both included in RunCloud backup scope. Deploy failures: GitHub Actions notifications." One table row each — no new tooling required.

**F-2 — The CAP-6 zip hand-off between connector CI and portal is undecided.**
- **Points at:** Capability map row CAP-6; AD-8; AD-11.
- **Problem:** `plugin-v*` tag → CI builds the zip → "the portal serves it". How the zip travels from the Actions runner to the portal, and what the portal's source of truth for "latest version" is (upload endpoint? rsync into portal storage? GitHub release asset proxied?), is decided nowhere. This is a seam between two independently built units — the connector workflow and the portal update endpoint can each invent a different answer.
- **Fix:** One sentence in AD-8 or a new AD: name the transport (e.g., "the connector workflow rsyncs the zip into portal `shared/storage/connector-releases/` and the portal reads latest from the filenames" — or an authenticated upload endpoint) and name the single writer of "latest version" (AD-6 style).

**F-3 — The connector's client-site PHP/WP floor is unpinned.**
- **Points at:** Stack table; AD-7; AD-11.
- **Problem:** Stack pins PHP 8.4 / WP 7.1 for WOptimize's own apps, but `packages/connector` runs on arbitrary client sites WOptimize does not control. PHP 8.4 syntax on an older client PHP fatals at parse time — before AD-7's no-op guard can execute — which breaks AD-7's own guarantee. Connector code and its tests need a floor to build against, or every connector story guesses one.
- **Fix:** Add a Stack row: "Connector floor: PHP ≥ x.y, WP ≥ a.b" (whatever the user chooses), and note that the AD-11 matrix runs the connector at that floor. If all client sites are WOptimize-managed at 8.4 by policy, say that in one line instead — the decision just has to exist.

### Medium

**F-4 — Contract direction is ambiguous in AD-4/AD-5.**
- **Points at:** AD-4, AD-5.
- **Problem:** `openapi.yaml` is "the single source of truth for the contract", but AD-5 governs only "connector REST endpoints" (portal → connector). The reverse direction — phone-home and update-check endpoints on the portal that the connector calls — is not explicitly placed in the file. Two units could end up with one specified direction and one invented-in-code direction, which AD-4 exists to prevent.
- **Fix:** One clause in AD-4: "The file specifies both directions: connector endpoints under `woptimize/v1` and the portal endpoints the connector calls (phone-home, update check/download)."

**F-5 — AD-7 (no-op invariant) has no enforcement.**
- **Points at:** AD-7; AD-11.
- **Problem:** CAP-3's success criterion ("an offboarded site runs with the orphaned connector as pure no-ops") is the one spec success criterion with no test hooked to it. AD-11 defines the suite but does not require the failure scenarios.
- **Fix:** Add to AD-11's Rule: "The suite includes the AD-7 scenarios: bad key, unreachable portal, offboarded site — each must produce a silent no-op."

**F-6 — JS package management / workspace layout is silent.**
- **Points at:** Consistency Conventions; Structural Seed.
- **Problem:** At least three units need Node tooling (`packages/design-tokens` build, portal Tailwind, likely the www theme). Whether the repo uses a root workspace or per-app `package.json`, and which package manager, is neither decided nor deferred — the first two features built independently will diverge.
- **Fix:** One Consistency Conventions row, e.g. "Node: npm, per-app `package.json`, no root workspace" (or the workspace variant — either is fine, one must be named). Same one-liner for Composer ("per-app `composer.json`, no root") closes the PHP side.

### Low

**F-7 — Two Stack rows float.**
- **Points at:** Stack table.
- **Problem:** MariaDB is "as provisioned by RunCloud" with no major named — the rule ("DDEV mirrors the same major") is good, but three DDEV configs (www, portal, playground) each need the number. DDEV "latest" in CI can break the AD-11 suite on a bad upstream release.
- **Fix:** Once the RunCloud app is provisioned, write the MariaDB major into the table. Pin the DDEV version in `github-action-setup-ddev` and note "bump deliberately".

**F-8 — `UMAMI_*` secrets imply a workflow that does not exist.**
- **Points at:** AD-13; Consistency Conventions (CI workflows row); Structural Seed.
- **Problem:** The conventions name exactly three workflows (`www.yml`, `portal.yml`, `connector.yml`), but AD-13 reserves a `UMAMI_` secret prefix. Either umami deploys via CI (then a fourth workflow belongs in the convention) or via the AD-12 SSH exception (then the prefix is dead weight until needed).
- **Fix:** Pick one: add `umami.yml` to the convention row, or drop `UMAMI_` from AD-13 with "add when umami gets a pipeline".

**F-9 — Playground's DDEV config is not in the Structural Seed.**
- **Points at:** Structural Seed; AD-11.
- **Problem:** AD-11 hinges on "CI reuses the same `.ddev` config", but the seed shows `.ddev/` only under `apps/www`. Whether playground is its own DDEV project or rides inside www's is ambiguous — and it decides where the "one command" runs.
- **Fix:** Add `apps/playground/.ddev/` to the seed (or a comment stating playground lives inside the www DDEV project) so AD-11's one command has an unambiguous home.

## Deliberate decisions checked and NOT flagged

- Prod + local only, no staging — explicit user decision; correctly parked in Deferred with a revisit trigger.
- Repo-level secrets without GitHub environments — forced by GitHub free; AD-13 handles the collision risk with prefixes.
- Single VPS, RunCloud panel as an allowed change path — matches the solo-dev context; AD-12 contains it.
- No license system, no shared UI library, umami-not-a-codebase — spec non-goals, honored.
