# Release and Deploy

## Deploys (GitHub Actions)

- Production servers are RunCloud-managed; Actions deploy over SSH (rsync).
- Per-app path filters decide what a push deploys. No raw RunCloud webhooks on the monorepo — every push would deploy everything.
- One deploy model for both apps (`ARCHITECTURE-SPINE.md` AD-9): rsync to `releases/<sha>/`, then a symlink flip — instant one-command rollback. Code only; DB and uploads roll back via backups.
- www: the flip happens inside `wp-content/` — the `woptimize-theme` and `woptimize-core` symlinks repoint to the new release.
- portal: CI builds (composer + tokens + assets); `.env` and `storage/` live in `shared/`, symlinked per release; `migrate --force` runs before the `current` flip. Migrations stay expand/contract so rollback never needs a DB restore (AD-10).

## Connector releases

- Tag `plugin-v*` → the Action builds the zip from the connector folder → the portal serves it to client sites.
- The connector integration suite (against `apps/playground`) runs local and in CI from one DDEV definition, one command; the portal deploy job needs a green suite in the same run (AD-11).

## Versioning policy (Karin's rule, amended)

- Client sites never all update in the same minute; the portal supports the current + previous connector **minor** version.
- Patch releases (x.y.Z) never change the contract — always compatible, so multiple hotfixes ship in any order.
- A contract change requires a minor (or major) bump: ship the connector change first, the portal change after.
- Monorepo lockstep commits are the temptation — never rely on them.

## Content sync

- Custom DDEV provider `.ddev/providers/prod.yaml` — SSH + rsync, db and files, pull **and** push.
- Pull routinely. Push only pre-launch, double-confirmed: DDEV's own confirm plus a typed production hostname inside the provider's push commands.
- After launch, content flows one way: production → local.
