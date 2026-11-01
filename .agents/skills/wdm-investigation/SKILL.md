---
name: wdm-investigation
description: Invoke on @wdm-investigation mentions, on a prompt that opens with the `/wdm-investigation` marker, or on any prompt containing the word investigate or the phrase "find out why" once the user has answered yes to "Is this an WDM investigation?" — it records the investigation as a markdown document under docs/investigations/.
---

# `wdm-investigation` — record an WDM investigation as a markdown document

## When it activates

Three ways in, in the order they win, and only the last one asks:

1. **An explicit mention.** An `@wdm-investigation` mention anywhere in the prompt activates this
   skill immediately — no question — and the rest of the prompt is the investigation to work on.
2. **The marker.** If the prompt **opens** with `/wdm-investigation`, activate this skill
   immediately — no question — and treat the rest of the prompt as the investigation to work on.
3. **The words.** If neither is present but the prompt contains the word `investigate` or the
   phrase `find out why`, ask the user one yes/no question **before doing anything else**:

   > Is this an WDM investigation?

   - **Yes** → activate this skill before writing the response.
   - **No** → do not activate it; answer the prompt as normal.

The order is the rule: a mention, or a marker at the prompt's opening, wins over the words, and the
question is never asked when either is present. What the first two do not cover is a marker or a
mention **quoted** — inside backticks, in a sentence about this skill rather than addressed to it —
and that is not an invocation: a quotation is a reader describing the convention, not using it.

## What it does

1. Answer the prompt first — investigate the behaviour, work out why it happens, propose a fix or
   a fix suggestion, whatever the prompt asks. The document is written *from* the answer, not
   before it.
2. Once the response is complete, write **one markdown document** capturing that response into
   `docs/investigations/`, named `NNNN-YYYY-MM-DD_inv-<slug>.md`:
   - `NNNN` is the next number in the folder — `0001`, `0002`, `0003`, … — read from the highest
     number already there rather than derived from the date, so a record written today can never
     take a number an older record already holds;
   - `YYYY-MM-DD` is the date the document is written, in that order, and the underscore before
     `inv` is part of the name rather than a separator to be written as a hyphen;
   - pick `<slug>` from the prompt — the command, the warning text, the symptom — in short
     kebab-case, so the filename reads as what the document is;
   - the result is e.g. `docs/investigations/0008-2026-10-29_inv-pgcat-select-1-hang.md`. A file
     already in the folder keeps its name; the number and date are assigned once, when it is
     written, and never renumbered as later records arrive.
3. The document holds the response rather than a summary of it: the context (cluster, service,
   container, task, region, config read), the evidence, the root cause or the answer, and the fix
   or the recommendation — written so a reader who was not in the session can act on it. Redact
   secrets (passwords, tokens) and leave out this machine's own paths.

## Keeping the repository green

This package holds its own records to the tree, and a new file under `docs/` is no exception:

- add a line for the new document to `Handoff::PENDING_DESCRIPTIONS` in
  `tests/Support/Handoff.php`, then run `php bin/handoff.php --reaffirm=<path>` to record the
  reading those words were written against, and `php bin/handoff.php --recorded` to re-render the
  note's pending block — a line without a current reading is refused, so the table cannot describe
  a file nobody read;
- write paths in the document **repository-relative** — `docs/…`, `src/…`, `.agents/…` — which is
  the spelling `AGENTS.md` and these skills already use; a Unix absolute path belongs only where a
  container's own root is the point of the example, and never for a file this repository holds,
  because a drive letter, a home directory or a network share is a machine and the guards in
  `tests/Unit/Support/` report one;
- do not commit unless the user asks; the document is the deliverable.

## Related

- `AGENTS.md` section 0 — the scope rule every path above obeys.
- `.agents/skills/wdm-issue/SKILL.md` — the sibling skill for a fault, which files under the
  issue and investigation folders.
- `.agents/skills/wdm-commit/SKILL.md` — how a commit is made here, if one is asked for.
