#!/usr/bin/env bash
#
# External Review
# ---------------
# Runs one review pass through whichever LLM CLI is NOT hosting the current
# session, so the findings come from a different model than the one that wrote
# the code. Decorrelated errors are the entire point: a blind spot shared by
# author and reviewer is a blind spot that ships.
#
# Usage:
#   external-review.sh --mode <blind|edge|intent> [--diff PATH] [--intent PATH]
#
#   --mode    which review method to run. Loads the matching prompt from
#             _bmad/custom/review-prompts/external-<mode>.md
#   --diff    unified diff file to review. Omit to build one from the current
#             worktree (git diff HEAD, plus a list of untracked files).
#   --intent  required by --mode intent. A file holding the source of truth the
#             change is measured against: a spec file, or the verbatim intent
#             the work started from.
#
# Output contract (the calling review layer depends on these markers):
#   EXTERNAL_REVIEW_CLI: <name> (model: .., effort: .., mode: ..)
#                                  provenance, printed on every successful run
#   EXTERNAL_REVIEW_EMPTY: ...     nothing to review; not a failure, exit 0
#   EXTERNAL_REVIEW_SKIP: ...      preconditions absent; not a failure, exit 0
#   EXTERNAL_REVIEW_ERROR: ...     the layer failed; exits non-zero
#
set -uo pipefail

# --- pinned reviewer models ---------------------------------------------------
# Pinned on purpose. A review that silently follows whatever model the user last
# selected is not reproducible, and two runs of the same diff stop being
# comparable. Change these here, deliberately, not by changing a global default.
CODEX_MODEL="gpt-5.6-sol"
CODEX_EFFORT="xhigh"
CLAUDE_MODEL="opus"
CLAUDE_EFFORT="xhigh"

MODE=""
DIFF=""
INTENT=""

while [ $# -gt 0 ]; do
  case "$1" in
    --mode)   MODE="${2:-}";   shift 2 ;;
    --diff)   DIFF="${2:-}";   shift 2 ;;
    --intent) INTENT="${2:-}"; shift 2 ;;
    *) echo "EXTERNAL_REVIEW_ERROR: unknown argument: $1"; exit 1 ;;
  esac
done

case "$MODE" in
  blind|edge|intent) ;;
  "") echo "EXTERNAL_REVIEW_ERROR: --mode is required (blind|edge|intent)"; exit 1 ;;
  *)  echo "EXTERNAL_REVIEW_ERROR: unknown mode: ${MODE}"; exit 1 ;;
esac

REPO="$(git rev-parse --show-toplevel 2>/dev/null)" || REPO=""
if [ -z "$REPO" ]; then
  echo "EXTERNAL_REVIEW_ERROR: not inside a git repository"
  exit 1
fi
cd "$REPO" || { echo "EXTERNAL_REVIEW_ERROR: cannot enter ${REPO}"; exit 1; }

METHOD_FILE="${REPO}/_bmad/custom/review-prompts/external-${MODE}.md"
if [ ! -r "$METHOD_FILE" ]; then
  echo "EXTERNAL_REVIEW_ERROR: review method not readable: ${METHOD_FILE}"
  exit 1
fi

# --- intent mode preconditions ----------------------------------------------
# An unresolved placeholder reaches us as literal braces. Treat that as "no
# source of truth for this run" and skip, rather than reviewing against garbage.
if [ "$MODE" = "intent" ]; then
  case "$INTENT" in
    ""|*"{"*"}"*)
      echo "EXTERNAL_REVIEW_SKIP: intent mode needs --intent, none provided"
      exit 0 ;;
  esac
  if [ ! -r "$INTENT" ] || [ ! -s "$INTENT" ]; then
    echo "EXTERNAL_REVIEW_SKIP: intent source missing or empty: ${INTENT}"
    exit 0
  fi
fi

# --- assemble the change set -------------------------------------------------
EXTRA=""
if [ -z "$DIFF" ]; then
  # Worktree mode. Build the diff ourselves and list untracked files separately,
  # so we never mutate the user's index just to make them visible.
  DIFF="$(mktemp -t external-review-diff)" || {
    echo "EXTERNAL_REVIEW_ERROR: cannot create temporary diff file"; exit 1; }
  trap 'rm -f "$DIFF"' EXIT
  git diff HEAD > "$DIFF" 2>/dev/null

  UNTRACKED="$(git ls-files --others --exclude-standard)"
  if [ -n "$UNTRACKED" ]; then
    EXTRA="

These files are new and untracked, so they do not appear in the diff at all.
Read each one in full and review it with the same rigour:
${UNTRACKED}"
  fi
elif [ ! -r "$DIFF" ]; then
  echo "EXTERNAL_REVIEW_ERROR: diff file not readable: ${DIFF}"
  exit 1
fi

if [ ! -s "$DIFF" ] && [ -z "$EXTRA" ]; then
  echo "EXTERNAL_REVIEW_EMPTY: no changes to review"
  exit 0
fi

# --- CLI selection -----------------------------------------------------------
# Claude Code is the only host we can detect positively: it exports CLAUDECODE=1.
# Codex exports no marker we found reliable, so "not Claude Code" is treated as
# "probably not claude" and we prefer claude as the external voice. Either way
# the guess is never trusted on its own -- command -v decides.
if [ -n "${CLAUDECODE:-}" ]; then
  PREFERRED="codex";  FALLBACK="claude"
else
  PREFERRED="claude"; FALLBACK="codex"
fi

if command -v "$PREFERRED" >/dev/null 2>&1; then
  CLI="$PREFERRED"
elif command -v "$FALLBACK" >/dev/null 2>&1; then
  CLI="$FALLBACK"
else
  echo "EXTERNAL_REVIEW_ERROR: neither 'codex' nor 'claude' is on PATH"
  exit 1
fi

# --- build the prompt --------------------------------------------------------
METHOD="$(cat "$METHOD_FILE")"

CONTENT="The change under review is the unified diff at ${DIFF}.
Read that file first. You are running inside the repository at ${REPO}, so read
any source file you need in order to judge the change properly.${EXTRA}"

if [ "$MODE" = "intent" ]; then
  CONTENT="${CONTENT}

The source of truth this change is measured against is the file at ${INTENT}.
Read it in full before you read the diff."
fi

PROMPT="${METHOD}

---

${CONTENT}

Review only. Do not edit, create, or delete any file."

# --- run ---------------------------------------------------------------------
if [ "$CLI" = "codex" ]; then
  MODEL="$CODEX_MODEL"; EFFORT="$CODEX_EFFORT"
else
  MODEL="$CLAUDE_MODEL"; EFFORT="$CLAUDE_EFFORT"
fi
echo "EXTERNAL_REVIEW_CLI: ${CLI} (model: ${MODEL}, effort: ${EFFORT}, mode: ${MODE})"

if [ "$CLI" = "codex" ]; then
  printf '%s' "$PROMPT" | codex exec \
    --sandbox read-only \
    --model "$CODEX_MODEL" \
    -c model_reasoning_effort="$CODEX_EFFORT" \
    -
else
  printf '%s' "$PROMPT" | claude -p \
    --model "$CLAUDE_MODEL" \
    --effort "$CLAUDE_EFFORT"
fi

STATUS=$?
if [ "$STATUS" -ne 0 ]; then
  echo "EXTERNAL_REVIEW_ERROR: ${CLI} exited with status ${STATUS}"
  exit "$STATUS"
fi
