# Forged: WOptimize repo structure

**Verdict: HARDENED — one GitHub monorepo, minus Umami.**

## Locked decisions

- One monorepo: `apps/www` (WP marketing), `apps/portal` (Laravel), `apps/playground` (throwaway WP "client site" for integration tests), `packages/design-tokens`, `packages/connector` (plugin installed on client sites), `infra/umami` (compose + env only), `.github/workflows`.
- Design system is code: one token source (fonts, colors, typography, spacing, motion, buttons, links) **builds** to `theme.json` + stylesheet for WP and a Tailwind preset + stylesheet for Laravel. Scope: tokens + base styles first; shared components only when both apps need the same one.
- Plugin ↔ portal: two-directional (plugin registers REST endpoints the portal calls with a site key; plugin phones home). Portal serves the plugin update zips. **No license system** — offboarding = uninstall; an orphaned plugin becomes pure no-ops.
- `apps/www` in git = custom theme + one site plugin only. Layout: `theme/` and `plugins/` at the `apps/www` root; full WP install gitignored in `wordpress/`; **relative** symlinks from `wordpress/wp-content/` to the root folders. DDEV project root = `apps/www` with `docroot: wordpress` — required, or the symlink targets fall outside the container mount.
- Deploys: GitHub Actions with per-app path filters. No raw RunCloud webhooks on the monorepo (every push would deploy everything).
- www deploys rsync to `releases/<sha>/` on the server + symlink flip in `wp-content/` → instant one-command rollback. Code only; DB/uploads roll back via backups.
- Connector releases: tag `plugin-v*` → Action builds the zip from its folder → portal serves it to client sites.
- Content sync: custom DDEV provider `.ddev/providers/prod.yaml` (SSH + rsync; db/files pull **and** push). Pull routinely; push only pre-launch.

## Rejected

- **Separate repos** — forces private-package publishing or copy-drift for the token pipeline, and splits the plugin/portal contract and local integration testing. No surviving upside: the access-boundary argument is dead (any future hire works on the portal and needs the site repo too).
- **Umami as a codebase** — pure deployment, zero custom code. Infra notes only.
- **License system for the connector** — uninstall-on-offboarding + no-op fallback is enough.

## Surviving risks (discipline rules, not blockers)

- **Karin's rule (amended):** client sites never update in the same minute. The portal supports the current + previous plugin **minor** version. Patch releases (x.y.Z) never change the contract — always compatible, so multiple hotfixes can ship in any order. Contract changes require a minor (or major) bump; ship the plugin change first, the portal change after. Monorepo lockstep commits are the temptation.
- **Tomas's rule:** do not let the design system outgrow the product — envelope, not truck.
- **`ddev push` on a live site destroys content edits.** After launch, content flows one way: production → local.
