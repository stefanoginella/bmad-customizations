---
type: sprint-change-proposal
project: woptimize.io
created: '2026-08-25'
status: approved
scope: minor
trigger: 'New requirement — internal admin dashboard on admin.woptimize.io'
targets:
  - ../specs/spec-repo-structure/SPEC.md
  - ../specs/spec-repo-structure/repo-layout.md
  - ../specs/spec-repo-structure/release-and-deploy.md
  - ../specs/spec-repo-structure/stories.yaml
  - architecture/architecture-woptimize.io-2026-08-24/ARCHITECTURE-SPINE.md
---

# Sprint Change Proposal — Admin surface on the portal

## 1. Issue Summary

**Problem.** WOptimize needs an internal admin dashboard — plans, client
handling, tracking — reachable at `admin.woptimize.io`. No planning artifact
covers it. The spine's Hostnames convention lists only `www`, `portal`, and
`data`, so `admin.woptimize.io` has no home, and no story owns the surface.

**Issue type.** New requirement from the stakeholder. Not an implementation
failure, not a misunderstanding of an earlier requirement.

**Discovered.** 2026-08-25, after stories 1–5 shipped (latest commit `188bd5d`),
while asking whether the customer portal could carry a separate admin domain.

**Evidence.**

- `ARCHITECTURE-SPINE.md`, Consistency Conventions → Hostnames: no `admin` entry.
- `SPEC.md`: capabilities CAP-1…CAP-7 contain no admin surface.
- `stories.yaml`: stories 1–11 contain no admin work.
- `apps/portal/routes/web.php` holds one route; no domain split exists.

