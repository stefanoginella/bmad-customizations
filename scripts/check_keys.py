#!/usr/bin/env python3
# /// script
# requires-python = ">=3.11"
# ///
"""Check that every key an override sets is still declared by the shipped skill.

BMad merges `_bmad/custom/<skill>.toml` over `.claude/skills/<skill>/customize.toml`.
The merge itself never complains: a key the shipped file no longer declares is
simply added to the result and then ignored, so a BMad upgrade can disable an
override in complete silence. `render_skill.py` catches this for skills that
have a `workflow.md` render entry; skills without one have no check at all.
This is that check, and it works for both.

Usage:  check_keys.py --override PACK/<skill>.toml --shipped SKILL_DIR/customize.toml
Exit:   0 every key is declared, 1 at least one is orphaned, 2 a file is unusable.
"""

import argparse
import sys
import tomllib
from pathlib import Path


def walk(override, shipped, prefix=""):
    """Yield dotted paths set by `override` that `shipped` does not declare."""
    for key, value in override.items():
        path = f"{prefix}{key}"
        if key not in shipped:
            yield path
            continue
        # Recurse into plain tables only. An array of tables is keyed by `id`
        # at merge time, so its entries are ours to add — only the array itself
        # must still be declared.
        if isinstance(value, dict) and isinstance(shipped[key], dict):
            yield from walk(value, shipped[key], f"{path}.")


def load(path: Path):
    try:
        with path.open("rb") as handle:
            return tomllib.load(handle)
    except FileNotFoundError:
        sys.stderr.write(f"not found: {path}\n")
        raise SystemExit(2)
    except tomllib.TOMLDecodeError as error:
        sys.stderr.write(f"invalid TOML in {path}: {error}\n")
        raise SystemExit(2)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--override", required=True, type=Path)
    parser.add_argument("--shipped", required=True, type=Path)
    args = parser.parse_args()

    orphans = sorted(walk(load(args.override), load(args.shipped)))
    for path in orphans:
        print(path)
    return 1 if orphans else 0


if __name__ == "__main__":
    raise SystemExit(main())
