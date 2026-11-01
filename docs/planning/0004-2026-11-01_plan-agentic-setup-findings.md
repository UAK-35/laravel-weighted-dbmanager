# Plan — the agentic setup review's fifteen findings, repaired where each one is read

| | |
|---|---|
| Subject | the fifteen open findings of `docs/reports/0001-2026-10-30_rpt-agentic-setup-review.md`, and the order their repairs can actually be held to |
| Records read | `docs/reports/0001-2026-10-30_rpt-agentic-setup-review.md`, `AGENTS.md`, `.agents/README.md`, `.agents/manage-config.py`, `.agents/sample.machine.local.json`, `opencode.jsonc.template`, `opencode.v1-backup.jsonc.template`, the eight `.agents/skills/*/SKILL.md`, `HANDOFF.md`, `tests/Support/Handoff.php`, `tests/Unit/Docs/DocCitationsTest.php`, `tests/Unit/Docs/SkillDescriptionTest.php`, `tests/Unit/Docs/HandoffTest.php`, `tests/Unit/Support/MachinePathsTest.php`, `bin/checks.php` |
| Files to change | `opencode.jsonc.template`, `opencode.v1-backup.jsonc.template`, `.agents/manage-config.py`, `.agents/sample.machine.local.json`, `.agents/README.md`, `.agents/skills/{wdm-plan,wdm-deploy,wdm-push,wdm-issue,wdm-investigation,wdm-report,wdm-runbook}/SKILL.md`, `AGENTS.md`, `HANDOFF.md`, `tests/Unit/Support/MachinePathsTest.php`, `tests/Unit/Docs/SkillDescriptionTest.php`, `CHANGELOG.md` |
| Contract this plan writes down | a reference, a claim and a procedure are each checked from the place they are written, not from the package root and not from the day they were written |
| Needs a decision | the default agent (F01), the guards' corpus (F14), the handoff snapshot (F15), the ignored rules copy (F13) |

## Why the findings are one subject

Twelve of the fifteen are the same mistake in different files: something written at a distance
from the thing it describes. A link counted from the package root instead of from the skill's own
directory (F04), an index that names a file the repository does not have (F01, F12), a branch
hardcoded where the release names one (F07), a step missing from one of five sibling procedures
(F06), a version and a `PATH` fact copied off another machine (F05, F09), a sentence describing a
guard corpus from an earlier shape of it (F14), and a remote tip recorded on a day the remote has
since left behind (F15). The other three are a code-versus-contract disagreement: the renderer's
escaping (F02), the permission table's ordering (F03), and the renderer's missing-record exit code
(F08).

That split decides the shape of the repair. A reference is fixed once and then held by a check that
resolves it from its own directory; a claim is either re-derived from the code or narrowed until it
is true; a code-versus-contract disagreement is resolved by moving whichever half is wrong, never
both. So the plan is five batches, ordered by what a session meets first: the configuration it
loads, then the skills it follows, then the records a fresh clone copies, then the guards that read
those records, then the handoff and the local copy nothing loads.

## What was re-checked while planning, and what moved since the review

Every finding was re-read against the tree before it was planned, because three of them have moved
since 2026-10-30:

