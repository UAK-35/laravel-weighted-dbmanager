---
name: wdm-runbook
description: Invoke on @wdm-runbook mentions, on a prompt that opens with the `/wdm-runbook` marker, or on any prompt containing the phrase "create a runbook" or "create this as a runbook" once the user has answered yes to "Is this an WDM runbook?" — it records the runbook as a markdown document under docs/runbooks/.
---

# `wdm-runbook` — record a runbook as a markdown document

## When it activates

Two ways in, and only one of them asks:

1. **The marker.** If the prompt opens with the marker `/wdm-runbook`, activate this skill
   immediately — no question — and treat the rest of the prompt as the procedure to write down.
2. **The phrases.** If the prompt does *not* open with the marker but contains the phrase
   `create a runbook` or `create this as a runbook`, ask the user one yes/no question **before
   doing anything else**:

   > Is this an WDM runbook?

   - **Yes** → activate this skill before writing the response.
   - **No** → do not activate it; answer the prompt as normal.

The marker always wins: when `/wdm-runbook` is present the skill is active whatever else the prompt
says, and the question is never asked.

## What it does

1. Answer the prompt first — work out the procedure, walk it, and say what each step does and what it
   is expected to show. The document is written *from* the answer, not before it.
2. Once the response is complete, write **one markdown document** capturing that response into
   `docs/runbooks/`, named `NNNN-YYYY-MM-DD_runbook-<slug>.md`:
   - `NNNN` is the next number in the folder — `0001`, `0002`, `0003`, … — read from the highest
     number already there rather than derived from the date, so a runbook written today can never
     take a number an older runbook already holds;
   - `YYYY-MM-DD` is the date the document is written, in that order, and the underscore before
     `runbook` is part of the name rather than a separator to be written as a hyphen;
   - pick `<slug>` from the prompt — the task, the command, the recovery being written down — in
     short kebab-case, so the filename reads as what the document is;
   - the result is e.g. `docs/runbooks/0001-2026-11-01_runbook-...md`. A runbook already in the
     folder keeps its name; the number and date are assigned once, when it is written, and never
     renumbered as later runbooks arrive.
3. When the folder is normalized, the existing runbooks are ordered by their original creation date
   — the first git addition following renames, or the filesystem creation time for one no commit
   carries — earliest is `0001`, ties broken by the existing filename. Renumbering a collection
   preserves each document's date, slug and contents and updates the references and the handoff
   declarations with it.
4. The document holds the response rather than a summary of it, and is written to be followed rather
   than read: when to reach for it, what has to be true before the first step (access, a maintenance
   window, a backup, the version it was written against), the steps in the order they are taken with
   the exact command for each and the output that says it worked, what to check before moving on, how
   to stop and how to undo what has been done, what to do when a step fails — and which failures are
   safe to retry — and what the runbook deliberately leaves out. Redact secrets (passwords, tokens)
   and leave out this machine's own paths.

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
- `.agents/skills/wdm-report/SKILL.md` — the sibling skill for a review or an audit, which files under
  `docs/reports/`.
- `.agents/skills/wdm-investigation/SKILL.md` — the sibling skill for a why/how question, which
  files under `docs/investigations/`.
- `.agents/skills/wdm-plan/SKILL.md` — the sibling skill for a change that is being planned, which
  files under `docs/planning/`.
- `.agents/skills/wdm-issue/SKILL.md` — the sibling skill for a fault with a fix, which files under
  `docs/issues/` and `docs/investigations/`.
