# `.agents/` — the skills for developing this package

These are for working **in this repository** — the directory that holds this package's
`composer.json`. They are hand-maintained: unlike
the application repository that installs the package, this one has no
`.skills/` sources, no `_sync.py` generator of them and no `verify.py` guard, so a file here is the
only copy and is edited in place. Every tool that reads `.agents/skills/<name>/SKILL.md` finds
them; the frontmatter `description` is what decides when one is invoked.

| Skill               | What it does                                                                                                                                                                                                                                                                |
|---------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `wdm-commit`        | commit a change here: the message shape `bin/weighing.php` reads back, the `## Unreleased` entry that decides the bump, the signature, and the two files that are never committed                                                                                           |
| `wdm-push`          | get commits and annotated tags onto GitHub from PowerShell 7, recognise the two refusals, and prove the tag landed in three layers                                                                                                                                          |
| `wdm-deploy`        | publish a version: weigh the bump, promote the CHANGELOG, tag it, push it, confirm Packagist knows it, and hand the consumer the pin to raise                                                                                                                               |
| `wdm-issue`         | record an WDM fault as a markdown document: activate on the `/wdm-issue` marker, or for a prompt mentioning a problem or an issue once the user answers yes to "Is this an WDM issue?", then write the response into the issue or investigation folder                      |
| `wdm-investigation` | record an WDM investigation as a markdown document: activate on the `/wdm-investigation` marker, or for a prompt containing investigate or "find out why" once the user answers yes to "Is this an WDM investigation?", then write the response into `docs/investigations/` |
| `wdm-plan`          | record an WDM plan as a markdown document: activate on the `/wdm-plan` marker, or for a prompt containing "plan a", "plan the" or "need a plan" once the user answers yes to "Is this an WDM plan?", then write the response into `docs/planning/`                          |
| `wdm-report`        | record a report as a markdown document: activate on the `/wdm-report` marker, or for a prompt containing "create a report", "report the" or "need a report" once the user answers yes to "Is this an WDM report?", then write the response into `docs/reports/`            |
| `wdm-runbook`       | record a runbook as a markdown document: activate on the `/wdm-runbook` marker, or for a prompt containing "create a runbook" or "create this as a runbook" once the user answers yes to "Is this an WDM runbook?", then write the response into `docs/runbooks/`          |

The written-down procedures they wrap are [`RELEASING.md`](../RELEASING.md) (what a release
is, and what `bin/release.php` does at each step) and [`PUSHING.md`](../PUSHING.md) (the
remote, what authenticates here, and why MSYS fails). Those two files are the authority; a
skill here names them rather than restating them, so the two cannot drift.

The other four files here are not instructions. Two are records: `machine.local.json` is this
machine's own facts, gitignored, and `gate.json` is the last gate run as
`php bin/checks.php --record` wrote it — committed, because `HANDOFF.md` renders its summary from it
instead of a session copying the numbers out of a terminal, and the same command re-renders that
block from the record it just wrote, so the note and the run it quotes cannot be taken apart. The
third is `sample.machine.local.json`, the copyable form of the first: a fresh clone has nothing to
copy from, so this one is committed with a token where each of this machine's facts goes. The fourth
is `manage-config.py`, the renderer that answers the harness templates out of the record and writes
the configuration the harness reads — it writes no rule, which is why the skills beside it have none.

Before the first command that shells out, take the interpreter from that record rather than from
`PATH`, and do it even when a thread opened nothing but this file. `machine.local.json` names
`php.exe` and states whether a bare `php` resolves in `php.onPath` — that answer is this machine's
own fact rather than a rule, and the recorded interpreter is the one the gate and the tools were
measured with either way. Searching the disk for a binary of that name is the search the record
exists to replace. A fresh clone has nothing to copy from, so the same file in token form is
committed as `sample.machine.local.json`: copy it to the name above and replace each `__TOKEN__` with
this machine's own value. [`AGENTS.md`](../AGENTS.md) §2 carries the same rule in the load order it
belongs to.

The state of the work itself — what is uncommitted, unpushed and in no release a consumer can
install, as of the day it was taken — is in [`HANDOFF.md`](../HANDOFF.md). It is a snapshot
rather than a rule, so [`tests/Unit/Docs/HandoffTest.php`](../tests/Unit/Docs/HandoffTest.php)
holds it to the tree through [`tests/Support/Handoff.php`](../tests/Support/Handoff.php), in the
register of the commit the note names as its basis: the paths, commits, tags and files it
describes have to still be there, and the branch moving on is not what fails it. It opens by
telling the reader which three commands to re-run before trusting it.