| What | Reading today | What it does to the finding |
|---|---|---|
| `.agents/agents/php-code-analyzer.md` | absent, and absent from the whole history: `git log --all -- '.agents/agents/*'` prints nothing | F01 is **not** a restore. The reference is to a file that only ever lived in the application repository this package came from, so the repair is to drop the registration or to author a definition here |
| the skill set | eight directories, including `wdm-report` and `wdm-runbook`, added since the review | F10 and F11 are five document skills, not three; the same wording is in all five |
| `.agents/README.md` | its table lists all eight skills | half of F12 is already done by that change; the remaining halves are the `two files` sentence and the two template comments |
| F15's remote | `HANDOFF.md` §2 records `origin/dev` as `a99c22b`; `git ls-remote` answers `d9e8db5`; `HEAD` is `cfad0ce` | still red, and the note's `HEAD` row is behind too — `HandoffTest::test_the_origin_row_still_names_the_tip_the_remote_answers` fails on the first of those |
| the guards' corpus | `DocCitationsTest::records()` and `bin/checks.php`'s `recordFiles()` both glob `docs/*.md` | F14 stands; `docs/` holds four folders and 19 nested records that neither reads |
| `--reaffirm` in the document skills | in issue, investigation, report and runbook; **not** in plan | F06 confirmed, and it is the only one of five |
| relative links in the skills | six `](../../` targets across `wdm-deploy` and `wdm-push` | F04 confirmed at exactly those six |

Two more facts were measured here and matter to the steps: `python --version` answers on this
machine, and the machine record carries **no python row** — which is why Batch 1's renderer repair
gets a probe command rather than a permanent PHP-unit guard.

## Batch 1 — the configuration a session loads (F01, F02, F03, F08)

### Step 1.1 — F01: the default agent points at a body this repository never had

The templates select `php-code-analyzer` as the default agent and tell it to read
`.agents/agents/php-code-analyzer.md`, which does not exist and never did. Two resolutions, and
this plan recommends the first:

**(a) drop it — recommended.** Delete `default_agent` from both templates, the `agents` block, and
the `commands.php-analyze` block that names the agent. The rules a session follows are loaded by
`instructions` (`AGENTS.md`, `BEHAVE.mdc`), not by the agent registration, so nothing a session needs
goes with it.

Why (a) rather than authoring a body: the registration's own description is a general PHP analyser,
and this package already runs `pint` and PHPStan at `max` in its gate — a second, unguarded opinion
about the same files is not what the gate is missing. A definition written here to satisfy a
dangling reference would be another repository's prompt wearing this package's name, which is the
drift the `.agents/` skills exist to avoid. And (a) also removes the agent-level `shell *` grant that
F03's second half is about, in the same edit.

**(b) author the body here.** Only if the user wants the analyser: write
`.agents/agents/php-code-analyzer.md` from this package's own rules — the scope rule in `AGENTS.md`
§0, the check names from `bin/checks.php --list`, the tool entry points in `bin/tool.php` — never
transcribed from the application repository, and keep the inline `system` sentence pointing at it.

**Changed:** `opencode.jsonc.template` (lines 8, 15–34, 35–42), `opencode.v1-backup.jsonc.template`
(the same three places in its V1 shape).

**Verified by:** `python .agents/manage-config.py` then `python .agents/manage-config.py --check`
(exit 0), and `grep -c 'php-code-analyzer' opencode.jsonc` → `0` under (a).

**Risk under (a):** a harness that requires a default agent may fall back to its own. That is the
one thing this repository cannot test without an OpenCode session, and it fails visibly on the
first run rather than quietly; if the harness refuses, add an available agent name rather than the
missing file.

### Step 1.2 — F02: the renderer substitutes a raw value into a JSON string

`render()` does `rendered.replace(...)` with the record's value and nothing else, so a Windows path
in the record reaches the output unescaped. Quotes and control characters are in the same hole, and
nothing reads the result before it is written.

**Changed:** `.agents/manage-config.py`.

| Change | Detail |
|---|---|
| encode for the string it lands in | backslash → `\\`, `"` → `\"`, and a control character refused by name rather than encoded |
| normalise a path before encoding | a Windows separator becomes `/` in path-valued substitutions; JSON and the harness both read it, and the record's own value already uses it |
| prove the value survives | round-trip `json.loads('"' + encoded + '"')` and compare with the value; on a difference, refuse instead of writing |
| keep the token sweep | the existing `TOKEN` scan already catches an unanswered token; it does not catch a broken string, which is why the round trip is added rather than relied on |