**The risk if left unrecorded.** A builder faced with "add an admin" has two
readings: a second Laravel app under `apps/`, or a surface of `apps/portal`. The
first reading is the natural one under AD-1 ("apps are independently deployable
products") and it is wrong — a separate app owns a separate database (AD-2) and
could never read the site registry the admin exists to manage (AD-6). The
correction closes that reading.

## 2. Impact Analysis

### Story impact

| Stories | Impact |
| --- | --- |
| 1–5 (built) | **None.** No rewrite, no rollback. |
| 6–11 (backlog) | **None.** Scope, order, and content unchanged. |
| 12 (new) | Admin dashboard scaffold. Depends on story 8. |

### Artifact impact

| Artifact | Change |
| --- | --- |
| `ARCHITECTURE-SPINE.md` | New invariant AD-20; Hostnames row; AD-9 portal bullet; AD-19 uptime line; Structural Seed; capability map row; two Deferred entries; frontmatter `binds` + `updated`. |
| `SPEC.md` | New CAP-8; three constraints; two non-goals; success signal clause. |
| `repo-layout.md` | Tree comment; new "apps/portal hostnames" section. |
| `release-and-deploy.md` | Portal deploy bullet; new no-own-workflow bullet. |
| `stories.yaml` | New story 12. |
| UX documents | None exist. N/A. |

### Technical impact

- **Code:** `apps/portal` only — a second route file, an `ADMIN_DOMAIN` config
  key with a non-null fallback, and an admin guard backed by its own table.
  Story 12's work.
- **Local dev:** one line in `apps/portal/.ddev/config.yaml`
  (`additional_hostnames: [admin.woptimize]`), then `ddev restart`. DDEV appends
  the `.ddev.site` TLD and puts both names in the project certificate. No
  `/etc/hosts` edit, no sudo.
- **CI:** none. The admin has no workflow and no path filter of its own — it is
  `apps/portal` code, deployed by `portal.yml`.
- **Infrastructure:** one alias domain plus Basic SSL on the existing portal
  RunCloud web app, added in the panel (AD-12). `ADMIN_DOMAIN` goes into
  `shared/.env`.
- **Database:** one additive table, `admin_users`, built in story 12 — nothing
  else. For every fact the portal already owns, the admin reuses the portal's
  schema and the portal's own registry code; it never becomes a second writer
  (AD-6). The migration is additive, so AD-10 rollback stays safe.
- **Observability:** one extra external uptime check on the admin host. Domain
  routing can fail alone: a wrong `ADMIN_DOMAIN` leaves the portal healthy and
  the admin dead, which the portal check cannot see.

### Invariants examined

| Invariant | Verdict |
| --- | --- |
| AD-1 sealed app borders | Holds. The admin is not an app; nothing new imports across a border. |
| AD-2 one runtime coupling | Holds — and is the reason for the one-app decision. Two apps would need a second runtime link or a shared database. Both are forbidden. |
| AD-6 one writer per fact | Holds, with a new explicit clause in AD-20: admin writes reuse the portal's registry code. |
| AD-9 one deploy model | Extended, not changed. One release and one flip serve both hostnames. |
| AD-10 expand/contract | Holds. Story 12's `admin_users` table is an additive migration; a symlink rollback still needs no DB restore. |
| AD-13 secrets placement | Holds. No new prefix; `PORTAL_*` covers both surfaces. |
| AD-15 idiom border | Untouched. The admin is Laravel, inside Laravel. |
| AD-19 observability | Extended by one uptime check. |
| CAP-4 path filters | Unchanged. |

## 3. Recommended Approach

**Direct Adjustment (Option 1).** Add one capability, one invariant, one story,
and the supporting prose. Nothing is rolled back and no MVP goal is cut.

- **Effort:** Low. Documentation now; one scaffold story later.
- **Risk:** Low. Nothing built changes. The one real hazard —
  `Route::domain(null)` matching every host — is written into AD-20 and into
  story 12's dev note.
- **Timeline:** No slip. Story 12 sits after story 11 and depends on story 8.

**Options rejected.**

- *Rollback* — nothing to roll back. Not viable.
- *MVP review* — the MVP is unchanged; the admin is additive. Not viable.
- *A separate `apps/admin`* — a real app border means a separate database
  (AD-2), so the admin could not read the site registry. It would need a third
  API contract to reach the portal. Rejected on cost and on AD-2.

**Scope boundary.** This correction fixes the **surface** only: hostname,
routing, identity, deploy, and observability. What the admin *does* — plans,
billing, client tracking, impersonation — goes to a later spec and is recorded
under the spine's Deferred section.

## 4. Detailed Change Proposals

### 4.1 `ARCHITECTURE-SPINE.md`

**Frontmatter**

```
OLD:  updated: '2026-08-24'
      binds: [CAP-1, CAP-2, CAP-3, CAP-4, CAP-5, CAP-6, CAP-7]
NEW:  updated: '2026-08-25'
      binds: [CAP-1, CAP-2, CAP-3, CAP-4, CAP-5, CAP-6, CAP-7, CAP-8]
```

**New invariant AD-20** (after AD-19)

> ### AD-20 — Two surfaces, one app
>
> - **Binds:** CAP-1, CAP-8, `apps/portal`, AD-2, AD-6, AD-9
> - **Prevents:** a second Laravel app that cannot read the portal database;
>   admin routes answering on the client hostname; two deploys for one codebase;
>   a second writer for a fact the portal already owns; a client account
>   escalating into admin authority.
> - **Rule:** The admin dashboard is a **surface** of `apps/portal`, not an app.
>   One codebase, one database, one release, one deploy job, one rollback.
>   Routes split by domain — client routes on the portal host, admin routes on
>   the admin host, in separate route files; no route is registered on both.
>   The admin surface authenticates against its **own guard and its own table**
>   (`admin_users`). No client account can hold admin authority — there is no
>   role on `users` that grants it, and a client session carries none on the
>   admin host. Every admin route sits behind that guard; an unauthenticated
>   request reaches the admin login and nothing else. The admin domain is config
>   (`ADMIN_DOMAIN`) and **must never resolve to null** — a null domain matches
>   every host. Admin writes reuse the portal's own registry code (AD-6); the
>   admin never opens a second write path. No new CI workflow, no new secret
>   prefix — `PORTAL_*` covers both surfaces.

**Hostnames row**

```
OLD: ... portal: `portal.woptimize.io` (local `portal.woptimize.ddev.site`);
     umami: `data.woptimize.io`. ...
NEW: ... portal: `portal.woptimize.io` (local `portal.woptimize.ddev.site`);
     admin: `admin.woptimize.io` (local `admin.woptimize.ddev.site`, via
     `additional_hostnames` in the portal's DDEV config) — the same app on a
     second domain, never a second app (AD-20); umami: `data.woptimize.io`. ...
```

**AD-9, portal bullet** — append:

> One release serves both the portal and the admin hostname; the `current` flip
> moves both at once, so rollback is still one command.

**AD-19, uptime line**

```
OLD: one external uptime check watches the portal and umami
NEW: external uptime checks watch the portal host, the admin host, and umami —
     the admin host is checked separately because domain routing can fail alone
     (a wrong ADMIN_DOMAIN leaves the portal healthy and the admin dead)
```

**Structural Seed** — `portal/` comment

```
OLD: portal/    # Laravel 13 client portal; own .ddev; tokens...
NEW: portal/    # Laravel 13 client portal + admin dashboard — two hostnames,
                #   one app (AD-20); own .ddev; tokens...
```

**Capability map** — new row

| CAP-8 admin surface | `apps/portal` (admin routes) | AD-20, AD-2, AD-6, AD-9 |

**Deferred** — new entry

> - **Admin feature scope** — plans, billing, client tracking, impersonation.
>   This correction fixes the surface only. A later spec defines what the admin
>   does.
> - **Admin UI tool** — Filament versus plain Blade + tokens. Story 12 picks,
>   after verifying Filament's Laravel 13 support. The spine stays tool-neutral.

**Deliberately unchanged:** the dependency mermaid graph. An `admin` node would
read as a separate app and contradict AD-20.

### 4.2 `SPEC.md`

**New capability** (after CAP-7)

> - **CAP-8**
>   - **intent:** The portal serves an internal admin dashboard on
>     `admin.woptimize.io` — the same Laravel app and the same database as the
>     client portal, split by domain and gated by its own guard.
>   - **success:** A client account cannot authenticate on the admin host at all
>     — no role grants it. On the portal host, no admin route resolves. One
>     `ddev start` in `apps/portal` serves both hosts locally.

**Constraints** — two new bullets

> - The admin dashboard is a surface of `apps/portal`, never a separate app. A
>   separate app would own a separate database and could not read the site
>   registry (spine AD-2, AD-20).
> - The admin domain is config (`ADMIN_DOMAIN`) and always resolves to a
>   non-null value — a null domain matches every host and would answer admin
>   routes on the client portal.
> - Admin identity lives in its own table with its own guard, never a role flag
>   on the client `users` table (spine AD-20). The `admin_users` migration is
>   additive, so rollback stays safe (AD-10).

**Non-goals** — two new bullets

> - A separate admin app, admin repo, or admin database.
> - Admin feature scope — plans, billing, client tracking, impersonation. This
>   spec fixes the surface only.

**Success signal** — append

```
OLD: ... and an offboarded client site keeps running with the connector doing
     nothing.
NEW: ... an offboarded client site keeps running with the connector doing
     nothing; and a client account reaches no admin route, on either hostname.
```

**Deliberately unchanged:** the `## Why` section. The monorepo rationale is
untouched — the admin sits inside an existing app.

### 4.3 `repo-layout.md`

**Tree comment**

```
OLD:     portal/         Laravel client portal
NEW:     portal/         Laravel client portal + admin dashboard — one app, two hostnames
```

**New section** (after `## apps/www layout`)

> ## apps/portal hostnames
>
> - One Laravel app serves two hostnames: the client portal and the admin
>   dashboard (spine AD-20). Not two apps, not two databases.
> - Production: one RunCloud web app — `portal.woptimize.io` primary,
>   `admin.woptimize.io` alias, both on one Basic SSL certificate. An alias
>   **domain**, never an alias *web app*.
> - Local: `portal.woptimize.ddev.site` and `admin.woptimize.ddev.site`. The
>   second comes from `additional_hostnames: [admin.woptimize]` in
>   `apps/portal/.ddev/config.yaml`. DDEV appends the `.ddev.site` TLD and puts
>   both names in the project certificate — no `/etc/hosts` edit and no sudo.
> - Routes split by domain into separate route files. The domain comes from
>   config (`ADMIN_DOMAIN`), never a literal inside a route file.
> - Admin identity is its own table (`admin_users`) with its own guard — never a
>   role flag on the client `users` table.

### 4.4 `release-and-deploy.md`

**Portal deploy bullet** — append

> One release serves both the portal and the admin hostname; the `current` flip
> moves both at once, so rollback stays one command. `ADMIN_DOMAIN` lives in
> `shared/.env` — never in a release, never in git.

**New bullet under `## Deploys`**

> - The admin dashboard gets **no** workflow and **no** path filter of its own.
>   It is `apps/portal` code, deployed by `portal.yml` (AD-20).
>   `admin.woptimize.io` is an **Alias domain** on the existing portal RunCloud
>   **web app** — never an alias *web app*, which would be a second app —
>   covered by the same Basic SSL certificate (one certificate, all domains on
>   the app). Added in the panel, never by ad-hoc SSH (AD-12).

**Deliberately unchanged:** the connector-release and content-sync sections.

### 4.5 `stories.yaml`

**New entry, appended after story 11**

```yaml
- id: "12"
  title: Admin dashboard scaffold
  description: >-
    Add the admin surface to apps/portal — one app, two hostnames (CAP-8,
    AD-20): admin.woptimize.ddev.site locally via additional_hostnames,
    admin.woptimize.io in production as an alias domain on the portal RunCloud
    web app, domain-split route files, an ADMIN_DOMAIN config with a non-null
    fallback, and admin identity in its own admin_users table with its own
    Laravel guard — never a role flag on users. Scaffold only — no plans,
    billing, or client tracking. Pick the admin UI tool here (check Filament's
    Laravel 13 support; plain Blade + tokens is the fallback).
  depends_on: ["8"]
  spec_checkpoint: true
  done_checkpoint: true
  invoke_dev_with: >-
    Route::domain(null) matches every host — ADMIN_DOMAIN must never resolve to
    null, or admin routes answer on the client portal. Verify three ways: an
    admin route 404s on the portal host; a client session carries no authority
    on the admin host; and the admin_users migration is additive, so a symlink
    rollback needs no DB restore (AD-10). The production alias domain and its
    SSL go through the RunCloud panel, never ad-hoc SSH (AD-12).
```

`depends_on` is a new key in this file — no earlier story uses one.

## 5. Implementation Handoff

**Scope classification: Minor.** Documentation edits inside existing artifacts
plus one backlog entry. No epic reorganization, no replan.

| Recipient | Responsibility |
| --- | --- |
| Developer agent (this session) | Apply §4.1–§4.5 exactly as written. Documentation only — no code, no DDEV config, no `apps/portal` change. |
| `bmad-project-context` (follow-up) | Refresh the `bmad:context` block in `AGENTS.md` so the admin surface and the two-hostname rule reach every future agent. Outside this workflow's ownership — `AGENTS.md` is managed by that skill. |
| Story 12 (later) | Build the scaffold. Blocked by story 8. |

**Success criteria for this correction**

1. `ARCHITECTURE-SPINE.md` carries AD-20, and its Hostnames row names
   `admin.woptimize.io` and `admin.woptimize.ddev.site`.
2. `SPEC.md` carries CAP-8; the spine `binds` list includes CAP-8.
3. `stories.yaml` ends at story 12; stories 1–11 are byte-identical.
4. No file under `apps/` or `packages/` changed by this correction.
5. A reader who asks "should the admin be its own app?" finds the answer, and
   the reason, in AD-20.

## 6. Amendments during review (2026-08-25)

Two decisions taken after the first draft, folded into §4 above:

1. **Identity.** "Gated by role" was vague and quietly implied a role column on
   `users`. Replaced: the admin authenticates against its own guard and its own
   `admin_users` table. A client account cannot hold admin authority, because
   there is no column to flip. Recorded in AD-20, CAP-8, and the SPEC
   constraints.
2. **RunCloud vocabulary.** "Second domain" replaced with RunCloud's own term —
   an **Alias domain** on the existing web app — plus an explicit warning
   against the panel's separate "alias web app" feature, which would create the
   second app AD-20 forbids. Basic SSL covers every domain on the app with one
   certificate.

Verified against the RunCloud documentation: a web app takes exactly one primary
domain plus any number of alias domains, and Basic SSL issues one certificate
covering them all.

Options weighed and rejected during review:

- **Path prefix (`portal.woptimize.io/admin`)** — fewer moving parts and no
  null-domain hazard, but the admin surface stays discoverable from the client
  host and the session cookie is shared. Because `ADMIN_DOMAIN` is config, this
  stays a one-value switch if the domain ever proves not worth its cost.
- **A role column on `users`** — simplest, but security would then rest on never
  forgetting a gate. Rejected for a portal that holds the site registry.
- **Committing to Filament now** — deferred; its Laravel 13 support is
  unverified. Story 12 picks the tool.
