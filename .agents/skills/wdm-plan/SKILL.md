---
name: wdm-plan
description: Invoke on @wdm-plan mentions, on a prompt that opens with the `/wdm-plan` marker, or on any prompt containing the phrase "plan a", "plan the" or "need a plan" once the user has answered yes to "Is this an WDM plan?" — it records the plan as a markdown document under docs/planning/.
---

# `wdm-plan` — record an WDM plan as a markdown document

## When it activates

Three ways in, in the order they win, and only the last one asks:

1. **An explicit mention.** An `@wdm-plan` mention anywhere in the prompt activates this skill
   immediately — no question — and the rest of the prompt is the plan to work on.
2. **The marker.** If the prompt **opens** with `/wdm-plan`, activate this skill immediately — no
   question — and treat the rest of the prompt as the plan to work on.
3. **The phrases.** If neither is present but the prompt contains the phrase `plan a`, `plan the`
   or `need a plan`, ask the user one yes/no question **before doing anything else**:

   > Is this an WDM plan?

   - **Yes** → activate this skill before writing the response.
   - **No** → do not activate it; answer the prompt as normal.

The order is the rule: a mention, or a marker at the prompt's opening, wins over the words, and the
question is never asked when either is present. What the first two do not cover is a marker or a
mention **quoted** — inside backticks, in a sentence about this skill rather than addressed to it —
and that is not an invocation: a quotation is a reader describing the convention, not using it.

## What it does

1. Answer the prompt first — work out the plan, weigh the steps, say what each one changes and
   what it would be verified by, whatever the prompt asks. The document is written *from* the
   answer, not before it.
2. Once the response is complete, write **one markdown document** capturing that response into
   `docs/planning/`, named `NNNN-YYYY-MM-DD_plan-<slug>.md`:
   - `NNNN` is the next number in the folder — `0001`, `0002`, `0003`, … — read from the highest
     number already there rather than derived from the date, so a plan written today can never
     take a number an older plan already holds;
   - `YYYY-MM-DD` is the date the document is written, in that order, and the underscore before
     `plan` is part of the name rather than a separator to be written as a hyphen;
   - pick `<slug>` from the prompt — the feature, the command, the change being planned — in
     short kebab-case, so the filename reads as what the document is;
   - the result is e.g. `docs/planning/0004-2026-10-29_plan-...md`. A file already in the folder
     keeps its name; the number and date are assigned once, when it is written, and never
     renumbered as later plans arrive.
3. The document holds the response rather than a summary of it: the context (cluster, service,
   container, task, region, config read, where the plan is about a live deployment), what is
   being planned and why, the steps in the order they would be taken with what each one changes,
   the verification each step is held to, the risks and the dependencies between steps, and what
   the plan deliberately leaves out — written so a reader who was not in the session can act on
   it. Where a step is a command or carries an exit code, write the command rather than
   describing it. Redact secrets (passwords, tokens) and leave out this machine's own paths.

## Keeping the repository green

This package holds its own records to the tree, and a new file under `docs/` is no exception:

- add a line for the new document to `Handoff::PENDING_DESCRIPTIONS` in
  `tests/Support/Handoff.php`, record the reading with
  `php bin/handoff.php --reaffirm=<path>`, then run `php bin/handoff.php --recorded` to re-render
  the note's pending block — the table is rendered from the files this checkout has that the
  register does not carry, so a line with no current reading is refused by name rather than
  rendered blank;
- write paths in the document **repository-relative** — `docs/…`, `src/…`, `.agents/…` — which is
  the spelling `AGENTS.md` and these skills already use; a Unix absolute path belongs only where a
  container's own root is the point of the example, and never for a file this repository holds,
  because a drive letter, a home directory or a network share is a machine and the guards in
  `tests/Unit/Support/` report one;
- do not commit unless the user asks; the document is the deliverable.

## Related

- `AGENTS.md` section 0 — the scope rule every path above obeys.
- `.agents/skills/wdm-issue/SKILL.md` — the sibling skill for a fault, which files under
  `docs/issues/` and `docs/investigations/`.
- `.agents/skills/wdm-investigation/SKILL.md` — the sibling skill for a why/how question, which
  files under `docs/investigations/`.
- `.agents/skills/wdm-commit/SKILL.md` — how a commit is made here, if one is asked for.