A full `json.loads` of the output is not available and is not what this needs: the file is JSONC
and carries `//` comments, so the unit of validation is the substituted value, not the document.

**Verified by** a probe that writes nothing, run before and after the change:

```bash
python - <<'PY'
import importlib.util, json
spec = importlib.util.spec_from_file_location("mc", ".agents/manage-config.py")
mc = importlib.util.module_from_spec(spec); spec.loader.exec_module(mc)
out = mc.render('{"a": "<__SYSTEM_TEMP_FOLDER__>"}',
                {"systemTempFolder": {"systemTempFolderPath": "alpha\\delta\\folder"}})
print(out)
print(json.loads(out))
PY
```

Today the `json.loads` raises `Invalid escape: line 1 column 7`, which is F02 reproduced. After the
change it prints the object with either the encoded or the normalised value, and the round trip is
the assertion.

**Deliberately not a permanent test** — see the exclusions at the end: a PHPUnit guard would make
the PHP suite depend on a Python interpreter the machine record does not carry.

### Step 1.3 — F03: the fallback is in front of the rules it is meant to fall back to

The template's own comment says the **last** matching rule wins and that broad rules must come
first. It then puts `shell *` with effect `ask` at lines 96–99, after the three wrapped-command
allows at 56–69 and before the `echo`/`curl` allows. Under the documented rule, every wrapped
command matches the wildcard and asks.

**Changed:** both templates. Move the `shell`/`*`/`ask` entry to the **top** of `permissions`, so
every narrow rule below is later and therefore wins; `echo *` and `curl *` stay where they are,
below it, which is the same reasoning they already relied on. Then the second half: with Step 1.1(a)
the agent-level `shell`/`*`/`allow` disappears; under 1.1(b) drop the agent's `permissions` block
anyway, because the global table is the single readable account of what a shell may run, and this
review could not establish how an agent-level grant merges with a global one.

**Verified by** the order itself and the comment that states the rule — `grep -n '"effect"' -B2` on
the rendered file — plus an honest note in the commit: schema conformance and effective permission
need an installed harness, and are **not** verified here.

### Step 1.4 — F08: a missing record exits 1, and the docstring promises 2

`main()` catches `Refusal` and returns 1 for every refusal, including the missing record that
`read_record()` raises; the module docstring reserves 2 for a usage error or a missing template or
record, and the missing template does return 2.

**Changed:** `.agents/manage-config.py`. The code moves to match the docstring, because a script
classifying setup failures by those codes is the consumer the docstring is for: check
`RECORD.is_file()` in `main()` beside the template check and return 2 with the same sentence
`read_record()` prints today. A record that is present but is not JSON, or not an object, stays a
`Refusal` at 1 — it is malformed rather than missing, and the two want different repairs.

**Verified by** the same in-memory probe with `RECORD` pointed at a path that does not exist:

```bash
python - <<'PY'
import importlib.util, pathlib
spec = importlib.util.spec_from_file_location("mc", ".agents/manage-config.py")
mc = importlib.util.module_from_spec(spec); spec.loader.exec_module(mc)
mc.RECORD = pathlib.Path("does-not-exist/machine.local.json")
print("exit", mc.main(["--check"]))
PY
```

`exit 1` today, `exit 2` after. The record itself is not moved: it is the only source of the
interpreter, so this is probed in memory and never by renaming the file.

## Batch 2 — the skills a session follows (F04, F06, F07, F10, F11)

### Step 2.1 — F04: six links resolve inside `.agents/`, where neither runbook is

Each skill is read from its own directory, so `../../PUSHING.md` from `.agents/skills/wdm-push/`
is `.agents/PUSHING.md`. The runbooks are at the package root: the fixed target counts one parent
segment more than it counts today, and three of them are what a skill's own directory needs to reach
the root. The count is what the table below records; the skills are the files that may write it,
and this record deliberately does not reproduce the literal, because from `docs/planning/` the same
three segments would resolve above the package root — the climb `tests/Unit/Support/RepoEscapesTest.php`
reports, and the reason F11 asks for repository-relative paths in the first place.

