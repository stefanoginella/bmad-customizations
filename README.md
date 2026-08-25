# BMad customizations

A portable BMad override pack. It mounts at `_bmad/custom/` in any project and
adds a **security audit** layer plus **external-LLM review** layers to every
BMad review workflow, and a **conditional TDD split** to the build workflows.

Nothing here is project-specific. Every path resolves at run time from
`git rev-parse --show-toplevel`, so the pack works in any git repository.

---

## Target BMad version

> **6.11.1-next.27**

Read this before you upgrade BMad in a project.

Each `bmad-*.toml` binds to keys declared in that skill's shipped
`customize.toml` — for example `[[workflow.review_layers]]` in
`.claude/skills/bmad-build/customize.toml`. If a BMad upgrade renames, moves, or
drops one of those keys, the override **stops working**: it either does nothing
at all, or the render step fails outright.

After a BMad upgrade, check the shipped `customize.toml` of each of the three
skills below, then update this line.

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
  /Volumes/Main/Projects/bmad-customizations main --squash
```

`git subtree add` needs the prefix to **not exist**. If the project already has
a `_bmad/custom/`, remove it first:

```bash
git rm -r --cached _bmad/custom && rm -rf _bmad/custom
git commit -m "chore(bmad): move custom pack to a subtree"
```

## Get improvements

```bash
git subtree pull --prefix=_bmad/custom \
  /Volumes/Main/Projects/bmad-customizations main --squash
```

## Send improvements back

```bash
git subtree push --prefix=_bmad/custom \
  /Volumes/Main/Projects/bmad-customizations main
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
