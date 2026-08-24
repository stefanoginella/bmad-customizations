---
type: review
lens: adversarial
target: ../ARCHITECTURE-SPINE.md
sources:
  - ../../../../specs/spec-repo-structure/SPEC.md
  - ../../../../specs/spec-repo-structure/repo-layout.md
  - ../../../../specs/spec-repo-structure/release-and-deploy.md
date: '2026-08-24'
reviewer: adversarial (pairwise incompatibility attack)
---

# Adversarial Review — woptimize.io Architecture Spine

**Method.** For each pair of build units one level below the spine (theme builder, portal builder, connector builder, portal API builder, tokens builder, playground builder, CI author, deploy story author, DDEV config author, umami infra author), I asked: can both units obey every AD and convention to the letter and still produce parts that do not fit together? Every "yes" is a finding. Severity is calibrated for the real context: greenfield, solo intermediate developer, AI builders implementing most code from this spine. A hole such a developer hits in the first weeks ranks above a theoretical multi-team hazard.

**Overall.** The spine is strong on the shape of the system (one runtime coupling, one writer per fact, one deploy model) and weak at the seams where two builders must produce matching halves of one mechanism. Three seams are open wide enough that the two halves cannot meet at all: the portal side of the API contract, the site-key handshake, and the update-delivery pipeline. Several more produce first-week breakage (theme.json ownership, token build order, path filters).

---

## CRITICAL

### C1 — Half of the runtime contract has no home: the portal-hosted API surface

- **Pair:** the connector builder × the portal API builder.
- **The hole.** The system needs endpoints on BOTH ends: the connector hosts `woptimize/v1` WP REST endpoints (the portal calls them), and the portal hosts endpoints the connector calls — phone-home receiver, update check, update-zip download. But every contract rule points only at the connector side:
  - AD-4: the source of truth is `packages/connector/openapi.yaml`, and CI "validates real **connector** responses against the file." Portal responses are never validated.
  - AD-5: "All **connector** REST endpoints live under `woptimize/v1`." It says nothing about where portal endpoints live.
  - AD-2 declares "the only runtime link is the `woptimize/v1` REST contract" — but the connector's calls TO the portal are not `woptimize/v1` WP REST routes. Read literally, the portal-hosted half either does not exist or is exempt from every contract rule.