| File | Lines | Target today | Target |
|---|---|---|---|
| `.agents/skills/wdm-deploy/SKILL.md` | 15, 83 | `](../../RELEASING.md)`, `](../../PUSHING.md)` | three parent segments |
| `.agents/skills/wdm-push/SKILL.md` | 11, 52, 82, 88 | `](../../PUSHING.md)` ×3, `](../../RELEASING.md)` | three parent segments |

**Verified by** a sweep that resolves every relative link from the file's own directory and prints
the ones that do not exist — six lines today, none after:

```bash
for f in .agents/skills/*/SKILL.md; do
  d=$(dirname "$f")
  grep -o '](\([^)]*\))' "$f" | sed 's/^](//; s/)$//' | grep -v '^https\?:' |
    while read -r t; do [ -e "$d/$t" ] || echo "$f -> $t"; done
done
```

### Step 2.2 — F06: `wdm-plan` renders a pending row it never recorded a reading for

Four of the five document skills say: add the line, run `--reaffirm=<path>`, then run
`--recorded`. `wdm-plan` says the first and the third. `tests/Support/Handoff.php` requires both a
description and a matching fingerprint, so the literal procedure leaves the row unrenderable —
which this very document exercises, because writing it is what registers it.

**Changed:** `.agents/skills/wdm-plan/SKILL.md` "Keeping the repository green" — insert
`php bin/handoff.php --reaffirm=<path>` between the two steps, worded as the siblings word it.

**Verified by** `grep -c reaffirm .agents/skills/*/SKILL.md` → one in each of the five, and by the
registration of this plan (Step 5.2 below), which is the procedure being fixed.

### Step 2.3 — F07: the publish command names `main` whatever branch was cut

`wdm-deploy` explains that a prerelease is cut from `dev`, then publishes with `git push origin
main --follow-tags`; `wdm-push` hardcodes `main` in its command and in its preflight range. After a
prerelease, following either pushes a branch that is not the release, and `--follow-tags` only
carries tags reachable from the history pushed.

**Changed:** both skills.

| File | Change |
|---|---|
| `wdm-deploy/SKILL.md` §3 | `git push origin "$BRANCH" --follow-tags`, where `$BRANCH` is the branch the weighing plan named — `--branch`, defaulting to `main`, `dev` for a prerelease — and a sentence that the branch is the one the plan printed, not an assumption |
| `wdm-push/SKILL.md` | the same command with `BRANCH="$(git rev-parse --abbrev-ref HEAD)"` as the default, plus a guard sentence: a detached `HEAD` prints `HEAD`, so name the branch explicitly there rather than pushing it |
| `wdm-push/SKILL.md` preflight | `git log "origin/$BRANCH..HEAD" --oneline` and `git diff --stat "origin/$BRANCH..HEAD"` |

Nothing is pushed by this plan; the commands are written so that the next one is right.

### Step 2.4 — F10: activation is broader in the frontmatter than in the body

All five document skills say in the frontmatter that an `@wdm-…` mention invokes them, and then
enumerate only an opening slash marker and a keyword question — and close by saying a slash marker
anywhere always activates, which is broader than "opens with". A literal reader can therefore ask on
an explicit mention, or activate on a marker quoted inside unrelated text.

**Changed:** the five document skills, identically: one precedence, stated in the body as the
frontmatter states it — (1) an explicit `@wdm-…` mention, (2) a prompt that **opens** with
`/wdm-…`, (3) a keyword trigger with the yes/no question — and one exclusion sentence: a marker
quoted as an example is not an invocation.

**Verified by** `tests/Unit/Docs/SkillDescriptionTest.php`, which holds each index row to its
skill's frontmatter description; a description edited without its row (or the reverse) fails there.
Because of that, the `wdm-…` rows in `.agents/README.md` are updated in the same edit if any
description changes.

