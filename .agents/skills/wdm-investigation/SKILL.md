---
name: wdm-investigation
description: Invoke on @wdm-investigation mentions, on a prompt that opens with the `/wdm-investigation` marker, or on any prompt containing the word investigate or the phrase "find out why" once the user has answered yes to "Is this an LPR investigation?" — it records the investigation as a markdown document under docs/investigations/.
---

# `wdm-investigation` — record an LPR investigation as a markdown document

## When it activates

Two ways in, and only one of them asks:

1. **The marker.** If the prompt opens with the marker `/wdm-investigation`, activate this skill
   immediately — no question — and treat the rest of the prompt as the investigation to work on.
2. **The words.** If the prompt does *not* open with the marker but contains the word
   `investigate` or the phrase `find out why`, ask the user one yes/no question **before doing
   anything else**:

   > Is this an LPR investigation?

   - **Yes** → activate this skill before writing the response.
   - **No** → do not activate it; answer the prompt as normal.

The marker always wins: when `/wdm-investigation` is present the skill is active whatever else the
prompt says, and the question is never asked.

## What it does

1. Answer the prompt first — investigate the behaviour, work out why it happens, propose a fix or
   a fix suggestion, whatever the prompt asks. The document is written *from* the answer, not
   before it.
2. Once the response is complete, write **one markdown document** capturing that response into
   `docs/investigations/`, named `investigation-<slug>.md`:
   - pick `<slug>` from the prompt — the command, the warning text, the symptom — in short
     kebab-case, so the filename reads as what the document is.
3. The document holds the response rather than a summary of it: the context (cluster, service,
   container, task, region, config read), the evidence, the root cause or the answer, and the fix
   or the recommendation — written so a reader who was not in the session can act on it. Redact
   secrets (passwords, tokens) and leave out this machine's own paths.

## Keeping the repository green

This package holds its own records to the tree, and a new file under `docs/` is no exception:

- add a line for the new document to `Handoff::PENDING_DESCRIPTIONS` in
  `tests/Support/Handoff.php`, then run `php bin/handoff.php --recorded` to re-render the note's
  pending block;
- keep paths in the document absolute-Unix only — no drive letters, home directories or network
  shares — so `AGENTS.md` section 0's walk and the machine-path guard stay quiet;
- do not commit unless the user asks; the document is the deliverable.

## Related

- `AGENTS.md` section 0 — the scope rule every path above obeys.
- `.agents/skills/wdm-issue/SKILL.md` — the sibling skill for a fault, which files under the
  issue and investigation folders.
- `.agents/skills/pkg-commit/SKILL.md` — how a commit is made here, if one is asked for.
