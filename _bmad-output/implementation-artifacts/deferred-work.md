# Deferred Work

- source_spec: `_bmad-output/specs/spec-repo-structure/stories/1-monorepo-skeleton-and-www-local-dev.md`
  summary: Add an automated clean-room check in the future www CI workflow (`www.yml`) that runs the fresh-clone recipe (`ddev delete -Oy`, remove `wordpress/`, `ddev start`, `ddev www-setup`), asserts `woptimize-theme` and `woptimize-core` are active, and asserts `git check-ignore -q apps/www/wordpress/wp-load.php`. It must land in story 7.
  evidence: Review found the Mutagen-race fix and the `/wordpress/` ignore rule are verified only by one-time manual runs; a regression would land on the next person who clones. The spec's Never list excludes CI workflows from story 1.

- source_spec: `_bmad-output/specs/spec-repo-structure/stories/2-portal-scaffold.md`
  summary: Add an automated clean-room check in the future portal CI workflow (`portal.yml`, story 7) that runs the fresh-clone recipe (`ddev delete -Oy`, `ddev start`, `ddev portal-setup`, then `ddev portal-setup` again), asserts both runs exit 0 with `APP_KEY` byte-identical, asserts the missing-`.env` case exits 1 with the restart instruction, and asserts `git check-attr eol -- apps/portal/.ddev/commands/host/portal-setup` reports `lf` on a fresh checkout.
  evidence: Review found the portal-setup idempotence/`.env` guards and the root `.gitattributes` LF rule are verified only by one-time manual runs; locally the LF rule is shadowed by a DDEV-generated untracked `.ddev/commands/.gitattributes`, so only a fresh-clone CI job can observe it. Story 2's Never list excludes CI, and story 1's existing entry names only `www.yml`.

- source_spec: `_bmad-output/specs/spec-repo-structure/stories/2-portal-scaffold.md`
  summary: Decide the portal's public-surface policy before story 8 flips the deploy — `public/robots.txt` currently allows all indexing of a private client portal, and the skeleton's welcome page and unauthenticated `/up` health endpoint would go live on `portal.woptimize.io` as-is.
  evidence: Review flagged all three; they are skeleton defaults with zero local consequence, and the spec assigns production hostname and deploy exclusively to story 8.