### Step 2.5 — F11: the documents are told to hold paths with no defined root

The five skills require `absolute-Unix only` paths while their own examples and `AGENTS.md` use
repository-relative ones. A Unix absolute path is not confined to the repository, so the guidance
asks for the one spelling that cannot be checked from here.

**Changed:** the five skills, one sentence: paths in these documents are **repository-relative**
(`docs/…`, `src/…`, `.agents/…`); a Unix absolute path appears only where a container's own root is
the point of the example, and never for a file this repository holds.

**Verified by** `php bin/tool.php phpunit tests/Unit/Support/RepoEscapesTest.php
tests/Unit/Support/MachinePathsTest.php` — the two guards that read every carried file for a climb
and for a machine path.

## Batch 3 — the records a fresh clone copies and reads (F05, F09, F12)

### Step 3.1 — F05: the sample record bootstraps facts that belong to a machine and a list that is stale

`.agents/sample.machine.local.json` states PHP `8.4.26` and `onPath=false` where the record says
`8.4.27` and `true`, lists three skill directories where the index and the tree have eight, and its
`about` text tells the reader to leave the version rows alone.

The rule this repair settles: **a value a machine measures is a token; a value the repository owns
is stated.** So:

| Row | Today | Change |
|---|---|---|
| `php.version`, `php.onPath`, `pwsh.onPath`, `composer.version`, `composer.onPath`, `search.rgVersion`, `search.rgOnPath`, `package.latestTag` | values, some stale | tokens — `__PHP_VERSION__`, `__PHP_ON_PATH__`, `__PWSH_ON_PATH__`, `__COMPOSER_VERSION__`, `__COMPOSER_ON_PATH__`, `__RG_VERSION__`, `__RG_ON_PATH__`, `__LATEST_TAG__` |
| `skills.dirs` | three of eight | all eight, in the index's order — the repository owns this list |
| `checks.names`, `checks.count`, `tarball.*`, `git.origin`, the paths already tokenised | unchanged | unchanged; they are the repository's or already tokens |
| `about` | "replace every `__TOKEN__` … the rows that are the same on every machine — the versions, the origin URL, the checks and the tarball rows — are meant to be read and left alone" | name the measured rows as measured: every version, every `onPath`, and the latest tag are read off the machine being set up; the origin URL, the checks and the tarball rows are the repository's |

Two guards move with it, and the second is what keeps the finding fixed:

