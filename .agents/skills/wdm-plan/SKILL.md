---
name: wdm-plan
description: Invoke on @wdm-plan mentions, on a prompt that opens with the `/wdm-plan` marker, or on any prompt containing the phrase "plan a", "plan the" or "need a plan" once the user has answered yes to "Is this an WDM plan?" — it records the plan as a markdown document under docs/planning/.
---

# `wdm-plan` — record an WDM plan as a markdown document

## When it activates

Two ways in, and only one of them asks:

1. **The marker.** If the prompt opens with the marker `/wdm-plan`, activate this skill
   immediately — no question — and treat the rest of the prompt as the plan to work on.
2. **The phrases.** If the prompt does *not* open with the marker but contains the phrase
   `plan a`, `plan the` or `need a plan`, ask the user one yes/no question **before doing
   anything else**:

   > Is this an WDM plan?

   - **Yes** → activate this skill before writing the response.
   - **No** → do not activate it; answer the prompt as normal.

The marker always wins: when `/wdm-plan` is present the skill is active whatever else the prompt
says, and the question is never asked.

## What it does

1. Answer the prompt first — work out the plan, weigh the steps, say what each one changes and
   what it would be verified by, whatever the prompt asks. The document is written *from* the
   answer, not before it.
2. Once the response is complete, write **one markdown document** capturing that response into
   `docs/planning/`, named `plan-<slug>.md`:
   - pick `<slug>` from the prompt — the feature, the command, the change being planned — in
     short kebab-case, so the filename reads as what the document is.
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
  `tests/Support/Handoff.php`, then run `php bin/handoff.php --recorded` to re-render the note's
  pending block — the table is rendered from the files this checkout has that the register does
  not carry, so a file with no line declared for it is refused by name rather than rendered
  blank;
- keep paths in the document absolute-Unix only — no drive letters, home directories or network
  shares — so `AGENTS.md` section 0's walk and the machine-path guard stay quiet;
- do not commit unless the user asks; the document is the deliverable.

## Related

- `AGENTS.md` section 0 — the scope rule every path above obeys.
- `.agents/skills/wdm-issue/SKILL.md` — the sibling skill for a fault, which files under
  `docs/issues/` and `docs/investigations/`.
- `.agents/skills/wdm-investigation/SKILL.md` — the sibling skill for a why/how question, which
  files under `docs/investigations/`.
- `.agents/skills/pkg-commit/SKILL.md` — how a commit is made here, if one is asked for.
