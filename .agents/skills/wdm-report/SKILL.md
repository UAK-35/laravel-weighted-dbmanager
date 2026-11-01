---
name: wdm-report
description: Invoke on @wdm-report mentions, on a prompt that opens with the `/wdm-report` marker, or on any prompt containing the phrase "create a report", "report the" or "need a report" once the user has answered yes to "Is this an WDM report?" — it records the report as a markdown document under docs/reports/.
---

# `wdm-report` — record a report as a markdown document

## When it activates

Two ways in, and only one of them asks:

1. **The marker.** If the prompt opens with the marker `/wdm-report`, activate this skill
   immediately — no question — and treat the rest of the prompt as the subject to report on.
2. **The phrases.** If the prompt does *not* open with the marker but contains the phrase
   `create a report`, `report the` or `need a report`, ask the user one yes/no question **before
   doing anything else**:

   > Is this an WDM report?

   - **Yes** → activate this skill before writing the response.
   - **No** → do not activate it; answer the prompt as normal.

The marker always wins: when `/wdm-report` is present the skill is active whatever else the prompt
says, and the question is never asked.

## What it does

1. Answer the prompt first — do the review, the inventory, the audit or whatever the prompt asks,
   and write the answer as the response. The document is written *from* that answer, not before it.
2. Once the response is complete, write **one markdown document** capturing that response into
   `docs/reports/`, named `NNNN-YYYY-MM-DD_rpt-<slug>.md`:
   - `NNNN` is the next number in the folder — `0001`, `0002`, `0003`, … — read from the highest
     number already there rather than derived from the date, so a report written today can never
     take a number an older report already holds;
   - `YYYY-MM-DD` is the date the document is written, in that order, and the underscore before
     `rpt` is part of the name rather than a separator to be written as a hyphen;
   - pick `<slug>` from the prompt — the subject reviewed, the area audited, the question asked —
     in short kebab-case, so the filename reads as what the document is;
   - the result is e.g. `docs/reports/0003-2026-11-01_rpt-...md`.
3. When the folder is normalized, the existing reports are ordered by their original creation date
   — the first git addition following renames, or the filesystem creation time for a report no
   commit carries — earliest is `0001`, ties broken by the existing filename. Renumbering a
   collection preserves each report's date, slug and contents and updates the references and the
   handoff declarations with it; a report already in order keeps the name it has, and the number
   and date are assigned once, when it is written, never re-derived from a later edit.
4. The document holds the response rather than a summary of it: what was reviewed and how (the
   commands, the files, the environment the reading was taken in), what was found with the evidence
   behind each finding, the findings in the order they should be acted on with what each one costs
   and what it would take to confirm it, and what the review deliberately leaves out — written so a
   reader who was not in the session can act on it. Where a finding is a command or carries an exit
   code, write the command rather than describing it. Redact secrets (passwords, tokens) and leave
   out this machine's own paths.

## Keeping the repository green

This package holds its own records to the tree, and a new file under `docs/` is no exception:

- add a line for the new document to `Handoff::PENDING_DESCRIPTIONS` in
  `tests/Support/Handoff.php`, record the reading with
  `php bin/handoff.php --reaffirm=<path>`, then run `php bin/handoff.php --recorded` to re-render the
  note's pending block — the table is rendered from the files this checkout has that the register
  does not carry, so a line with no current reading is refused rather than rendered blank;
- keep paths in the document absolute-Unix only — no drive letters, home directories or network
  shares — so `AGENTS.md` section 0's walk and the machine-path guard stay quiet;
- do not commit unless the user asks; the document is the deliverable.

## Related

- `AGENTS.md` section 0 — the scope rule every path above obeys.
- `.agents/skills/wdm-investigation/SKILL.md` — the sibling skill for a why/how question, which
  files under `docs/investigations/`.
- `.agents/skills/wdm-plan/SKILL.md` — the sibling skill for a change that is being planned, which
  files under `docs/planning/`.
- `.agents/skills/wdm-issue/SKILL.md` — the sibling skill for a fault with a fix, which files under
  `docs/issues/` and `docs/investigations/`.