- `tests/Unit/Support/MachinePathsTest.php` reads the sample's machine facts by name and asserts a
  token; the eight new keys join that list, and its comment (which today says the versions are "the
  same on every machine of this kind") is corrected to the rule above;
- `tests/Unit/Docs/SkillDescriptionTest.php` already enumerates the index's rows and already has
  `test_the_index_lists_every_skill_and_no_other`; it gains the sample as the second reader of that
  list, so a ninth skill added to the tree cannot leave the sample at eight. This is the guard the
  finding was missing rather than the edit it asks for.

**Verified by** those two test classes, and by `python .agents/manage-config.py --check`, which reads
the real record and is unaffected — the sample is not the record.

### Step 3.2 — F09: two files say `php` is absent from `PATH`; the record says it is there

`AGENTS.md` §2 and `.agents/README.md` both assert `php` is not on this shell's `PATH`, and both
explain a failure by it. The record says `onPath=true`, and the handoff's PHP row repeats the record.

**Changed:** both files, one edit each. The instruction that survives is the one that was ever sound
— *take the interpreter from the record* — with the availability claim replaced by: whether a bare
`php` resolves is this machine's own fact and the record states it, and the record's interpreter is
the one the gate and the tools were measured with either way.

**Verified by** `php bin/tool.php phpunit tests/Unit/Docs/HandoffEnvironmentTest.php` (the guard
that reads the handoff's environment claims against the record) and
`php bin/handoff.php --check --recorded`.

### Step 3.3 — F12: discovery and maintenance text that stopped following the setup

| File | Claim today | Change |
|---|---|---|
| `AGENTS.md` header | "This repository has no `.skills/` sources", and no generator or `verify.py` either | qualify it: no `.skills/` sources and no `verify.py`, and the one generator is the harness configuration renderer in `.agents/`, which writes no rule |
| `.agents/README.md` | counted two files beside the skills, and called both of them records | name all four: `gate.json` and `machine.local.json` are records, `sample.machine.local.json` is the copyable form of the second, and `manage-config.py` is the renderer |
| both templates, the skill-target comment | "see `.skills/README.md`" | `see .agents/README.md` |
| `HANDOFF.md`'s maintenance sentence | names the commit helper as the only Python exception | add the config renderer beside it, which is what `HandoffEnvironmentTest` already allows |

**Verified by** `php bin/tool.php phpunit tests/Unit/Docs/HandoffEnvironmentTest.php`,
`php bin/tool.php phpunit tests/Unit/Docs/SkillDescriptionTest.php`, and
`php bin/checks.php --only=counts,sentences` — the last because the README and the handoff are
counted and sentence-checked records.

## Batch 4 — the guards' corpus (F14)

`AGENTS.md` §3 says `README.md`, `RELEASING.md` and **every record under `docs/`** are read by the
guards. `DocCitationsTest::records()` and `bin/checks.php`'s `recordFiles()` both glob `docs/*.md`,
so the 19 records in `docs/issues/`, `docs/investigations/`, `docs/planning/` and `docs/reports/`
are outside the citation check and outside the sentence check. A green gate therefore does not
establish what §3 says it establishes.

**Recommendation: narrow the sentence (A).** It is a documentation repair with no red gate
attached, and it is true the day it lands.

| Option | Change | Cost |
|---|---|---|
| **A — narrow §3** (recommended) | name the corpus exactly: the two documents at the root and the Markdown files **directly inside** `docs/`; add that the folders under `docs/` are held by the other guards — the repository-boundary walk, the machine-path reading and the handoff register | the nested records stay outside the citation and sentence checks, and the rule stops claiming otherwise |
| B — make both readers recursive | the same globs become a walk of `docs/`, with an intentional exclusion list | the gate becomes an audit of 19 historical records written under different conventions; the first run is red on citations and sentences in them, so it lands as a repair sweep of its own, with `bin/counts.php` re-derived |

**Verified by** (A): `php bin/checks.php --only=counts,sentences` and
`php bin/tool.php phpunit --filter 'DocCitationsTest|ProseNumbersTest'`, plus a read of §3 against
the two readers' code. (B), if chosen: the same, expecting the sweep first.

## Batch 5 — the handoff snapshot, and the copy nothing loads (F15, F13)

### Step 5.1 — F15: the remote row is a snapshot the remote has left behind

`HANDOFF.md` §2 records `origin/dev` as `a99c22b` with nine commits above it at `HEAD 365f300`. The
remote answers `d9e8db5` and `HEAD` is `cfad0ce`, so
`HandoffTest::test_the_origin_row_still_names_the_tip_the_remote_answers` fails: the tip is neither
what the row names nor its parent, which is the one allowance the test makes for a release commit.

This is a **re-take, not an edit**, and the order matters:

1. decide the unpushed history first. The note asserts those commits are on no remote; the repair is
   either to push them — publishing, so the user's call, not this plan's — or to leave them and let
   §2 say so against the real tip;
2. re-take §1–§3 from the refs as they then stand, on top of whatever the working tree holds rather
   than on the committed copy, because `HANDOFF.md` already carries uncommitted changes;
3. re-render and check: `php bin/handoff.php --recorded`, then `php bin/handoff.php --check --recorded`
   (exit 0), then the origin-row test alone.

**Verified by** `php bin/tool.php phpunit tests/Unit/Docs/HandoffTest.php --filter
test_the_origin_row_still_names_the_tip_the_remote_answers`, and by the note's own three commands at
its head still answering what it says.

### Step 5.2 — F13: an ignored rules copy names skills that no longer exist

`AGENTS.md.txt` is present, ignored, differs from `AGENTS.md`, lacks the newer load order and names
`pkg-commit`, `pkg-push` and `pkg-deploy` instead of the `wdm-…` skills. It is loaded by nothing,
so this is a local maintenance hazard: a reader who imports it is sent to skills that are gone.

**Recommendation: label it, do not delete it** — one line at the top of the file saying it is
obsolete, superseded by `AGENTS.md`, loaded by no configuration, and kept only as a local
reference. Nothing is destroyed, and the hazard is the thing removed. Deleting it is the
alternative if the user prefers; `git` history holds `AGENTS.md`, so nothing is lost either way.

**Verified by** nothing beyond the file itself, and that is stated rather than glossed: it is
ignored, so no guard reads it, and this step is the only one in the plan with no check behind it.

## The order, and what each step is held to

| # | Step | Findings | Held to |
|---|---|---|---|
| 1 | the missing agent body | F01 | `python .agents/manage-config.py --check`; `grep -c php-code-analyzer opencode.jsonc` |
| 2 | the renderer's escaping | F02 | the in-memory probe, `json.loads` succeeding |
| 3 | the permission order | F03 | the rendered order, read with `grep -n '"effect"' -B2` |
| 4 | the missing-record exit code | F08 | the in-memory probe, `exit 2` |
| 5 | the six skill links | F04 | the link sweep printing nothing |
| 6 | `wdm-plan`'s missing step | F06 | `grep -c reaffirm .agents/skills/*/SKILL.md` |
| 7 | the branch in the publish commands | F07 | the commands as written against `git rev-parse --abbrev-ref HEAD`; nothing pushed |
| 8 | activation precedence | F10 | `php bin/tool.php phpunit tests/Unit/Docs/SkillDescriptionTest.php` |
| 9 | repository-relative paths | F11 | `php bin/tool.php phpunit tests/Unit/Support/RepoEscapesTest.php tests/Unit/Support/MachinePathsTest.php` |
| 10 | the sample record | F05 | `php bin/tool.php phpunit tests/Unit/Support/MachinePathsTest.php tests/Unit/Docs/SkillDescriptionTest.php` |
| 11 | the `PATH` claim | F09 | `php bin/tool.php phpunit tests/Unit/Docs/HandoffEnvironmentTest.php` |
| 12 | discovery and maintenance text | F12 | the same guard, `SkillDescriptionTest`, `php bin/checks.php --only=counts,sentences` |
| 13 | §3's corpus | F14 | `php bin/checks.php --only=counts,sentences`, the citation filter |
| 14 | the handoff re-take | F15 | the origin-row test, `php bin/handoff.php --check --recorded` |
| 15 | the ignored copy | F13 | nothing — stated |
| 16 | the notes, and the whole gate | all | `php bin/release.php --weigh --dry-run --branch=dev`, then `php bin/checks.php` |

Steps 1–4 are one file family and land together, because a template edit and a renderer edit are
verified by the same `--check`. Steps 5–9 are the skills and land together for the same reason: one
sweep resolves every link and one guard reads every path. Steps 10–13 are the records. Then the
handoff, then the notes.

`php bin/checks.php` is the last thing, once, because the report measured it at 1110.8 seconds on
this machine; each step's own test is what is run while the step is being written.

## Risks

| Risk | What it looks like | What the plan does |
|---|---|---|
| dropping the default agent changes how a session starts | a harness that wants one falls back to its own agent or refuses the file | the instruction files still load, so the rules travel; the failure is on the first run and is visible |
| a reordered permission table asks more, not less | the wildcard ends up last again, or a narrow rule is shadowed | the order is read back from the rendered file, and the rule is written in the comment beside it |
| tokenising the sample makes a copy fail | a copied record whose `php.exe` is `__PHP_EXE__` fails to resolve | that is the point: a stale version answers wrongly forever, a token answers with an error; `bin/interpreter.php` already skips a record that is absent |
| the sample guard grows a dependency | `SkillDescriptionTest` reads a file out of `.agents/`, which `.gitattributes` keeps out of a consumer's tarball | the sample is committed and in the listing the guards already read; the test skips nothing and fails by name if the file is gone |
| a branch derived from `HEAD` in CI | `HEAD` in a detached checkout | the skill says to name the branch explicitly when detached |
| narrowing §3 weakens a guard | the nested records stay outside it | written down as the decision, with option B named and its cost measured, rather than left implicit |
| the handoff re-take collides with uncommitted changes | `HANDOFF.md` and `tests/Support/Handoff.php` are already modified in this tree | the re-take is done on top of what is there, and the register is re-rendered rather than hand-edited |
| the notes and the bump disagree | a `### Changed` entry naming a surface change that did not happen | `src/` and `config/` are untouched, so weighing is a patch; the dry run is the check |

## Decisions this plan needs

1. **F01 — drop the agent registration (a, recommended) or author its body here (b)?** It is the
   only finding whose repair changes what a session starts as.
2. **F14 — narrow §3 (A, recommended) or make both readers recursive (B)?** B is a repair sweep of
   19 records, not a sentence edit.
3. **F15 — push the unpushed history, or restate §2 against the real tip?** The first publishes.
4. **F13 — label `AGENTS.md.txt` (recommended) or delete it?**

Each is answered independently; none blocks the other three, and the batches are ordered so that
the first can be taken without the rest.

## Deliberately not in this plan

- **The second review's findings.** `docs/reports/0002-2026-10-31_rpt-config-src-tests-bug-review.md`
  is about replica health, Redis recovery and the health endpoint. Different subject, different
  files, no shared step.
- **Making the citation and sentence guards recursive** unless F14's option B is chosen. It turns
  the gate into an audit of historical records and belongs in its own change, after the red is
  measured rather than guessed at.
- **A PHPUnit guard for the Python renderer.** It would make the PHP suite depend on an interpreter
  the machine record does not carry, which is the class of dependency Batch 3 is removing.
- **Authoring `php-code-analyzer`** under F01(b) unless that option is chosen.
- **Rewriting the backup template's V1 shape.** Only its two defects move: the fallback ordering,
  and the stale index link.
- **Renaming the document skills or their markers.** F10 is about where the precedence is written,
  not about what it says.
- **Anything outside this repository**, including the application repository the missing agent came
  from: it is somebody else's tree, and `AGENTS.md` §0 is explicit about that.

## Keeping the repository green

This document is a new file under `docs/`, so the register has to carry it:

```bash
php bin/handoff.php --reaffirm=docs/planning/0004-2026-11-01_plan-agentic-setup-findings.md
php bin/handoff.php --recorded
```

with a `words` line added to `Handoff::PENDING_DESCRIPTIONS` in `tests/Support/Handoff.php` first —
the block `--reaffirm` rewrites — and `php` being the interpreter from `.agents/machine.local.json`,
not a bare one. Then:

```bash
php bin/tool.php phpunit tests/Unit/Docs/HandoffTest.php
php bin/tool.php pint --test
git diff --check
```

The pending block is rendered from the files this checkout has and the register does not carry, so
a document with no line is refused by name rather than rendered blank. Nothing is committed unless
it is asked for: the document is the deliverable.

**One consequence worth stating:** re-rendering the pending block is not the §2 repair. F15's row is
about the remote, and re-rendering leaves it exactly as stale as it was.
