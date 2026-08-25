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
#   external-review.sh --resume <job-id>
#
#   --mode    which review method to run. Loads the matching prompt from
#             _bmad/custom/review-prompts/external-<mode>.md
#   --diff    unified diff file to review. Omit to build one from the current
#             worktree (git diff HEAD, plus a list of untracked files).
#   --intent  required by --mode intent. A file holding the source of truth the
#             change is measured against: a spec file, or the verbatim intent
#             the work started from.
#   --resume  wait on a review that an earlier call reported as PENDING.
#   --wait    seconds to wait before reporting PENDING (default 540). Keep it
#             under the host's per-call timeout.
#
# Why start/resume: the host's Bash tool has a hard per-call timeout (600 s in
# Claude Code by default) and an xhigh Codex review of a real diff takes longer.
# A call that outlives the cap gets backgrounded, and the courier that ran it is
# not reliably woken again. So the review runs detached from the very first
# call, every call returns well inside the cap, and a courier simply resumes
# until the findings are there. Nothing depends on a wake-up.
#
# Lockfiles (composer.lock, package-lock.json, ...) are stripped from the diff
# before it reaches the reviewer: thousands of generated lines, zero findings.
#
# Output contract (the calling review layer depends on these markers):
#   EXTERNAL_REVIEW_CLI: <name> (model: .., effort: .., mode: ..)
#                                  provenance, printed on every successful run
#   EXTERNAL_REVIEW_PENDING: <id>  still running; call again with --resume <id>
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
RESUME=""
WAIT_SECONDS=540
JOBS_ROOT="${TMPDIR:-/tmp}"; JOBS_ROOT="${JOBS_ROOT%/}/external-review-jobs"

LOCKFILE_NAMES='composer.lock|package-lock.json|yarn.lock|pnpm-lock.yaml|Gemfile.lock|Cargo.lock|poetry.lock|Pipfile.lock|go.sum|flake.lock'

while [ $# -gt 0 ]; do
  case "$1" in
    --mode)   MODE="${2:-}";   shift 2 ;;
    --diff)   DIFF="${2:-}";   shift 2 ;;
    --intent) INTENT="${2:-}"; shift 2 ;;
    --resume) RESUME="${2:-}"; shift 2 ;;
    --wait)   WAIT_SECONDS="${2:-}"; shift 2 ;;
    *) echo "EXTERNAL_REVIEW_ERROR: unknown argument: $1"; exit 1 ;;
  esac
done

case "$WAIT_SECONDS" in
  ''|*[!0-9]*) echo "EXTERNAL_REVIEW_ERROR: --wait needs a number of seconds"; exit 1 ;;
esac

# --- job helpers -------------------------------------------------------------
# A job is a directory: prompt, header, out, status. `status` appears last, when
# the CLI has exited, and holds its exit code -- that file is the done signal.

wait_for_job() {
  local job_dir="$1" job_id="$2" waited=0

  while [ ! -f "${job_dir}/status" ]; do
    if [ "$waited" -ge "$WAIT_SECONDS" ]; then
      echo "EXTERNAL_REVIEW_PENDING: ${job_id} (still running after ${waited}s more; run again with --resume ${job_id})"
      exit 0
    fi
    sleep 5
    waited=$(( waited + 5 ))
  done

  cat "${job_dir}/header"
  cat "${job_dir}/out"

  local status
  status="$(cat "${job_dir}/status")"
  if [ "$status" != "0" ]; then
    echo "EXTERNAL_REVIEW_ERROR: $(cat "${job_dir}/cli") exited with status ${status}"
    exit "$status"
  fi
  exit 0
}

if [ -n "$RESUME" ]; then
  case "$RESUME" in
    *[!A-Za-z0-9._-]*|'') echo "EXTERNAL_REVIEW_ERROR: bad job id: ${RESUME}"; exit 1 ;;
  esac
  JOB_DIR="${JOBS_ROOT}/${RESUME}"
  if [ ! -d "$JOB_DIR" ]; then
    echo "EXTERNAL_REVIEW_ERROR: no such job: ${RESUME} (looked in ${JOBS_ROOT})"
    exit 1
  fi
  wait_for_job "$JOB_DIR" "$RESUME"