- **Legal incompatible builds.** The connector builder codes phone-home against `POST {portal}/api/connector/v1/checkin` with a JSON body it invents. The portal API builder ships `POST /api/v1/phone-home` with a different body, different error shape, no entry in any YAML. Both are fully AD-compliant. Nothing connects. And even if both halves land in the YAML, CI only ever checks the connector's responses, so the portal can drift silently forever — the exact failure AD-4 exists to prevent.
- **Fix — tighten AD-4 and AD-5:**
  - AD-4: `packages/connector/openapi.yaml` describes **both directions** of the contract: connector-hosted endpoints (server base: the client site's `woptimize/v1` REST base) and portal-hosted endpoints (server base: a named path on the portal, e.g. `/api/connector/v1/*` — name it in the AD). The CI integration suite validates **connector responses AND portal responses** against the file.
  - AD-5: extend the namespace rule: portal-hosted contract endpoints live under the named portal base path; the same additive-only rule applies inside it.

### C2 — Site-key issuance: AD-6 legally supports both directions of the handshake

- **Pair:** the portal builder × the connector builder.
- **The hole.** AD-6 says the portal owns the site registry including "site key" — which reads as "the portal issues keys." The same AD says "the connector owns nothing durable except **its own key** and settings in `wp_options`" — which reads as "the key is the connector's." Nothing anywhere describes onboarding: who generates the key, and how it reaches the other side.
- **Legal incompatible builds.** The portal builder builds: admin creates a site in the portal UI → portal generates the key → human pastes it into the connector's settings screen. The connector builder builds: connector generates a key on activation → POSTs it to a portal registration endpoint → portal records it. Each build is internally complete, cites AD-6, and passes its own tests. Together: no site can ever be onboarded, because each side waits for a key the other side never sends.
- **Fix — new AD (key lifecycle):**
  - The **portal issues** every site key at onboarding (portal UI creates the site record + key). The key is entered into the connector's settings screen by a human for v1.
  - The connector **never** generates, derives, or changes a key. Its `wp_options` copy is a cache of a portal-issued fact.
  - Rotation: the portal issues a replacement key and invalidates the old one; a connector holding a dead key gets `401` and goes no-op (AD-7) until rekeyed.
  - The onboarding flow and any registration/verification endpoint go into `openapi.yaml` before code (per AD-4, extended by C1).

### C3 — The update pipeline is three builders with zero specified handoffs

- **Pair(s):** the CI author × the portal builder; the CI author × the connector builder.
- **The hole.** CAP-6's success criterion ("after a tag, client sites see and can install the new version") crosses three units, and the spine specifies none of the joints:
  1. **Zip handoff.** connector.yml builds the zip — then puts it WHERE? Legal options: rsync into portal `shared/storage/`, attach to a GitHub Release the portal proxies, POST to a portal upload endpoint that does not exist. The portal builder independently picks where to read from: local storage? GitHub API? Both choices are AD-compliant and disjoint.
  2. **Update-check mechanism.** How does a client site "see" the update? The connector builder must pick a WP mechanism (`Update URI:` plugin header + `update_plugins_{$hostname}` filter, or a `pre_set_site_transient_update_plugins` hook) and an expected response shape. The portal builder independently invents an update-check endpoint and response. Neither is in any spec (and per C1, CI would not check the portal's side anyway).
  3. **Zip anatomy.** "CI builds the zip from its folder" — literally zipping `packages/connector/` yields an inner folder named `connector/`. WordPress installs an update into the folder named by the zip's inner directory. If the plugin is installed on client sites as `woptimize-connector/` but the zip's inner folder is `connector/`, the first self-update installs a **duplicate, deactivated** plugin next to the live one — a Woptimize failure breaking a client site, the exact thing AD-7 exists to prevent.
  4. **Version-parseable naming.** The Karin's-rule matrix (AD-11) and the portal's "serve current" logic both need to find "the previous minor" — impossible without a fixed zip naming scheme.
- **Fix — new AD (update delivery):**
  - Plugin slug is fixed: folder `woptimize-connector/`, main file `woptimize-connector.php`, everywhere — repo (`packages/connector` contents are the plugin folder's contents), zip inner folder, client installs.
  - connector.yml on `plugin-v*` builds `woptimize-connector-<semver>.zip` (inner folder `woptimize-connector/`) and rsyncs it to a fixed portal path under `shared/storage/` (name it), which survives release flips per AD-9.
  - The portal serves an update-check endpoint and a zip-download endpoint, both defined in `openapi.yaml` (per C1). The connector uses the `Update URI:` header mechanism pointed at the portal. Auth: the same `X-Woptimize-Site-Key` header.

---

## HIGH

### H1 — `theme.json` has two legal owners, and one of them is forbidden to exist

- **Pair:** the tokens builder × the www theme builder.
- **The hole.** The Structural Seed says `theme.json` is "BUILT here (AD-3)," and AD-3 says built artifacts are gitignored, never hand-edited. But a real WP block theme's `theme.json` holds far more than tokens: layout sizes (`settings.layout.contentSize`), block settings, template parts, style variations. The tokens builder can legally generate the **entire** `theme.json` from DTCG source (AD-3 says Style Dictionary builds "every style artifact... directly to its consumer's canonical path"). The theme builder then has **no legal way** to set any non-token theme.json setting: the file is gitignored and hand-editing is forbidden. Alternatively the theme builder assumes `theme.json` is theirs and tokens only emit `tokens.css` — also a defensible reading. Two owners of one file, or zero.
- **Legal incompatible builds.** Tokens builder ships a Style Dictionary config that overwrites `theme.json` wholesale on every build. Theme builder commits a hand-written `theme.json` with layout/template settings. Every token build clobbers the theme's settings; every fresh clone loses them.
- **Fix — tighten AD-3:** the theme owns a committed base file (e.g. `apps/www/theme/theme.base.json`, hand-edited, in git). The token build **merges** its generated token sections (`settings.color.palette`, `settings.typography`, `settings.spacing`, ...) into the base and emits `theme.json` (gitignored). One writer per section: tokens own the token sections, the theme owns everything else. State the same split for the portal side (tokens emit only the token layer; `app.css` and Tailwind config stay the portal's).

### H2 — Token artifacts: unnamed filenames, and "before rsync" permits a build order that breaks the deploy

- **Pair:** the tokens builder × the portal builder; the tokens builder × the CI author.
- **The hole (three legal divergences):**
  1. **Filenames.** AD-3 fixes artifact paths "by the token build config, never by consumers" — but the consumer must write an `import`/`@import`/enqueue against an exact filename **before or in parallel with** the tokens builder choosing it. `resources/css/tokens.css`? `_tokens.css`? `theme.css`? And AD-3 names two portal artifacts ("Tailwind `@theme` CSS + stylesheet") without saying whether that is one file or two, or what each contains. The portal builder imports a name; the tokens builder emits another. Both legal.
  2. **Ordering.** AD-3 requires deploy jobs to run the token build "before rsync." Building tokens **after** `vite build` is literally "before rsync" — and broken: Tailwind 4 needs the `@theme` CSS at CSS-compile time. A CI author who obeys AD-3 to the letter can ship a portal deploy whose compiled CSS has no tokens in it.
  3. **The command.** "Per-app setup runs the build once locally" — invoked how? `npm run build` inside `packages/design-tokens`? A root-level script? A fresh clone (CAP-1 success criterion) has a portal whose Vite build **fails** on a missing import and a theme whose `theme.json` does not exist, until someone runs a command no document names.
- **Fix — tighten AD-3:** (a) name every artifact path and filename in the AD itself (e.g. `apps/www/theme/theme.json`, `apps/www/theme/assets/css/tokens.css`, `apps/portal/resources/css/tokens.css`); (b) replace "before rsync" with "**before any app asset compile**, locally and in CI — tokens build is always the first build step"; (c) name the one command (e.g. root `package.json` script `tokens:build`) and make it step 1 of every per-app setup and every deploy job.

### H3 — Path filters, obeyed to the letter, make token edits deploy nothing

- **Pair:** the CI author × the tokens builder.
- **The hole.** The convention says each workflow is "path-filtered to its app." A CI author obeying that writes `www.yml: paths: [apps/www/**]` and `portal.yml: paths: [apps/portal/**]`. A commit that edits only `packages/design-tokens/` — the exact CAP-2 flagship scenario, "editing one token changes the built styles of both apps" — matches **no** filter. Nothing deploys. Production styles silently drift from the token source until the next unrelated app-touching push. CAP-4's wording ("a push deploys only the apps whose paths changed") even blesses this reading, because no `apps/` path changed.
- **Fix — tighten the CI convention:** each app workflow's path filter = its own app path **plus every `packages/` path that app consumes at build time**. Concretely: `www.yml` and `portal.yml` also trigger on `packages/design-tokens/**`; `connector.yml` triggers on `packages/connector/**` and `apps/portal/**` (already implied by AD-11).

### H4 — The contract suite lives in connector.yml; the portal deploy lives in portal.yml; nothing connects them

- **Pair:** the CI author × the deploy story author.
- **The hole.** The one-workflow-per-app convention actively creates this: AD-11 puts the integration suite (with its `apps/portal` path trigger) in the connector workflow, while AD-9 puts the portal deploy in `portal.yml`. GitHub Actions runs separate workflow files independently. A push touching `apps/portal` starts both: the deploy can finish (and flip the symlink) **while the contract suite is still failing** in the other workflow. Both authors obeyed every AD; the enforcement AD-4 promises ("CI-enforced") gates nothing.
- **Fix — new AD or tightened AD-11:** the integration suite is a **reusable workflow** (`workflow_call`), invoked as a job by both `connector.yml` and `portal.yml`; the portal deploy job declares `needs:` on it. A portal deploy that has not passed the contract suite in the same run does not happen. (Same pattern for www if the theme grows tests.)

### H5 — Playground seeding: the test target's state is imagined differently by its two users

- **Pair:** the playground builder × the portal-test author.
- **The hole.** AD-11 says the suite runs "with one command (`ddev exec …`) against `apps/playground`" identically local and CI — but nothing says what playground **is** when the command runs: Is WP installed by a committed script, or by hand once? Is the connector present as a symlink from `packages/connector` into `wp-content/plugins/`, or copied, or installed from a built zip? What site key does it hold, and does the portal side of the suite know that key? Does the suite need a **running portal** (it must, to test the portal's client code and the Karin matrix) — and if so, who starts it in CI, in which DDEV project, at what URL does playground's connector phone home to it?
- **Legal incompatible builds.** The playground builder ships a bare DDEV WP with the connector symlinked and no key set ("tests will configure it"). The portal-test author writes Pest tests that assume a seeded registry row with key `test-key-123` and a playground already answering on `https://playground.ddev.site`. Locally the developer glues it by hand; in CI nothing stands up.
- **Fix — new AD (playground contract):** `apps/playground` owns one committed setup script (a DDEV custom command, e.g. `ddev playground-setup`) that: installs WP, symlinks `packages/connector` into `wp-content/plugins/`, activates it, and sets the canonical fixture site key from one named env var (e.g. `WOPTIMIZE_TEST_SITE_KEY`, default fixed value in `.ddev` config). The suite command and the portal tests read the same env var. Name the playground URL and the portal test URL in the same AD, and state which process starts the portal in CI.

### H6 — Slugs: the theme's directory name is chosen twice, and the prod→local DB sync couples the choices

- **Pair:** the deploy story author × the DDEV config author (and the theme builder).
- **The hole.** WordPress records the active theme by **directory slug** in the database (`template` option). The repo folder is `apps/www/theme/` — no slug given. Locally, the seed says `wordpress/wp-content` symlinks to `../../theme` — under what name inside `wp-content/themes/`? The deploy author independently picks a directory name on the server for the symlink target of the flip. Then CAP-7 syncs the **production DB to local**: if prod's slug is `woptimize` and the local symlink is named `theme`, every `ddev pull` lands a DB that activates a theme that does not exist locally — white screen after every routine content sync. Same story for the site plugin folder. Related gap: AD-9 gives "portal specifics" but no www specifics — what exactly is inside `releases/<sha>` and which symlink(s) does the flip flip (one `current` link, or separate `themes/x` + `plugins/y` links)?
- **Fix — tighten AD-9 / the seed:** fix the slugs in the spine: theme slug `woptimize` (`wp-content/themes/woptimize` → `apps/www/theme` locally; → `releases/<sha>/theme` in prod), site plugin slug named likewise. Add www specifics to AD-9 mirroring the portal's: contents of a www release, the single symlink that constitutes the flip, and what stays persistent (`uploads/`, WP core managed via panel per AD-12).

### H7 — Error envelope: the YAML author can spec a shape the connector is physically unable to deliver

- **Pair:** the contract (YAML) author × the portal client builder.
- **The hole.** The conventions say envelopes are "defined in `openapi.yaml` only — never invented in code first," but no convention says **what** the envelope is. The trap: WordPress core emits its own fixed error shape (`{"code","message","data":{"status"}}`) for REST failures that happen **before plugin code runs** — auth rejection, unknown route, invalid params. A YAML author who invents a custom envelope (`{"error":{"type","detail"}}`) is speccing something the connector cannot produce for exactly the failure class that matters most: bad-key `401`s, the trigger of AD-7's no-op path. The portal client builder then parses the custom shape and misreads every real auth failure. Both obeyed AD-4. Also unspecified: the channel for the AD-5 "connector reports its version in every response" rule — header or body field? Two YAML authors patching "their" endpoints can legally produce two dialects inside one file.
- **Fix — add convention rows:** connector-hosted endpoints use the WP REST core error envelope (`code`, `message`, `data.status`) — never a custom one; portal-hosted contract endpoints use one named Laravel envelope (state it). Version reporting travels in the `X-Woptimize-Connector-Version` response header (and in the phone-home body), not ad-hoc body fields. One PR changes the YAML for both directions together.

### H8 — Unknown-key phone-home: "upsert the registry" is AD-6-legal and quietly destroys offboarding

- **Pair:** the portal API builder × the connector builder.
- **The hole.** The spec's non-goal says offboarding = uninstall; the orphaned connector no-ops forever (AD-7). But an orphaned or half-onboarded connector keeps phoning home daily. What does the portal do with a phone-home carrying an unknown key? The convenient build — auto-register/upsert the site into the registry — does **not** violate AD-6 (the portal is still the sole writer of its own registry). Yet it means an offboarded site re-registers itself the next morning, forever, and half-onboarded sites appear in the registry without any human act. Meanwhile the connector builder, reading AD-7, may implement escalating retries on non-200 ("maybe transient") — a slow retry storm AD-7 explicitly bans but does not define thresholds for.
- **Fix — tighten AD-6/AD-7:** registry rows are created **only** by explicit portal-side onboarding — a phone-home or API call with an unknown or invalid key returns `401` with **no side effects** (no row, no log spam beyond rate-limited). The connector treats any 4xx as *permanent-quiet*: stay silent until the next regular WP-Cron slot, never tighten the schedule; only 5xx/network errors may retry, at most once per cron slot.

---

## MEDIUM

### M1 — The Karin matrix validates the previous-minor connector against the wrong YAML

- **Pair:** the CI author × the contract author.
- **The hole.** AD-4: CI validates connector responses "against the file." AD-11: the matrix runs the portal against the connector's current **and previous** minor. The moment a legal **additive** change lands (new endpoint in `v1`, per AD-5), the previous-minor connector lacks that endpoint. A CI author who validates the N-1 leg against HEAD's `openapi.yaml` sees a legally-compliant build fail its matrix — and the natural "fix" is loosening validation everywhere.
- **Fix — tighten AD-11:** the N-1 matrix leg checks out and validates against the `openapi.yaml` **at tag `plugin-v<previous-minor>`**; the HEAD YAML governs only the current leg. Equivalently: validation asserts "every response matches its schema," never "every path in the YAML exists on the connector."

### M2 — The portal constructs the connector's URL; real WP sites break the construction

- **Pair:** the portal builder × the connector builder.
- **The hole.** AD-6 gives the registry "site URL." The obvious portal build derives endpoint URLs as `{site_url}/wp-json/woptimize/v1/...`. Client WP sites without pretty permalinks serve REST only at `?rest_route=/woptimize/v1/...`; subdirectory and filtered installs differ too. The connector knows its true base (`rest_url()`); the portal guesses. Both builds are legal; some client sites are unreachable.
- **Fix:** the phone-home payload (in `openapi.yaml`) includes the connector's REST base URL from `rest_url('woptimize/v1')`; the portal stores it in the registry and **never** constructs endpoint URLs from the site URL.

### M3 — Umami has secrets and an AD exception, but no deploy path

- **Pair:** the CI author × the umami infra author.
- **The hole.** AD-13 creates `UMAMI_*` secrets; the workflow convention lists only `www.yml`, `portal.yml`, `connector.yml`. AD-12 bans ad-hoc SSH and allows only "panel or CI pipeline" — yet a change to `infra/umami/docker-compose.yml` reaches the server by neither (RunCloud's panel does not run compose; no workflow exists). The "contained exception" clause covers running Docker, not the transport for changes. The infra author will SSH; the AD says they must not.
- **Fix:** either add `umami.yml` (path-filtered to `infra/umami/**`, rsync + `docker compose up -d` over SSH, using the `UMAMI_*` secrets) to the workflow convention, or amend AD-12 to state that `infra/umami` changes are applied manually by a documented runbook in `infra/umami/` — pick one and write it down.

### M4 — The first release has no "previous minor"

- **Pair:** the CI author × the release story.
- **The hole.** AD-11 mandates the current+previous matrix. At `plugin-v1.0.0` (and after any major bump) there is no previous minor. A CI author can legally hard-fail the missing leg, blocking the very first release.
- **Fix:** one sentence in AD-11: when no previous minor tag exists (first release, or first minor after a major), the N-1 leg is skipped and reported as skipped, not failed.

### M5 — Key material: format, storage, and rotation mechanics unspecified

- **Pair:** the portal builder × the connector builder.
- **The hole.** With C2 fixed (portal issues keys), the remainder: key length/alphabet (a human must paste it — 128-char base64 vs 32-hex matters), whether the portal stores keys plaintext (needed to *send* `X-Woptimize-Site-Key` on outbound calls — so yes, and that should be a conscious decision) and how a rotation reaches an already-installed connector (manual re-paste for v1?). Two builders can each assume the other side handles rotation delivery.
- **Fix:** add to the C2 key-lifecycle AD: key = 40-char random alphanumeric (or similar, name it); portal stores it encrypted-at-rest via Laravel's encrypted cast but retrievable (it is a bearer credential used in both directions); rotation delivery in v1 is manual re-paste; anything smarter is Deferred.

---

## LOW

### L1 — Spec/spine wording drift: "Tailwind preset" vs "`@theme` CSS"

CAP-2 (spec) says "Tailwind preset + stylesheet"; AD-3 says "Tailwind `@theme` CSS." The spine is right for Tailwind 4 (JS presets are gone), but a builder reading the spec first could try to emit a JS preset. Note the amendment in the spec, or in the spine's AD-3 as an explicit supersession.

### L2 — Playground's internal layout is unspecified

The `apps/www` constraints (theme+plugin only in git, WP gitignored, relative symlinks) are stated only for www. The playground builder could commit a full WP install. One line: playground mirrors the www layout rules (WP install gitignored) minus the theme, with its own `.ddev`.

### L3 — "One workflow per app" does not fit the actual units

`connector.yml` is named after a package, not an app; `www.yml` covers two deployables (theme + site plugin); tokens have no workflow of their own (correct — but say so). Restate the convention as "one workflow per deployable surface: `www.yml`, `portal.yml`, `connector.yml`" so nobody adds `design-tokens.yml`.

### L4 — Secrets inventory is unnamed

`WWW_*`/`PORTAL_*`/`UMAMI_*` prefixes exist, but not the set (host, user, SSH key, path, DB creds for backup checks?). The first CI story will invent names; a five-line table in the conventions (e.g. `<APP>_SSH_HOST`, `<APP>_SSH_USER`, `<APP>_SSH_KEY`, `<APP>_DEPLOY_PATH`) prevents two workflows inventing two schemes.

### L5 — Node tooling layout is unowned

Tokens need Node; the portal needs Node; the theme may need Node. Root `package.json` with workspaces, or independent `package.json` per unit? Conflicts here are loud (visible in git), so severity is low — but naming it once (recommend: independent per-unit `package.json`, plus one root script `tokens:build` per H2) removes a decision from three builders.

---

## Summary table

| # | Severity | Seam | Pair | Closing move |
|---|----------|------|------|--------------|
| C1 | Critical | Portal-hosted API surface ungoverned, unvalidated | connector × portal API | AD-4/AD-5: YAML covers both directions; CI validates both sides |
| C2 | Critical | Key issuance direction ambiguous in AD-6 itself | portal × connector | New AD: portal issues keys; connector never generates; onboarding flow in YAML |
| C3 | Critical | Update pipeline: zip handoff, check mechanism, zip anatomy, naming | CI × portal × connector | New AD: fixed slug, fixed zip name/path, `Update URI:` mechanism, endpoints in YAML |
| H1 | High | `theme.json` fully-generated vs theme's non-token settings | tokens × theme | AD-3: committed `theme.base.json` merged by token build; one writer per section |
| H2 | High | Token filenames unnamed; "before rsync" allows tokens-after-assets; no named command | tokens × portal/CI | AD-3: name paths, order tokens before asset compile, name the root command |
| H3 | High | App-only path filters make token edits deploy nothing | CI × tokens | Convention: filters include consumed `packages/` paths |
| H4 | High | Contract suite (connector.yml) does not gate portal deploy (portal.yml) | CI × deploy | Suite = reusable workflow; portal deploy `needs:` it |
| H5 | High | Playground state, fixture key, portal presence in suite unspecified | playground × portal tests | New AD: committed seed script, named fixture key env var, named URLs |
| H6 | High | Theme slug chosen twice; prod→local DB sync couples them; www flip anatomy | deploy × DDEV | Fix slugs in spine; add www specifics to AD-9 |
| H7 | High | Error envelope inventable; WP core shape non-negotiable for auth failures | YAML author × portal client | Convention: WP core envelope connector-side; named Laravel envelope portal-side; version in header |
| H8 | High | Upsert-on-phone-home is legal and kills offboarding; retry limits undefined | portal API × connector | AD-6/7: rows only via onboarding; unknown key = side-effect-free 401; 4xx = permanent-quiet |
| M1 | Medium | N-1 matrix leg vs HEAD YAML fails on additive change | CI × contract | Validate N-1 against YAML at its tag |
| M2 | Medium | Portal derives `/wp-json/` URL; permalink/subdir sites break | portal × connector | Connector reports `rest_url()` base; portal stores it |
| M3 | Medium | Umami: secrets exist, no deploy path satisfies AD-12 | CI × infra | Add `umami.yml` or a written runbook exception |
| M4 | Medium | First release has no previous minor | CI × release | AD-11: skip-and-report when no N-1 tag |
| M5 | Medium | Key format, at-rest storage, rotation delivery | portal × connector | Extend key-lifecycle AD |
| L1 | Low | "preset" vs "@theme" wording drift | spec × spine | Note supersession |
| L2 | Low | Playground layout rules unstated | playground | Mirror www layout rules |
| L3 | Low | Workflow-per-"app" misfits actual units | CI | Rename convention to per-deployable-surface |
| L4 | Low | Secrets set unnamed | CI × deploy | Small naming table |
| L5 | Low | Node tooling layout unowned | tokens × portal × theme | One line: per-unit package.json + root tokens script |

**Verdict.** The spine's interior rules are sound; its seams are not yet buildable by two independent, letter-obedient builders. Close C1–C3 before any contract or update-pipeline story starts; fold H1–H3 into AD-3 and the CI convention before the tokens or CI stories start. Most fixes are one to four sentences of tightened AD text — cheap now, expensive after two AI builders have each shipped their own legal half.
