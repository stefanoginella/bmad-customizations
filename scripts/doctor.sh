#!/usr/bin/env bash
#
# Pack doctor
# -----------
# Run from inside a project that mounts this pack at `_bmad/custom/`. It checks
# the three ways the pack breaks, in the order they bite:
#
#   1  COMPAT   The overrides bind to keys declared in each skill's shipped
#               customize.toml. A BMad upgrade can rename or drop one, and the
#               merge NEVER complains — the override just stops applying. The
#               version string is a hint; check_keys.py is the proof.
#   2  LEAK     `git subtree push` sends the WHOLE prefix. A project-only file
#               left in _bmad/custom/ reaches every other project on the next
#               pull. Anything tracked but absent from MANIFEST is a stray.
#   3  CLI      external-review.sh needs `codex` or `claude` on PATH. Without
#               one the review layer FAILS — it does not skip quietly — so find
#               out here rather than 20 minutes into a build.
#
# Usage:  bash _bmad/custom/scripts/doctor.sh
# Exit:   0 all clear (warnings allowed), 1 at least one check failed.
#
set -uo pipefail

PACK="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO="$(git rev-parse --show-toplevel 2>/dev/null)" || REPO=""
if [ -z "$REPO" ]; then
  echo "DOCTOR_FAIL: not inside a git repository"
  exit 1
fi

FAILED=0
ok()   { echo "DOCTOR_OK:   $*"; }
warn() { echo "DOCTOR_WARN: $*"; }
fail() { echo "DOCTOR_FAIL: $*"; FAILED=1; }
detail() { sed 's/^/             /'; }

# --- 1. compatibility --------------------------------------------------------
echo "== 1. compatibility =="

PINNED="$(tr -d '[:space:]' < "${PACK}/BMAD_VERSION" 2>/dev/null)"
MANIFEST_YAML="${REPO}/_bmad/_config/manifest.yaml"
INSTALLED=""
[ -r "$MANIFEST_YAML" ] &&
  INSTALLED="$(sed -n 's/^[[:space:]]*version:[[:space:]]*//p' "$MANIFEST_YAML" | head -1)"

if [ -z "$INSTALLED" ]; then
  warn "cannot read the installed BMad version from ${MANIFEST_YAML#"$REPO"/}"
elif [ "$INSTALLED" = "$PINNED" ]; then
  ok "BMad ${INSTALLED} matches the pinned version"
else
  warn "BMad ${INSTALLED} installed, pack pinned to ${PINNED} — the key check below decides"
fi

if ! command -v uv >/dev/null 2>&1; then
  warn "uv not on PATH — cannot check keys, so compatibility is UNVERIFIED"
else
  for TOML in "$PACK"/bmad-*.toml; do
    [ -e "$TOML" ] || continue
    case "$TOML" in *.user.toml) continue ;; esac   # project's own layer, not ours
    SKILL="$(basename "$TOML" .toml)"
    SKILL_DIR="${REPO}/.claude/skills/${SKILL}"

    if [ ! -d "$SKILL_DIR" ]; then
      warn "${SKILL}: override present, skill not installed here — the file is inert"
      continue
    fi
    if [ ! -r "${SKILL_DIR}/customize.toml" ]; then
      fail "${SKILL}: skill installed but declares no customize.toml — nothing to override"
      continue
    fi

    # Every key this override sets must still be declared by the shipped skill.
    ORPHANS="$(uv run --no-cache "${PACK}/scripts/check_keys.py" \
                 --override "$TOML" --shipped "${SKILL_DIR}/customize.toml" 2>&1)"
    KEY_STATUS=$?

    if [ "$KEY_STATUS" -eq 0 ]; then
      ok "${SKILL}: every overridden key is still declared"
    elif [ "$KEY_STATUS" -eq 1 ]; then
      fail "${SKILL}: these keys are set but no longer declared — the override is DEAD:"
      printf '%s\n' "$ORPHANS" | detail
    else
      fail "${SKILL}: key check could not run"
      printf '%s\n' "$ORPHANS" | detail
      continue
    fi

    # Skills with a workflow.md render entry get the stronger check for free:
    # render resolves every customization token and raises on a bad one.
    # Skills without one (bmad-code-review) never call render_skill.py at all.
    if [ -r "${SKILL_DIR}/workflow.md" ]; then
      OUT="$(uv run --no-cache "${REPO}/_bmad/scripts/render_skill.py" \
               --project-root "$REPO" --skill "$SKILL_DIR" 2>&1)"
      if [ $? -eq 0 ]; then
        ok "${SKILL}: renders clean"
      else
        fail "${SKILL}: render failed"
        printf '%s\n' "$OUT" | detail
      fi
    fi
  done
