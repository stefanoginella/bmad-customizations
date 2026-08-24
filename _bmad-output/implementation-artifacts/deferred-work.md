# Deferred Work

- source_spec: `_bmad-output/specs/spec-repo-structure/stories/1-monorepo-skeleton-and-www-local-dev.md`
  summary: Add an automated clean-room check in the future www CI workflow (`www.yml`) that runs the fresh-clone recipe (`ddev delete -Oy`, remove `wordpress/`, `ddev start`, `ddev www-setup`), asserts `woptimize-theme` and `woptimize-core` are active, and asserts `git check-ignore -q apps/www/wordpress/wp-load.php`.
  evidence: Review found the Mutagen-race fix and the `/wordpress/` ignore rule are verified only by one-time manual runs; a regression would land on the next person who clones. The spec's Never list excludes CI workflows from story 1.
