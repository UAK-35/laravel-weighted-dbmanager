#!/usr/bin/env python3
"""Render `opencode.jsonc` from `opencode.jsonc.template` and this machine's own record.

WHY A TEMPLATE RATHER THAN ONE FILE
-----------------------------------
  `opencode.jsonc` is the agent harness's configuration for this checkout, and one value in it is a
  fact about the machine — the platform's temp directory, in the permission resource that allows the
  harness to work in it. A file carrying that value cannot be committed (a path that resolves for one
  person is the one thing a carried file may not name), and a file with the value replaced by a token
  cannot be *read* by the harness. So the file is generated: the template is carried, the record is
  machine state, and this renders the one into the other.

WHERE IT LIVES, AND WHY
-----------------------
  Beside the record it reads, in `.agents/`, which is also the part of the tree `.gitattributes` keeps
  out of a consumer's tarball. It is not a program of this package — nothing under `src/` or `bin/`
  calls it — so it does not belong at the root beside the manifest, and a consumer has no use for it.

THE SUBSTITUTION MAP
--------------------
  One entry per placeholder: the token, and the section and key its value is answered from in
  `.agents/machine.local.json`. The answer is taken out of the record rather than typed here, so this
  file carries no machine path of its own and a moved value is one edit in the record.

  The template writes the token in angle brackets — `<__SYSTEM_TEMP_FOLDER__>` — because in a JSON
  string the brackets delimit it from the rest of the value. Either form is consumed: the bracketed
  one first, then the bare token, so a future use outside a string works unchanged.

USAGE
  python .agents/manage-config.py            # write opencode.jsonc from the template
  python .agents/manage-config.py --check    # compare; write nothing; exit 1 when out of step

EXIT CODES
  0  written, or already in step under --check
  1  out of step under --check, or a token the map cannot answer
  2  usage error, or a missing template or record
"""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent

TEMPLATE = ROOT / "opencode.jsonc.template"
OUTPUT = ROOT / "opencode.jsonc"
RECORD = HERE / "machine.local.json"

#: Placeholder -> (section, key) in `.agents/machine.local.json`.
#: One line per token; a token that is not here is refused rather than left in the output.
SUBSTITUTIONS: dict[str, tuple[str, str]] = {
    "__SYSTEM_TEMP_FOLDER__": ("systemTempFolder", "systemTempFolderPath"),
}

#: The shape the layer reads: `__[A-Z0-9_]+__`. A token outside it is invisible to the map, which is
#: why the pattern is checked against the rendered output rather than trusted to the loop above.
TOKEN = re.compile(r"__[A-Z0-9][A-Z0-9_]*__")


class Refusal(Exception):
    """A refusal with a sentence for the terminal and exit code 1."""


def answer(record: dict, token: str) -> str:
    """The value one token stands for, read out of the record rather than written here."""
    section, key = SUBSTITUTIONS[token]

    node = record.get(section)

    if not isinstance(node, dict) or key not in node:
        raise Refusal(
            f"{RECORD} carries no {section}.{key}, so {token} cannot be answered. "
            f"Add the section and the key to the record, and the token form of it to "
            f".agents/sample.machine.local.json."
        )

    value = node[key]

    if not isinstance(value, str) or value.strip() == "":
        raise Refusal(
            f"{RECORD} writes {section}.{key} as "
            f"{'an empty string' if isinstance(value, str) else type(value).__name__}, "
            f"which is not a path {token} can answer."
        )

    # A trailing separator would meet the template's own and produce `//`. The template supplies the
    # separator; the value supplies the directory.
    return value.strip().rstrip("/\\")


def render(template: str, record: dict) -> str:
    """The template with every mapped token answered, and nothing left unresolved."""
    rendered = template

    for token in SUBSTITUTIONS:
        value = answer(record, token)
        rendered = rendered.replace(f"<{token}>", value).replace(token, value)

    remaining = sorted(set(TOKEN.findall(rendered)))

    if remaining:
        raise Refusal(
            f"the template leaves {', '.join(remaining)} unresolved, and this renderer has no "
            f"answer for {'it' if len(remaining) == 1 else 'them'}. A token nothing resolves is a "
            f"name nothing answers: either add it to SUBSTITUTIONS with the record's section and "
            f"key, or take it out of the template."
        )

    return rendered


def read_record() -> dict:
    """`.agents/machine.local.json`, as an object."""
    if not RECORD.is_file():
        raise Refusal(
            f"{RECORD} is not in this checkout. Copy .agents/sample.machine.local.json to that name "
            f"and replace each __TOKEN__ with this machine's own value."
        )

    try:
        record = json.loads(RECORD.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exception:
        raise Refusal(f"{RECORD} does not read as JSON: {exception}") from exception

    if not isinstance(record, dict):
        raise Refusal(f"{RECORD} decodes to {type(record).__name__}, not the record of facts this reads.")

    return record


def main(argv: list[str]) -> int:
    unknown = [argument for argument in argv if argument != "--check"]

    if unknown:
        print(f"unknown option: {' '.join(unknown)}", file=sys.stderr)
        print("usage: python .agents/manage-config.py [--check]", file=sys.stderr)

        return 2

    check = "--check" in argv

    if not TEMPLATE.is_file():
        print(f"the template is not there: {TEMPLATE}", file=sys.stderr)

        return 2

    try:
        rendered = render(TEMPLATE.read_text(encoding="utf-8"), read_record())
    except Refusal as refusal:
        print(f"refused: {refusal}", file=sys.stderr)

        return 1

    if check:
        current = OUTPUT.read_text(encoding="utf-8") if OUTPUT.is_file() else None

        if current == rendered:
            print(f"{OUTPUT.name} is in step with {TEMPLATE.name}.")

            return 0

        print(
            f"{OUTPUT.name} is out of step with {TEMPLATE.name}: "
            f"{'it is not there' if current is None else 'its bytes differ'}. "
            f"Run python .agents/manage-config.py to write it.",
            file=sys.stderr,
        )

        return 1

    OUTPUT.write_text(rendered, encoding="utf-8", newline="")
    print(f"wrote {OUTPUT.name} from {TEMPLATE.name} ({len(SUBSTITUTIONS)} token(s) answered).")

    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