fi

# --- 2. leak guard -----------------------------------------------------------
echo
echo "== 2. leak guard =="

case "$PACK" in
  "$REPO"/*)
    PREFIX="${PACK#"$REPO"/}"
    TRACKED="$(git -C "$REPO" ls-files -- "$PREFIX" 2>/dev/null)"
    if [ -z "$TRACKED" ]; then
      warn "nothing tracked at ${PREFIX} — not committed here, skipping"
    else
      EXPECTED="$(grep -v '^[[:space:]]*#' "${PACK}/MANIFEST" | grep -v '^[[:space:]]*$')"
      STRAY=""
      while IFS= read -r F; do
        REL="${F#"$PREFIX"/}"
        printf '%s\n' "$EXPECTED" | grep -qxF "$REL" || STRAY="${STRAY}${REL}"$'\n'
      done <<< "$TRACKED"

      if [ -z "$STRAY" ]; then
        ok "no stray tracked files — a subtree push sends only the pack"
      else
        fail "tracked in the prefix but not in MANIFEST — these LEAK on push:"
        printf '%s' "$STRAY" | sed '/^$/d' | detail
        echo "             Fix: rename to *.user.toml (gitignored, project-only),"
        echo "             or add to MANIFEST if it belongs to the shared pack."
      fi
    fi
    ;;
  *)
    warn "pack lives outside this repository — no prefix to audit, skipping"
    ;;
esac

# Absolute paths bind a shared file to one machine or one project. The scanner
# is excluded: it necessarily contains the patterns it looks for.
HITS="$(grep -rnE '(/Users/|/Volumes/|/home/)' \
          "$PACK"/*.toml "$PACK"/review-prompts "$PACK"/scripts 2>/dev/null \
        | grep -v '/scripts/doctor\.sh:')"
if [ -z "$HITS" ]; then
  ok "no absolute paths in the shared files"
else
  fail "absolute paths in shared files — these break in other projects:"
  printf '%s\n' "$HITS" | sed "s|^${PACK}/||" | detail
fi

# --- 3. reviewer CLI ---------------------------------------------------------
echo
echo "== 3. reviewer CLI =="

HAVE_CODEX=0; HAVE_CLAUDE=0
command -v codex  >/dev/null 2>&1 && HAVE_CODEX=1
command -v claude >/dev/null 2>&1 && HAVE_CLAUDE=1

if [ "$HAVE_CODEX" -eq 1 ] && [ "$HAVE_CLAUDE" -eq 1 ]; then
  ok "codex and claude both present — the external layer always runs cross-model"
elif [ "$HAVE_CODEX" -eq 1 ] || [ "$HAVE_CLAUDE" -eq 1 ]; then
  PRESENT=$([ "$HAVE_CODEX" -eq 1 ] && echo codex || echo claude)
  warn "only ${PRESENT} is installed — when it also hosts the session the"
  warn "             'external' review falls back to the SAME model and stops being independent"
else
  fail "neither codex nor claude on PATH — every external review layer fails"
fi

# --- verdict -----------------------------------------------------------------
echo
if [ "$FAILED" -eq 0 ]; then
  echo "DOCTOR_PASS: pack is healthy"
else
  echo "DOCTOR_FAIL: fix the failures above"
fi
exit "$FAILED"
