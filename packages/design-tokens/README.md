# packages/design-tokens — the one design-token source

One DTCG token source and one base-styles partial. Style Dictionary turns them
into the four style artifacts both apps consume. There is no second source of
truth for a colour, a size, a duration, or a radius anywhere in this repo.

## Prerequisites

- **Node >= 24** on the **host** (`.nvmrc` says `24`).

This is the one thing in the repo that does not run inside DDEV. Each DDEV
project mounts only its own app folder, and this build reads `packages/` and
writes into **both** apps. Install Node with your usual manager
(<https://nodejs.org>, `nvm install`, `fnm use`, `mise use`), then:

```bash
npm run tokens:build     # from the repo root
```

`ddev www-setup` and `ddev portal-setup` both run that command as their first
step. Their section 0 checks for `npm` **and** for a Node major of at least 24 —
Style Dictionary 5 dies deep inside the build on an older one — and stops with
the same two-line error pointing back here.

## The four artifacts

Every one of them is **generated, gitignored, and never hand-edited**. Each
carries a "GENERATED FILE — DO NOT EDIT" notice: the three CSS files open with
it as a comment, and `theme.json` — which cannot hold a comment — carries it as
its first key, `__generated`.

| Path | What it is |
| --- | --- |
| `apps/www/themes/woptimize-theme/theme.json` | `theme.base.json` with the token sections injected |
| `apps/www/themes/woptimize-theme/assets/css/tokens.css` | `:root { … }` custom properties + base styles |
| `apps/portal/resources/css/tokens.theme.css` | the Tailwind 4 `@theme static { … }` block |
| `apps/portal/resources/css/tokens.base.css` | the same base styles, no custom properties |

The paths are fixed by architecture decision AD-3. Do not move or rename them.

If you edited one by hand, the next build silently overwrites it. Put the change
in `tokens/` or `src/base.css` instead.

## Layout

```
packages/design-tokens/
  tokens/          DTCG sources — one file per source area, not per namespace
    font.json        font.*                       -> --font-*
    color.json       color.*                      -> --color-*
    typography.json  text.*, font-weight.*        -> --text-*, --font-weight-*
    spacing.json     spacing.*, radius.*          -> --spacing-*, --radius-*
    motion.json      duration.*, ease.*           -> --duration-*, --ease-*
    button.json      button.*  (references the primitives)
    link.json        link.*    (references the primitives)
  src/base.css     the hand-written base-styles partial
  build.js         the build — exports buildTokens(), runs when executed
  test/            node --test coverage of the build
```

Two files carry two top-level groups each, because the grouping follows the
source area a designer thinks in, not the custom-property namespace. Only the
top-level group name decides the namespace, so a token can move between files
without changing its name.

## Adding or changing a token

1. Edit the right file in `tokens/`. Sources are **DTCG**: every token is
   `{ "$type": …, "$value": … }`. A `$value` string carries its own CSS unit
   (`1rem`, `150ms`, `9999px`) — the build never adds or converts units.
2. Reference another token with `{group.path}`, for example
   `"$value": "{color.brand.600}"`. References resolve at build time; the
   artifacts contain resolved values only. A reference that points at nothing
   stops the build and names the offender.
3. Run `npm run tokens:build` from the repo root.
4. Run `npm run tokens:test` from the repo root.

Semantic tokens (`color.primary`, everything under `button` and `link`) should
reference a primitive rather than repeat a literal.

## The naming rule

One custom-property vocabulary, in both apps. The property name is `--` plus the
token path joined with `-`, with two adjustments:

| Token path | Custom property |
| --- | --- |
| `color.brand.600` | `--color-brand-600` |
| `color.primary` | `--color-primary` |
| `font.sans` | `--font-sans` |
| `text.base.size` | `--text-base` — a whole trailing `size` segment is dropped |
| `text.base.line-height` | `--text-base--line-height` |
| `font-weight.bold` | `--font-weight-bold` |
| `spacing.4` | `--spacing-4` |
| `radius.md` | `--radius-md` |
| `duration.base` | `--duration-base` |
| `ease.out` | `--ease-out` |
| `button.font-size` | `--button-font-size` — only a whole segment goes, never a suffix |

The top-level groups match Tailwind 4's namespaces (`--color-*`, `--font-*`,
`--text-*`, `--font-weight-*`, `--spacing-*`, `--radius-*`, `--ease-*`) so the
portal's utilities pick them up. `--duration-*`, `--button-*`, and `--link-*`
are not Tailwind namespaces; they are plain custom properties both apps read.

## theme.json: two owners, one writer per section

`apps/www/themes/woptimize-theme/theme.base.json` is **committed and
hand-edited** — it is the theme's half. The build reads it, injects the token
sections, and writes `theme.json`.

The token build owns exactly these keys:

- `settings.color.palette` ← every `color.*` token
- `settings.typography.fontFamilies` ← every `font.*` token
- `settings.typography.fontSizes` ← every `text.*.size` token
- `settings.spacing.spacingSizes` ← every `spacing.*` token

The theme owns every other key. If `theme.base.json` defines one of the four,
the build **stops with exit 1 and names the key** rather than picking a winner.
The build never writes `styles.*`.

Preset slugs are the token sub-path in kebab case (`brand-600`, `primary`), and
the preset name is that slug title-cased (`Brand 600`, `Primary`).

## Rules

- Output is byte-deterministic. Two builds with no source change produce
  identical bytes — no timestamps anywhere.
- Base styles live in `@layer base`, so the apps' own CSS and Tailwind's
  utilities always win.
- No components. Tokens plus the base envelope — `html`, `body`, headings, `a`,
  and the single `.wo-button` class — and nothing more.
- No webfont files and no `@font-face` here. `font.sans` is a system stack until
  a brand font is chosen.
- Style Dictionary is pinned at `^5.5.2`; it is ESM-only.

## Tests

```bash
npm run tokens:test      # from the repo root: installs, builds, then tests
```

The suite builds into a throwaway repo-shaped folder, so it never touches the
real artifacts. It covers determinism, reference resolution, the naming rule,
the theme.json merge, both DDEV setup commands' section 0, and every failure
mode above.

It steers the build with `WO_TOKENS_REPO_ROOT`, the env override for the repo
root that `build.js` falls back on when no `repoRoot` is passed. Use it to build
into a scratch tree instead of the real apps:

```bash
WO_TOKENS_REPO_ROOT=/tmp/scratch-repo node build.js
```

## What the build refuses

All of these stop the build with exit 1 **before** any artifact is written, so
a bad input never leaves a half-written tree:

- a `{group.path}` reference that points at nothing — every offender is named,
  including references inside array values such as font stacks
- two token paths that collapse to one custom property (`color.foo-bar` and
  `color.foo.bar` both want `--color-foo-bar`), or to one theme.json preset slug
- a `var(--x)` in `src/base.css` that no token defines — a typo there is
  otherwise invisible, the declaration simply does nothing
- `theme.base.json` missing, not valid JSON, not a JSON object, or claiming one
  of the four token-owned keys
- token sources that hold no `$value` anywhere
