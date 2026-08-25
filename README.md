# BMad customizations

<https://github.com/stefanoginella/bmad-customizations>

A portable BMad override pack. It mounts at `_bmad/custom/` in any project and
adds a **security audit** layer plus **external-LLM review** layers to every
BMad review workflow, and a **conditional TDD split** to the build workflows.

Nothing here is project-specific. Every path resolves at run time from
`git rev-parse --show-toplevel`, so the pack works in any git repository.

---

## Check the pack: `doctor.sh`

```bash
bash _bmad/custom/scripts/doctor.sh
```

Run it after mounting the pack, and after **every BMad upgrade**. It exits 1 on
a failure, so it drops straight into a pre-push hook or CI. It checks the three
ways this pack breaks:

**1. Compatibility.** Each `bmad-*.toml` binds to keys declared in that skill's
shipped `customize.toml` — `[[workflow.review_layers]]`, and so on. The merge
**never complains**: a key the shipped file no longer declares is added to the
result and then ignored. A BMad upgrade can therefore disable an override in
complete silence, and your reviews get quietly weaker with no error anywhere.
`scripts/check_keys.py` compares the two files and names any orphaned key. For
skills that have a `workflow.md` render entry, the doctor also runs
`render_skill.py`, which resolves every token. `bmad-code-review` has no render
entry, so the key check is the only check it gets.

The pinned version lives in `BMAD_VERSION` — one line, read by the doctor.
A mismatch is a **warning**, not a failure: the key check decides.

**2. Leaks.** See [The one rule](#the-one-rule).

**3. Reviewer CLI.** See [Requirements](#requirements).

---

## What is in it

| File | What it does |
| --- | --- |
| `bmad-code-review.toml` | Adds 4 review layers: `security-audit`, `external-blind`, `external-edge`, `external-intent`. |
| `bmad-build.toml` | Adds the same 4 layers on both review routes (standard + one-shot), plus the conditional TDD implementation handoff. |
| `bmad-build-auto.toml` | Adds 3 layers (`security-audit`, `external-intent`, `external-edge`), plus the TDD handoff. Lighter on purpose — this is the unattended loop, so every layer is paid on every iteration. |
| `review-prompts/security-review.md` | The in-session security review method. |
| `review-prompts/external-{blind,edge,intent}.md` | The three external review methods. |
| `scripts/external-review.sh` | Runs one review pass through whichever LLM CLI is **not** hosting the current session. |
| `config.toml` | Team layer for central config. Empty template — examples only. |
| `.gitignore` | `*.user.toml` — the personal layer never travels. |
| `scripts/doctor.sh` | Checks compatibility, leaks, and the reviewer CLI. Run after every BMad upgrade. |
| `scripts/check_keys.py` | Names any overridden key the shipped skill no longer declares. |
| `BMAD_VERSION` | The BMad version this pack was verified against. |
| `MANIFEST` | Every file the pack owns. The doctor's leak guard reads it. |

The prompts and the script are **shared assets**. All three overrides point at
them. Edit one file and every workflow picks up the change.

### Why the external layers exist

In these workflows the implementation agent and the review agents run on the
same model, in the same session. That is textbook self-review bias, and it lands
hardest on spec conformance: a misread requirement is invisible to whoever
misread it. The external layers read the change cold, from a different model.

They run as subagents, launched in the same parallel batch as the in-session
layers. Wall clock is the slowest single layer, not the sum.

---

## Requirements

- **`codex` or `claude` on `PATH`.** `external-review.sh` prefers the CLI that is
  not hosting the session, and falls back to the other. With neither installed
  it stops with `EXTERNAL_REVIEW_ERROR` — it does **not** skip quietly.
  With only **one** installed, the fallback runs the "external" review on the
  same model that hosts the session. It still passes, but it is no longer
  independent, which is the entire point of the layer. The doctor warns on this.
- **`uv`** — the doctor needs it to run the two Python checks.
- **BMad installed in the target project**, with `_bmad/scripts/` present.
- No install or build step. `render_skill.py` runs when a skill loads and caches
  the result under `_bmad/render/`. Drop the files in and the next skill run
  picks them up.

The reviewer models are **pinned** at the top of `scripts/external-review.sh`.
That is deliberate: a review that follows whatever model was last selected is
not reproducible, and two runs of the same diff stop being comparable.

---

## Add the pack to a project

```bash
cd /path/to/project

# BMad ignore rule — skip if the project already has it
printf '_bmad/*\n!_bmad/custom/\n' >> .gitignore

git subtree add --prefix=_bmad/custom \
  https://github.com/stefanoginella/bmad-customizations.git main --squash

bash _bmad/custom/scripts/doctor.sh
```

`git subtree add` needs the prefix to **not exist**. If the project already has
a `_bmad/custom/`, remove it first:

```bash
git rm -r --cached _bmad/custom && rm -rf _bmad/custom
git commit -m "chore(bmad): move custom pack to a subtree"
```

Prefer a short name? Register the remote once per project, then use
`bmad-pack` in place of the URL in every command below:

```bash
git remote add bmad-pack https://github.com/stefanoginella/bmad-customizations.git
```

## Get improvements

```bash
git subtree pull --prefix=_bmad/custom \
  https://github.com/stefanoginella/bmad-customizations.git main --squash

bash _bmad/custom/scripts/doctor.sh
```

## Send improvements back

```bash
bash _bmad/custom/scripts/doctor.sh   # catches strays BEFORE they leave

git subtree push --prefix=_bmad/custom \
  https://github.com/stefanoginella/bmad-customizations.git main
```

---

## The one rule

**`git subtree push` sends the whole prefix.** A project-only override left in
`_bmad/custom/` leaks to every other project on the next pull.

So:

- **Shared** override → `bmad-build.toml` — tracked, travels.
- **Project-only** override → `bmad-build.user.toml` — gitignored by
  `.gitignore` in this pack, never travels.

The user layer also wins the merge, so a project can override the shared pack
without editing it.

`MANIFEST` lists every file the pack owns. `doctor.sh` compares it against what
is tracked in the prefix and **fails** on anything extra, so a stray is caught
before the push rather than after the pull.

### Merge order

Skill overrides (`_bmad/scripts/config_utils.py`, `load_customization`):

```
.claude/skills/<skill>/customize.toml   shipped defaults
_bmad/custom/<skill>.toml               this pack
_bmad/custom/<skill>.user.toml          project-only, gitignored   ← wins
```

Central config (`load_central_config`):

```
_bmad/config.toml
_bmad/config.user.toml
_bmad/custom/config.toml                this pack
_bmad/custom/config.user.toml           project-only, gitignored   ← wins
```

BMad has **no** user-home or global customization layer. `_bmad/custom/` is
resolved from the project root only. That is why this pack exists.

---

## Editing an override

Use the `bmad-customize` skill (menu code `BC`) in a **fresh context window**. It
scans what is customizable, picks the right scope, writes the TOML, and verifies
the merge. No hand-authoring needed.