fi

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

# --- job directory -----------------------------------------------------------
mkdir -p "$JOBS_ROOT" || { echo "EXTERNAL_REVIEW_ERROR: cannot create ${JOBS_ROOT}"; exit 1; }
JOB_DIR="$(mktemp -d "${JOBS_ROOT}/${MODE}-XXXXXXXX")" || {
  echo "EXTERNAL_REVIEW_ERROR: cannot create a job directory under ${JOBS_ROOT}"; exit 1; }
JOB_ID="$(basename "$JOB_DIR")"

# --- strip lockfiles from the diff -------------------------------------------
# Whole file sections are dropped: from `diff --git a/<lockfile>` up to the next
# `diff --git`. The reviewer is told which files went missing and why.
STRIPPED="$(grep -E "^diff --git a/(.*/)?(${LOCKFILE_NAMES}) " "$DIFF" 2>/dev/null \
  | sed -E 's|^diff --git a/([^ ]+) .*|\1|')"
if [ -n "$STRIPPED" ]; then
  awk -v names="$LOCKFILE_NAMES" '
    BEGIN { pat = "^diff --git a/(.*/)?(" names ") " }
    /^diff --git / { skip = ($0 ~ pat) }
    !skip { print }
  ' "$DIFF" > "${JOB_DIR}/diff"
  EXTRA="${EXTRA}

Generated lockfiles were removed from the diff before you got it, so you do
not spend your time on dependency metadata. They changed, and they are out of
scope for this review:
${STRIPPED}"
else
  cp "$DIFF" "${JOB_DIR}/diff"
fi
DIFF="${JOB_DIR}/diff"

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

# --- run (detached) ----------------------------------------------------------
if [ "$CLI" = "codex" ]; then
  MODEL="$CODEX_MODEL"; EFFORT="$CODEX_EFFORT"
else
  MODEL="$CLAUDE_MODEL"; EFFORT="$CLAUDE_EFFORT"
fi
printf '%s\n' "EXTERNAL_REVIEW_CLI: ${CLI} (model: ${MODEL}, effort: ${EFFORT}, mode: ${MODE})" > "${JOB_DIR}/header"
printf '%s' "$CLI" > "${JOB_DIR}/cli"
printf '%s' "$PROMPT" > "${JOB_DIR}/prompt"

if [ "$CLI" = "codex" ]; then
  cat > "${JOB_DIR}/run.sh" <<EOF
#!/usr/bin/env bash
cd "$REPO" || exit 1
codex exec --sandbox read-only --model "$CODEX_MODEL" -c model_reasoning_effort="$CODEX_EFFORT" - \
  < "${JOB_DIR}/prompt" > "${JOB_DIR}/out" 2>&1
echo \$? > "${JOB_DIR}/status.tmp" && mv "${JOB_DIR}/status.tmp" "${JOB_DIR}/status"
EOF
else
  cat > "${JOB_DIR}/run.sh" <<EOF
#!/usr/bin/env bash
cd "$REPO" || exit 1
claude -p --model "$CLAUDE_MODEL" --effort "$CLAUDE_EFFORT" \
  < "${JOB_DIR}/prompt" > "${JOB_DIR}/out" 2>&1
echo \$? > "${JOB_DIR}/status.tmp" && mv "${JOB_DIR}/status.tmp" "${JOB_DIR}/status"
EOF
fi

# Double fork: the reviewer is reparented away from this shell, so it survives
# the caller's timeout, its process group, and its stdout closing.
( nohup bash "${JOB_DIR}/run.sh" < /dev/null > /dev/null 2>&1 & ) &
disown 2>/dev/null || true

# The worktree-mode temp diff was already copied into the job dir; the trap may
# remove the original now.
wait_for_job "$JOB_DIR" "$JOB_ID"
