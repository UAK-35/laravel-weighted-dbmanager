---
name: wdm-issue
description: Invoke on @wdm-issue mentions, on a prompt that opens with the `/wdm-issue` marker, or on any prompt that contains the word problem or issue once the user has answered yes to "Is this an WDM issue?" — it records an WDM fault as a markdown document under the issue and investigation folders.
---

# `wdm-issue` — record an WDM issue as a markdown document

## When it activates

Two ways in, and only one of them asks:

1. **The marker.** If the prompt opens with the marker `/wdm-issue`, activate this skill
   immediately — no question — and treat the rest of the prompt as the issue to work on.
2. **The word.** If the prompt does *not* open with the marker but contains the word
   `problem` or `issue`, ask the user one yes/no question **before doing anything else**:

   > Is this an WDM issue?

   - **Yes** → activate this skill before writing the response.
   - **No** → do not activate it; answer the prompt as normal.

The marker always wins: when `/wdm-issue` is present the skill is active whatever else the prompt
says, and the question is never asked.

## What it does

1. Answer the prompt first — investigate the fault, diagnose it, propose a fix or a fix
   suggestion, whatever the prompt asks. The document is written *from* the answer, not before
   it.
2. Once the response is complete, write **one markdown document** capturing that response:
   - a fault with a fix → `docs/issues/issue-<slug>.md`
   - an investigation with no defect, a why/how question → `docs/investigations/investigation-<slug>.md`
   - pick `<slug>` from the prompt — the command, the warning text, the symptom — in short
     kebab-case, so the filename reads as what the document is.
3. The document holds the response rather than a summary of it: the context (cluster, service,
   container, task, region, config read), the evidence, the root cause, and the fix or the
   answer — written so a reader who was not in the session can act on it. Redact secrets
   (passwords, tokens) and leave out this machine's own paths.

## Keeping the repository green

This package holds its own records to the tree, and a new file under `docs/` is no exception:

- add a line for the new document to `Handoff::PENDING_DESCRIPTIONS` in
  `tests/Support/Handoff.php`, then run `php bin/handoff.php --reaffirm=<path>` to record the
  reading those words were written against, and `php bin/handoff.php --recorded` to re-render the
  note's pending block — a line without a current reading is refused, so the table cannot describe
  a file nobody read;
- keep paths in the document absolute-Unix only — no drive letters, home directories or network
  shares — so `AGENTS.md` section 0's walk and the machine-path guard stay quiet;
- do not commit unless the user asks; the document is the deliverable.

## Related

- `AGENTS.md` section 0 — the scope rule every path above obeys.
- `.agents/skills/pkg-commit/SKILL.md` — how a commit is made here, if one is asked for.
