# Handoff — the state of this package, taken 2026-10-11

This is a snapshot for whoever opens a thread in **this** repository next: what is written
but uncommitted, what is committed but unpushed, what is not in any release a consumer can
install, and the facts a fresh thread would otherwise spend its first ten minutes
re-deriving.

`tests/Unit/Docs/HandoffTest.php` holds this file to the tree, reading it through
`tests/Support/Handoff.php`. The register it reads in is the commit section 2 names as `HEAD`:
every number below is a claim about *that* commit rather than about whatever the branch is
doing when the suite runs. So the test does not mind that the branch has moved on — it fails
when what the note says has been **contradicted**: a path it names is gone, the commit or the
tag it names is gone or reads differently, the tag section 3 calls the latest has been
superseded, or a file it describes disagrees with it. What the test cannot ask is listed at the
end of section 5, and a **release** is the one event that has to re-take this file before the
tag is cut, because section 3's table is a claim about now.

Re-read the three commands it was built from before acting on it:

```bash
# from the package root
git status --short                                  # what is uncommitted, and how it is staged
git log --oneline origin/dev..HEAD                  # what is unpushed
php bin/release.php --weigh --branch=dev --dry-run  # what a release would do right now
```

Every number below that the register holds — sections 2 and 3 — was read off this machine on
2026-10-11, with the remote asked directly (`git ls-remote`, which named the same commit the local
ref does) rather than trusted from the local `origin/dev` ref. Section 1 was re-taken on the same day,
the date above, and it is the one part of this file that describes *now* rather than the commit it is
read against.

---

## 1. Pending — written, green, uncommitted

Nothing is pending. The batches this section described when the note before this one was taken are
commits now, and they are the whole of what it described: the writers declaration, which names in one
place which files a release derives from the record and which command writes each; the pgcat
rehearsal's own reading, which holds `db:pgcat-flip --dry-run` to its no-write promise against the
installation rather than against its own report; the release script's own exit paths, which are held
to that promise in the same way — every refusal, the three exits a wrong argument takes, the
declared-version dry runs and the `--skip-ci` paths — through a snapshot of the repository taken on
both sides of the run; this note's own renderer, which took the pending set, the count and the listing
out of a hand's reach and reads them off git; how much is pending, which this note no longer counts;
and the two forms every count the guard reads has to accept, which the guard now holds. All of them
were green where they landed — the batches' own tests, the suites that carry them, and the gate's
`pint`, `phpstan`, `sentences` and `counts` rows, the `phpstan` one over a `src/` none of them
touches; the gate's `tests` row runs the whole suite in one command and is not claimed here — and the
note rode with the last of them, because section 1 describes a working tree and the only state it can
be true in is the one after everything else has landed. One guard change rode with the note rather
than with a batch: `Handoff::pending()` used to refuse a section whose table had no rows, and a tree
with nothing pending is exactly the state a section that says so has to be able to describe — the test
that holds the paths no commit carries against the paths the section names still fails a file nobody
names, so an empty table cannot hide one.

The writers declaration. The list that names the derived files (`derivedFiles()` in
`bin/surface.php`) now also names, row by row, the commands that write each — `written_by`, with
`derivedWriters()` and `derivedBy()` as its other half — and the three commands reach their files
through their own rows rather than through the whole list: `bin/release.php` writes every row that
names it, `bin/inventory.php` the record and the page rendered from it, `bin/api.php` the page alone
and refuses a page whose row does not name it. `tests/Unit/Release/DerivedWritersTest.php` holds
that declaration to the commands by measurement: it forges a row into each of the three records and
a marker into the page, runs each command, and asks every file whether it was repaired. The prose
the derived-file list had already made stale travelled with it — `README.md`'s layout rows,
`RELEASING.md`'s page and hand-regeneration sections, with the writers table as the readable form of
the declaration, `docs/inventory-surface-rows.md`'s file table, and the usage text and docblocks of
`bin/inventory.php` and `bin/api.php`.

The pgcat rehearsal's reading. `db:pgcat-flip --dry-run` was held to its no-write promise only
through the steps it reports, so `tests/Unit/Console/DbFlipPgcatDryRunTest.php` reads the
installation itself — every file under it by the hash of its bytes, and the lock beside them — on
both sides of one rehearsal, and carries the control the promise needs in both directions: the same
reading sees a real flip replace `pgcat.toml`, record the mode and create the lock, and a rehearsal
mutated to rename its probe over the target instead of removing it fails both promise tests with the
target's hash in the diff. `docs/pgcat-flip-dry-run.md` names it and its control.

The release script's exit paths. `tests/Unit/Release/DryRunTest.php` held one exit — the plan — to its
no-write promise, by reading the repository on both sides of one run; that reading is now
`tests/Support/RepositorySnapshot.php`, so the two guards cannot disagree about what *nothing was
written* means. It reads the whole of what a state has rather than three things: every file under the
fixture's root by the hash of its bytes and beside that by size and mtime — a directory walk, so a file
a run leaves behind is in the reading by construction and a directory that is not a repository still
has one — and, where there is a `.git`, HEAD, the branch under it, every ref, the index, the porcelain
and the reflog, and nothing inside `.git` beyond those, because git rewrites its index's stat cache on
`git status` and a run that only *asked* about the tree would otherwise look like one that wrote to it.
`tests/Unit/Release/ExitPathsTest.php` applies it to every exit of `bin/release.php` that is not the
apply: the thirteen refusals, the three usage errors, the three exits a wrong argument takes, the
question a non-interactive shell does not answer, and the seven dry runs — weighed, declared as
`--minor`, `--major` and `--version=X.Y.Z`, with the CI question skipped, on an empty Unreleased
section, and on a commit CI has not verified. Each row asserts the exit it took and a sentence the path
is known by, because a mistyped fixture would otherwise keep the tree still for the wrong reason, and
the rows whose path shows up in the plan rather than in a sentence assert the plan's own line — the
`ci` line that says `--skip-ci`, and the version a run had worked out before it asked.

The table is held to the script rather than to itself, because the way a table like this rots is not a
row that stops matching but an exit nothing drives. So the exits are read out of `bin/release.php` by
the shape of the call — `fail`, `usageError`, and `exit` with its code, before the apply — and the
counts have to agree, which is how the one refusal behind the parse gate is accounted for rather than
assumed: `--version` is validated where it is parsed, and the release path re-applies the same rule to
the same string, so no argument reaches the second reading. The census measures that as the refusal
whose sentence a usage error already prints, and the row for `--version=1.2.3-alpha.1` is the proof of
it, since it exits at the parse site with the same words. A second such guard would show up as a
missing row rather than as a green suite.

The note's own renderer. Section 1's table and section 2's table and listing are derived rather than
transcribed: `bin/handoff.php` renders all three from git — the rows are the files this checkout has
that the register does not carry, the count and the breakdown are the commits between the register and
`origin/dev`, and the listing is the most recent of those — through the same readings the note's guard
takes in `tests/Support/Handoff.php`, and `bin/handoff.php --check` compares what the file holds with what
the derivation says at the register the note names rather than at `HEAD`, so a note describing an older
commit is not reported stale by every commit made after it. Its three blocks are delimited in this file
by `<!-- handoff:NAME -->` markers, which is what lets the renderer replace the bytes between them and
not one byte outside them. The one input git cannot answer is the prose column of section 1's table,
and that is declared beside the renderer: a file the register does not carry and that has no line
declared for it is refused by name, which is the same sentence the guard gives — name the file, or take
it out of the tree. `tests/Unit/Docs/HandoffTest.php` holds both halves, every block against its
rendering and the command against the same answer.

How much is pending is no longer counted in this note. Section 1 stated it as three numbers — how
many paths differ from the register, how many of those are tracked paths that changed, and how many
are files no commit carries — and `tests/Unit/Docs/HandoffTest.php` compared them with `git status`,
so the sentence was red on every checkout but the one that wrote the note: a count of uncommitted
paths is a fact about one working tree, and any file added, deleted or reverted afterwards left it
disagreeing with the tree. The sentence now names the derivation — `git status --short`, the command
the block at the top of this file runs — and the guard holds it to that: it has to name the command,
and a number word standing in front of a `path` or a `file` fails it, because `NumberWords` is what
tells a number from a word in a record. The reading moved with it. `tests/Support/Handoff.php` carries
`pendingSize()` where `pendingCounts()` was, `uncommitted()` gone because the claim it served is no
longer stated; `tests/Support/NumberWords.php` keeps its `zero`, now because the count refusal reads a
word through that table rather than because a note might state one; and `docs/prose-numbers.md` gains
the decision to its list of numbers the guard deliberately does not bind, with the reason a rendered
count cannot stand in for it either — a number rendered from `git status` would be wrong on the first
clean clone. The guard's teeth were
measured by control rather than argued, and section 5 records it: the counts written back into the
sentence were reported as *twelve paths, nine paths, three files*.

The two forms every count reading must accept. Every count the note's guard reads is a pattern around
a noun, and a noun a reading counts has a singular for the count of one and a plural for the rest, so
a pattern spelling the plural alone reads no note holding exactly one of the thing while staying
green against every note this package has. The nouns are therefore declared once, as pairs, in the
reader that counts them — `entry`, `path` and `file` — and a reading builds its pattern from the
declaration rather than from one of the forms. `tests/Unit/Docs/HandoffTest.php` holds every pair to
both forms, and reads the guard's own source for the two shapes a hand-written noun takes: a form
written into a pattern, and a pair written out by hand beside the table. The controls are measured
rather than argued, and section 5 records them: a refusal spelling `paths` and no `path`, and an
Unreleased row spelling `entries` and no `entry`, are each refused by name at their line. The scan's
own two directions are a test rather than a paragraph — the shapes it has to refuse and the lines it
has to leave alone are composed by the suite from the declaration's own forms — so the teeth are
re-measured on every run instead of by the hand that wrote them.

The register every number in section 2 is read at is `378d39b`, the commit that landed the last of the
five batches this cycle added, and the table below is rendered at it: it is empty, which is the state
it describes rather than a table that failed to render — a file this checkout carries that the
register does not is a file no commit carries, and this tree has none. The five batches that were
pending when the note before this one was taken — and the reading that lets a note be taken at all —
are commits too, and their re-take is `38e9a9f`. They are the pgcat *direction*, the
end of the last reader window a stale pooler is at, with the records and the changelog entry that
describe it; the release script, which renders the published API page from the record it writes;
`AGENTS.md`, which now sends a session to `.agents/machine.local.json` for the interpreter every
command here starts with; the plan's dirty-tree note, which no longer leaves a reader to go and ask
git *which* files are standing in the way — the porcelain a real run refuses with is the porcelain
printed under the note, columns and all, so the plan and the refusal it rehearses name one set
rather than two; and the gate's `interpreter` check, which answers the PHP row below against the
file the machine record names, so an interpreter that has moved, that reports another version, or
that is below the requirement the record carries is red in the gate rather than a hunt in the next
session's first command. That check's other half is a commit of its own: `bin/interpreter.php` and
`tests/Unit/Support/InterpreterResolverTest.php`, which make every command hand its process to the
interpreter the record names — `bin/tool.php`'s call to it travelled with them — and which refuse the
hand-over to a file that starts and cannot read the script it would be given, so the check's verdict
and the commands' fallback agree about which interpreter is this machine's.

The pgcat answerability batch this note described — the pool probe, the `repaired` kind it reports,
the two surfaces that read it, the repair counters `db:pgcat-flip --status` prints, and the records
and changelog entry that describe all of it — is a commit now, `0a880cb`, its own commit like the
five before it. Three more landed with it, all of them described below: the audit record handed back
writable to whoever boots next (`c6b5d0c`, which is the fix the other thread's half-applied change
was reaching for, with its own `### Fixed` entry in the changelog), the release scripts' derived-file
list (`bab7c4e`), and the `.gitignore` line another hand added after this note was taken (`aa5f695`,
which is a rule for an editor's own project file and nothing this package installs). The rule file
another hand left at the root while this note was being re-taken — `BEHAVE.mdc` — was its reader's
call, and this cycle's reader made it: the guidelines commit above carries the file, with its row in
`AGENTS.md`'s authority table and load order and the `.gitattributes` line that holds it out of a
consumer's tarball. Nothing is left in the tree that no commit carries, which is the state the table
below is read in.

The size of the pending set is read off the tree rather than stated here: `git status --short` prints
the whole of it against the commit section 2 names as `HEAD` — the tracked paths that changed and the
files no commit carries — because a count of uncommitted paths is a fact about one working tree, and
a committed file cannot be asked for it.

The table under it is **rendered rather than written**, as are section 2's table and the listing under
it. Its rows are the files this checkout has that the register does not carry (`git ls-files --cached
--others --exclude-standard` minus `git ls-tree -r` of the register), one row apiece, with the prose
column declared in `tests/Support/Handoff.php` beside the renderer — the one input git cannot answer.
It has two rows and nothing between them, which is what nothing pending looks like; a row in it is the
register saying it does not carry that file. `php bin/handoff.php` writes all three blocks from git,
and the note's own guard renders the same three and asserts the file says what they say, so a re-take
cannot state a set of files, a count or a listing the tree does not have. A file the register does not
carry and that has no line declared for it is refused by name rather than rendered blank, which is the
same sentence the guard gives: name it, or take it out of the tree.

<!-- handoff:pending -->
| File | What it is |
|---|---|
<!-- /handoff:pending -->

The pgcat sources that batch changed — `src/Pgcat/FlipResult.php`, `src/Pgcat/PgcatConfigFlipper.php`,
`src/Console/Commands/DbFlipPgcatCommand.php` and `config/db-manager.php` — are one piece of work with
`PoolProbe.php` and its test above: a running pooler that cannot answer for a pool is a fault the flip
now repairs and reports as its own `repaired` kind, and the regenerated records under `docs/`, `API.md`
and the three `.tsv` inventories are that work's records, with its design record
`docs/pgcat-pool-probe.md` new beside them. The same work now gives that reading a reader:
`src/Http/Controllers/DatabaseHealthController.php` reports it as `/health/db`'s `pool_probe` block and
`src/Console/Commands/DbDoctor.php` as a fourteenth row, `pool probe`, both asking
`PgcatConfigFlipper::poolProbeVerdict()` by name so the two surfaces cannot disagree about it — with
their tests (`tests/Unit/Http/DatabaseHealthControllerTest.php`, `tests/Unit/Console/DbDoctorTest.php`)
and the prose they are held to (`README.md`, `CHANGELOG.md`, `docs/db-doctor-json.md`,
`docs/prose-numbers.md`) travelling with them. `src/Support/BootAudit.php` and
`src/Providers/WeightedDatabaseServiceProvider.php` began as a *different* thread's half-applied
change — the audit record handed back able to be written by whoever boots next, carried on the second
argument the releaser now takes — and this checkout finished it rather than leaving the tree red: that
finish is `c6b5d0c`, the second of the three commits the fence below lists. The red it was leaving
behind is gone, measured rather than asserted:
`phpstan` reports no error over `src/`, because the property and its `@param` are typed
`(mixed, string): void`, which is the argument the call site at the write already passes; `pint`
reports no style issue over 129 files, because the `$sysTempDir` the reading hoisted is indented as an
argument rather than a level further in; and `tests/Unit/Support/BootAuditConcurrencyTest.php` is green,
because the child half (`tests/Support/boot-audit-child.php`) forwards the record to the shipped
releaser instead of calling it with the one argument it used to take. The repair itself moved with the
signature: `@chmod($filePath, 0666)` in the releaser is now `BootAudit::makeRecordWritable()`, which is
one call on both hosts rather than a branch — on Windows `chmod()` is the read-only attribute and all
that host has of a mode, and a read-only record is exactly what the acquirer's `fopen(…, 'c')` refuses —
and which hands nothing back for a path the write removed. It is now covered rather than merely
claimed: `BootAuditTest::test_the_release_hands_the_record_back_writable_to_the_next_boot` takes the
shipped closure off a plain instance the way the concurrency child does, makes the record read-only,
and holds the repair to a hand-back — which fails, measured, with the `chmod` disabled (the control edit
was restored byte for byte), and which reports the mode as `0666` on a host where a mode is a permission.
The release is no longer blocked by these files, and the sequence above is what a release still needs.

The pool probe has since been measured against the running pooler rather than argued about, and the
measurement changed two things above. A pool's name is the *first* segment of a header path: the
deployed `/etc/pgcat/pgcat.toml` declares its one pool with no `[pools.writer_db_1]` line at all —
only `[pools.writer_db_1.users.0]` and `[pools.writer_db_1.shards.0]` — so
`PoolProbe::declaredPools()` answers `writer_db_1` where it used to answer two sub-table paths that no
client ever asks for, and `PoolProbeTest` pins that from the captured header shape. The probe's
default port is pgcat's own rather than PostgreSQL's `5432`, because a PostgreSQL server answers
*every* name in its own words and would be read as a pooler holding them all. What the same
measurement could not repair is now written beside the code it constrains: that pooler answers an
AuthenticationRequest to *every* name it is given — declared, undeclared, commented out, or invented
— so `missing` is never observed there, and `answered` says the pooler is there rather than that the
pool is.

The flip itself gained the read the earlier half of this self-heal never made: a run that *applies* a
mode now asks the pooler about each pool the variant it just applied declares, with the same bounded
attempts and delay a repair's starts use (`start_attempts`, `start_retry_delay_ms`), because both
ways of applying a config return before pgcat has re-read one — a reload is a signal, and a restart
returns inside supervisor's `startsecs` window. A refusal that survives those attempts is a signal
pgcat did not act on, so the repair escalates to `restart_command`, judged with the same
`SupervisorStep::inspect()` a flip judges its own command with, and the pools are read back once more.
The run reports `repaired` with the transition in its sentence rather than a clean `flipped`, which is
what `FlipResult::KIND_REPAIRED` and its factory now say: a repair can follow a transition as well as
an unchanged mode, and the state behind it is `/health/db`'s to report. An installation with
`use_reload = false` is told its flip already restarted the pooler rather than having it restarted
twice, a pooler that answered nothing is still never repaired, and `db:pgcat-flip --dry-run` reports
the read as a step it cannot perform, because the pools it would ask about are the ones nothing has
renamed in yet. Six tests in `tests/Unit/Pgcat/PgcatConfigFlipperTest.php` hold the new path — the
answered flip, a pooler still catching up, the restart that repairs it, the refusal that survives the
restart, the installation that has already restarted, and the silent one — and the rehearsal's step
list moved with it in `tests/Unit/Console/DbFlipPgcatCommandTest.php`. `forceMode()` deliberately did
not move: the window tasks apply through it, it has no boot window and records no run, so a read
added there would be a socket on the boot path with nothing to report it to.

That read now leaves a record of its own. Each pool repair is counted in the state file —
`pool_repairs`, with `last_pool_repair_at` beside it, written where the run is so both repair paths
move it — and `db:pgcat-flip --status` prints the pair as a `pool_repairs` row beside `flip_runs`:
the count is what a run's kind cannot carry, since a pooler repaired twice reads the same `repaired`
as one repaired once. It travels in the window block, so `--status --json` and `/health/db` read the
same two facts, and a container that has never repaired anything reads zero rather than nothing.
`tests/Unit/Pgcat/PgcatConfigFlipperTest.php` holds the recording and the arithmetic, and
`tests/Unit/Console/DbFlipPgcatCommandTest.php` the row and the JSON.

The last batch this section had no words for is the release scripts' derived-file list, `bab7c4e`.
`bin/surface.php` now names every file the package derives — the inventory record's three, and
`API.md` — with the command that renders each, and `bin/inventory.php`, the API renderer in
`bin/api.php` and `bin/release.php` all read that list rather than naming paths of their own, so a
run of `bin/inventory.php` refreshes the page beside the record and a release commits the derived
files it wrote and no others. It is not the batch above: it changes where the paths are named, not
what any of them says — the rows the record and the page carry are the ones that batch produced, and
both `--check` runs still report the record and the page current.

One thing the batch landed without, and it is this paragraph's to say: `docs/pgcat-flip-dry-run.md`'s
step table still lists the steps without `pool read`, so that record is out of step with the report a
rehearsal prints and the row is owed. The ceiling that deferred it is gone — the number vocabulary is
back to what it was — but the row is not the whole of what that record needs: its section states a
rule (a rehearsal performs every step whose effect can be undone, and reports the ones that cannot),
and `pool read` is reported for a reason that rule does not cover — there is nothing for the read to
be about until the rename it follows has happened. So the row and the rule move together, which is a
change to that record's argument rather than an addition to its table, and it is left for whoever
takes that record next.

The five commits this section's batches became, in the order they landed — and a sixth, which a fence
under it cannot name, carrying this note and the one guard change above it:

```
docs: a session reads the behavioral guidelines before the readme                    73c278d
refactor: the writers of the derived files are declared in the list that names them  2bda57b
test: the pgcat rehearsal is held to the installation, not to its own report         7ff9197
test: every exit of the release script that is not the apply is held to writing nothing aa0d647
refactor: the handoff note's pending set and listing are rendered from git           378d39b
```

**The batches were commits of their own rather than one**, because they are unrelated work that happens
to share a checkout — and the note rides with the last of them rather than the first, because section 1
describes a working tree rather than a commit, and the only state it can be true in is the one after
everything else has landed. The package has no `core.hooksPath` (so stock `.git/hooks`, and no
gate of this repository's own runs on commit), `commit.gpgsign=true` with
`user.signingkey=9CE840D871DE7A62`, and the message shape `bin/weighing.php` reads back is the one
`@pkg-commit` documents.

### Also pending: the records carry the stamp of the last release

The three `.tsv` records describe the tree `v0.2.0-alpha2` was cut from, and they say so in their
header line. At the commit section 2 names — which is not that release — `php bin/inventory.php
--check --at=<HEAD>` exits 1 and names all three, one stale line each: the stamp. That is the
check reading the stamp rather than the rows. Against this checkout, the same command without
`--at` reports the inventory current:

```
php bin/inventory.php --check --at=f92abd2
  --> exit 1: files.tsv, methods.tsv and surface.tsv, 1 line(s) stale each — the stamp

php bin/inventory.php --check
  --> • inventory is current — 40 files, 204 public methods, 239 key(s)/member(s),
      described as v0.2.0-alpha2
```

The split is the intended state rather than a finding: those three files describe the tree **at a
tag**, the next release rewrites them and commits them, and the check's own message offers
exactly those two answers (*"Run php bin/inventory.php to write them and commit them, or leave
them alone and let the next release refresh them"*). Do not "fix" them by hand as a favour — a
record written at some other moment describes a tree no tag points at, which is the thing the
fourth weighing signal reads.

---

## 2. Unpushed — the commits on `dev` no remote has

<!-- handoff:unpushed -->
| Field | At the snapshot |
|---|---|
| Branch | `dev` |
| HEAD | `378d39b` — *refactor: the handoff note's pending set and listing are rendered from git* |
| `origin/dev` | `f92abd2` — *test: the pending list in the handoff note is held in both directions* |
| Unpushed | 19 commits — the ones `git log --oneline origin/dev..HEAD` prints |
| Since the last tag | chore 1, docs 5, feat 4, fix 3, refactor 3, test 3 |
<!-- /handoff:unpushed -->

The `HEAD` row is the basis every number in this file is read against, and the table above it is
rendered: the register and the remote tip are commits, and the count and the breakdown are what
`git log --oneline origin/dev..HEAD` says between them. The tip of `origin/dev` was asked of the
remote directly (`git ls-remote`, not the local ref) when this section was taken, which named the
same commit the local ref does.

<!-- handoff:log -->
The ten most recent are the ones a reader is most likely to be standing in the middle of:

```
378d39b  refactor: the handoff note's pending set and listing are rendered from git
aa0d647  test: every exit of the release script that is not the apply is held to writing nothing
7ff9197  test: the pgcat rehearsal is held to the installation, not to its own report
2bda57b  refactor: the writers of the derived files are declared in the list that names them
73c278d  docs: a session reads the behavioral guidelines before the readme
38e9a9f  docs: the handoff note is re-taken at the three batches it described
aa5f695  chore: the editor's project file is ignored by the repository
bab7c4e  refactor: every derived file is named once, in the list that writes it
c6b5d0c  fix: the audit record is handed back writable, whoever boots next
0a880cb  feat: a pooler that cannot answer for a pool is a fault a flip repairs
```
<!-- /handoff:log -->

The fence is a ceiling rather than the whole range, which the count above says in its own words: the
register is nineteen commits ahead of the remote and the ten most recent are the ones printed, so the
nine the fence does not reach are the tail of the pgcat cycle — the commits its own paragraphs below
name by id, from `0a880cb` back to the rule at the root. `git log --oneline origin/dev..HEAD` is the
whole of it.

Everything on `dev` is ahead of the remote, and that has one consequence worth naming: it is
the **interlock on the next release**. `bin/release.php` refuses to tag while HEAD is not the
tip of `origin/dev` — the plan in section 3 says so in its `ci` line — so either the branch goes
up first or the release is declared with `--skip-ci`. It is not an error to be worked around: it
is the check that the thing being tagged is the thing a consumer can fetch.

The push itself goes through PowerShell 7 and nothing else; `PUSHING.md` is the runbook, and
`@pkg-push` wraps it:

```powershell
# from the package root
git push origin dev --follow-tags
```

---

## 3. Unreleased — built, tested, and in nothing a consumer can install

| Field | At the snapshot |
|---|---|
| Tags on the branch | `v0.0.1-alpha1`, `v0.1.0-alpha1`, `v0.1.0-alpha3`, `v0.2.0-alpha1`, `v0.2.0-alpha2` |
| The latest of them | `v0.2.0-alpha2` |
| A version with no suffix | none — `v0.2.0` does not exist |
| `## Unreleased` at the basis | 12 entries — 6 added, 1 fixed, 5 changed |
| The notes weigh as | minor — the six `### Added` entries are the loudest heading the notes use |

The absent version is the exact sentence that decides whether the next one may be a prerelease,
and it may. The headings the weighing reads are the `### Added`, `### Fixed` and `### Changed` a
Keep a Changelog section writes; the section carries three of them — six entries under `### Added`,
five under `### Changed` and one under `### Fixed` — so `### Added` is the loudest heading it has and
the severity is the one the weighing gives that. The marker is the one a note claims rather than the word alone, which is why the last release
could be declared breaking from a section that carries four headings and no `Removed` one.

### The release the weighing would ask for, and the line that refuses it

`v0.2.0-alpha2` was cut and pushed on 2026-10-06. The entries under `## Unreleased` are twelve at the
register — six under `### Added`, five under `### Changed` and one under `### Fixed`, the seven the
pgcat cycle left and the five this cycle's batches added — and thirteen in the working tree, the sixth
`### Changed` entry being the one that travels with this note. So the table above is the register's own
count, the severity does not move (`### Added` staying the loudest heading either way), and the tree the
weighing reads is one entry ahead of it. Asked on 2026-10-11 what a release would do, `exit 0`:

```bash
php bin/release.php --weigh --branch=dev --dry-run
```

```
  branch        dev  (dirty)
  ci            HEAD is not the tip of origin/dev
  latest tag    v0.2.0-alpha2
  bump          minor  (weighed: a minor change)
  next version  0.2.0  (tag v0.2.0)
  CHANGELOG     ## Unreleased -> ## 0.2.0 - 2026-10-11, new Unreleased section above
  release notes 13 bullet(s) in the promoted section
  inventory     40 file(s), 204 method(s), 239 key(s)/member(s)  (fresh, weighed against v0.2.0-alpha2)
  api page      38 class(es), 204 public method(s), described as v0.2.0
  branch-alias  dev-dev, dev-main -> 0.2.x-dev  (unchanged)
  commit        Release v0.2.0
  tag           git tag -a v0.2.0 -m "v0.2.0"
  push          git push origin dev --follow-tags
```

One line is unsatisfied, and it is the check working rather than an obstacle: `ci` (section 2 is
unpushed, so the commit being released is not one a consumer can fetch). The CHANGELOG line is
satisfied at the register as well as in the tree — the twelve entries at the register are committed, and
the thirteenth is this note's own — where, at the last taking, the section was empty at the register and
a release declared against that commit had nothing to publish. The `minor` comes from three signals rather than one: the notes, whose `### Added`
is the loudest heading they carry (the weighing reports `### Added — 6 entries, the loudest heading
the notes use`); the thirty commits since the last tag — `refactor 3, test 5, docs 7, chore 3, fix 6,
feat 6` — in which the six `feat:` commits are new capabilities; and the surface, which the pgcat
cycle moved more than any release before it — `public API` weighing `minor` on thirteen changes and
`config` `minor` on ten, the `repaired` kind, the pool probe and the reader-window direction among
them. The inventory weighs `patch` on its own: *nothing removed, renamed or added since the last
tag*.

That plan is a transcript of a run against this working tree, and nothing re-runs it. The numbers
in it that *are* checkable are held, though they are two different readings now: the entries the
notes carry **at the register** (the table at the top of this section — twelve) and the state of the
three records (section 1). Its own `13 bullet(s)` is the weighing's answer about the working tree,
which no guard here holds.

### What is actually in the unreleased section

Twelve entries at the register and thirteen in the working tree, and the register carries all twelve.
Seven are the pgcat cycle's: the machine-path guard — a reading that refuses a path naming the drive,
the account or the share it was written on, held beside the walk that refuses one climbing above the
root, with every path it reported rewritten to name nobody — the pgcat batch's four, the
pooler-direction entry, and the half-applied audit change's `### Fixed` one. Five are this cycle's
batches, all of them under `### Changed`: the guidelines file and the load-order row, the writers
declaration, the pgcat rehearsal's reading, the release script's exit paths, and the handoff renderer
with the count readings that travelled with it; the sixth is this note's own, the guard change that
lets its table say nothing is pending. Every one is a commit now — `0a880cb`, `3f05cff` and `c6b5d0c`
for the pgcat entries, and the five commits section 1's fence lists for these — rather than the
working-tree state the paragraphs above were written in.

The nineteen commits in section 2 are the pgcat cycle's ten — the rule at the root, the walk that reads
it, the two records whose path pointed outside the package, the ignore rule for the machine record,
this note's own earlier re-take, the decision that a consumer's copy carries none of the tools under
`bin/`, the guard that holds section 5's environment rows to the machine record, the reading that
refuses a path naming the machine it was written on, the two install events that ran a script a
consumer's copy does not have, and the guard that held this pending list in both directions — and this
cycle's five batches, whose commit fence section 1 carries. What they change for a consumer is
`README.md` alone — prose about which commands are a checkout's — because `AGENTS.md`, `BEHAVE.mdc`,
`tests/`, `.gitattributes`, `.gitignore` and `bin/` are all `export-ignore`d. The weighing agrees with
that reading rather than with a summary of it, and its own lines are the surface the pgcat cycle moved
(see the plan above) with the inventory current.

The thing to know about is the **boundary flip**, which is released and not installed:
`db:pgcat-window-flip` (`src/Console/Commands/DbWindowFlipCommand.php`) and the reader windows as
scheduled tasks the package registers are all in `v0.2.0-alpha2` — cut, tagged and pushed — so
this cycle has nothing to add to them, and they reach no application until the pin in section 4
moves. The pooler left on the writer-only config inside an open reader window answers `degraded` at
HTTP 503, and so does the same fault from the other end — the readers config left in place outside
every window — because what the verdict reads is whether the file and the windows disagree, not
which of the two is ahead; either way round is part of that release, and it is the one change in it
that a monitor or a deploy gate can feel. Which end a failure is at, rather than whether there is
one, is this cycle's rather than `v0.2.0-alpha2`'s: `direction` (`inside_window`,
`outside_window`) and the `db:doctor` row that prints it are commits now — `3f05cff` — like the entry
that describes them.

---

## 4. Unpacked — the application side has none of it

A tag in this package changes nothing an application runs until the application's pin moves. The
consumer here is the LPR app, and the last reading of its pin — taken by hand on 2026-10-06,
before `v0.2.0-alpha2` existed — was:

```
site/composer.json  →  "uak35/laravel-weighted-dbmanager": "v0.2.0-alpha1"
```

That is a fact about **another repository**, which this one's test cannot ask about and this
thread did not re-read: it is written here as the last reading rather than as today's pin, and
the pin may have moved since. So everything section 3 calls released — the boundary flip
included — is in a tag and in no installation until that pin moves, which is the difference
between "the flip is implemented", "the flip is released" and "the flip is deployed" that a fresh
thread can otherwise spend a long time establishing.

What a release of this cycle would still need, and what is done:

1. the batches that were in the working tree are commits of their own now — `0a880cb` for the pgcat
   answerability batch, `c6b5d0c` for the audit record, `bab7c4e` for the derived-file list,
   `aa5f695` for the editor's ignore rule, and the commit that carries this note — so this step is
   done;
2. push the commits on `dev` — the ten the table in section 2 counts, and the five this cycle
   added — or declare the run with `--skip-ci`;
3. the `## Unreleased` entries: the register carries one and the working tree six, so a release
   declared against the register would publish the machine-path entry alone, while the release
   commits the section as it stands;
4. raise the consumer's pin, in the application repository, to the new tag;
5. the application-side switches and schedule entries that a flip needs, which live in that
   repository and are not part of this package.

Steps 4 and 5 are **outside this repository**. Nothing here can do them, and a change in this
repository is not an installation change until each of them happens.

---

## 5. Working here — the facts that do not change

| Field | Working here |
|---|---|
| PHP | The binary `.agents/machine.local.json` records as `php.exe` (8.4.26). It is **not on `PATH`** in the shell, so a bare `php` does not resolve — which no longer decides which interpreter a command runs on: every script under `bin/` re-runs itself under the recorded one, so start it with whatever `php` this shell has and the work happens on the recorded interpreter. |
| Composer | 2.10.3. `composer.phar` sits in the package root and is **gitignored**, so a test must never shell out to composer. |
| Git identities | `origin` is HTTPS (`https://github.com/UAK-35/laravel-weighted-dbmanager.git`), `commit.gpgsign=true`, `user.signingkey=9CE840D871DE7A62`, and `core.hooksPath` is unset — this repository has **no** pre-commit gate of its own. |
| Skills | `.agents/skills/{pkg-commit,pkg-push,pkg-deploy}/SKILL.md`, indexed by `.agents/README.md`. Hand-maintained — no `.skills/` sources, no generator, no `verify.py` — so a file there is the only copy. |
| Checks | `php bin/checks.php --list` names thirteen; use `--only=` subsets. The full run and the whole `tests/` tree both exceed what a single agent command is allowed to take, so run one file or one directory at a time. `interpreter` is the one that reads the machine record — it fails on a `php.exe` that is gone, one that starts and cannot read the script it would be run on, one that reports another version, or one below the requirement the record carries. |
| Style gate | `php bin/tool.php pint --test` (PSR-12). |
| Static analysis | `phpstan.neon.dist`: level `max`, over `src/` only — a file under `tests/` is style-checked but not analysed. |
| In a consumer's tarball | `docs/` |
| Left out of it | `tests/`, `.agents/`, `bin/api.php`, `bin/checks.php`, `bin/counts.php`, `bin/publish-config.php`, `bin/release.php`, `bin/surface.php`, `bin/weighing.php`, `bin/inventory.php`, `bin/blame.php`, `bin/tools.php`, `bin/tool.php`, `bin/interpreter.php`, `bin/handoff.php`, `bin/composer-link.cmd`, `bin/composer-ca.ps1`, `CHANGELOG.md`, `RELEASING.md`, `PUSHING.md`, `STATIC-ANALYSIS.md`, `HANDOFF.md`, `AGENTS.md`, `BEHAVE.mdc`, `phpstan.neon.dist`, `phpunit.xml.dist`, `pint.json`, `.gitattributes`, `.gitignore`, `.editorconfig`, `.idea`, `files.tsv`, `methods.tsv`, `surface.tsv` |
| Text search | `rg` (ripgrep 15.2.0) is on `PATH`; `grep` works too. |
| Machine record | `.agents/machine.local.json` — the facts above as the machine running the suite holds them, compared by `tests/Unit/Docs/HandoffTest.php`; gitignored, so a clone without the file skips that comparison. |

The two tarball rows are read out of `.gitattributes` as it stands, and this is the one row in
this table that is a **decision** rather than an observation: every script under `bin/` is
export-ignored, so a consumer's copy carries none of them, and the row above names `docs/` as what
this tree contributes to one.

The three that were the exception are the tools that write what a release reads —
`bin/api.php` renders `API.md` from the inventory, `bin/counts.php` renders the counts the records
state, `bin/publish-config.php` copies the sample config — and they were left shipping as observed
rather than decided. Holding them back is the decision, and it moves the published README with it:
the section that describes the `bin/` group is now a checkout's tools, and the commands that run
one are marked as a checkout's rather than an installation's.

`tests/Unit/Docs/DocCitationsTest.php` treats a `test_…` name in a record as a citation, and
refuses one no class declares. Its corpus is `README.md`, `RELEASING.md` and `docs/*.md` — this
file is not in it, which is why the test named at the top of this one reads it instead.

The table above is held rather than trusted. `tests/Unit/Docs/HandoffTest.php` compares each row
with `.agents/machine.local.json`, the machine's own copy of those facts, and
`tests/Unit/Docs/HandoffEnvironmentTest.php` compares the rows that describe a machine with the
machine this suite is running on: the named `php.exe` run for its own `PHP_VERSION`, a bare `php`
attempted so that the row's claim about `PATH` is measured rather than assumed, `php composer.phar
--version`, `rg --version`, `git remote get-url origin` and the three git settings — where `git
config` exiting 1 is the "unset" the row claims — `pint.json`'s preset through `bin/tool.php`, and
the maintenance sentence: no `.skills/` sources, no generator, no Python among the files a commit
would carry. Where a row describes a machine that is not this one the comparison skips rather than
fails, which is what a fresh clone and CI's runner see — the same shape as the record itself:
absent is a skip, present and different is a failure.

Four of the note's own claims were checked by control rather than taken on trust. Editing section
3's count row to `3 entries — 3 added, 0 fixed, 0 changed` fails `HandoffTest` with *"HANDOFF.md
says the Unreleased section carries added 3, changed 0, fixed 0; the weighing counts added 1,
changed 0, fixed 0"*, and editing it to `1 entry — 2 added, 0 fixed, 0 changed` fails it with
*"…carries added 2, changed 0, fixed 0; the weighing counts added 1, changed 0, fixed 0"* — the
reading that had to learn the singular, which is what makes the change that widened it a reading
rather than a shrug. Writing the pending set's counts back into section 1 fails it two ways, and both
were measured: kept inside the sentence that names the derivation — *the size of the pending set is
the porcelain `git status --short` prints: twelve paths differ, nine tracked paths changed and three
new files* — it is caught at line 113 with *"…states the pending set as a count again (twelve paths,
nine paths, three files)"*, and restored as the sentence it was, counts first and the derivation after
them, it is refused by the reading itself, which says section 1 no longer says what the size of the
pending set is. Editing this table's Composer row to 2.10.4 fails the environment test, which names
the row's line and both versions —*"HANDOFF.md line 323 puts Composer at 2.10.4, and the phar it names reports 2.10.3"*. Every edit was restored byte for byte afterwards, which is why the file the
suite reads is the file described here.

The readings themselves were held by control in the same way, and every edit was restored byte for
byte. A refusal spelling only the plural — `'/^paths$/i'`, where the table's pair is `path` and
`paths` — fails the test that holds the pairs, which names the form, the file and the line:
*"A count reading in the guard spells its own noun rather than building it from the pair:
tests/Unit/Docs/HandoffTest.php:239  the form [paths], spelled into the pattern rather than built from
the pair (Handoff::counted('path'))"*. The Unreleased totals spelling only `entries` fails the same
way, at line 677 of `tests/Support/Handoff.php` — the reading this paragraph already says had to
learn the singular — and the `entr(?:y|ies)` it used to carry is refused as *`y|ies`* at its line once
it is written back beside the table. A pair declared in one form, `entry`'s singular written as its
plural, fails with *"The guard counts [entry] in one form, so a reading of it cannot tell a count of
one from a count of many"*.

The shapes the scan refuses are a test rather than a paragraph now. The lines it has to refuse and the
three it has to leave alone — a pattern built around the pair, a form running into the key it names,
and a form inside a variable — are composed by the suite from the declaration's own forms, and both
directions were measured: a scan blinded to matching lines fails it with *"The scan read nothing in a
line no note could be read through — a plural spelled into a pattern of its own"*, and a scan whose
variable cut is removed fails it with *"The scan refuses a line it has no business refusing — a form
inside a variable"*. Both mutations were restored byte for byte.

The release table's teeth were measured the same way, and every edit was restored byte for byte. A
refusal made to write on its way out — `fail()` given one `file_put_contents` before its `exit(1)` —
fails the promise test on the first refusal row with *"a directory that is not a repository: wrote to
the repository on its way out — files[.refusal-marker], written[.refusal-marker]."*, which is the
reading naming the file it found rather than counting it. An exit added to `bin/release.php` that no row
drives fails the census with *"bin/release.php has 14 refusal exit site(s) before the apply (lines 328,
336, 339, 363, 377, 407, 426, 494, 524, 531, 569, 594, 628, 675) and the table drives 12 — except the
guard the parse gate already applies, which the row for that argument drives by proving the gate
answers first."*, and a row declaring a shape the script does not have fails it in the other direction
with *"The table declares 1 shared exit(s) and the script has no such exit."* The thirteen refusal
sites and the twelve rows are the same population: the difference is the second reading of
`--version`'s rule behind the parse gate, which the census measures as the refusal whose sentence a
usage error already prints.

The renderer's teeth were measured the same way, and the edit was restored by re-rendering rather
than by hand. A count in a rendered block edited to disagree with git — `| Unpushed | 19 commits`
written `| Unpushed | 9 commits` — is refused by `php bin/handoff.php --check` with *"  unpushed   out
of step… 1 block(s) out of step — run `php bin/handoff.php` to render them."* and exit 1, and by the
block test with *"HANDOFF.md's `unpushed` block is not what git says it is. The block is an output
rather than a claim:…"*; running the renderer puts the block back, and the note is then in step and
byte-identical to the file the suite reads. The other direction is the file the register does not
carry and no words are declared for: with one at the root — not ignored, so it is part of the pending
set — `--check` exits 1 with *"The blocks could not be rendered: The register does not carry
handoff-control-undeclared.md and no words are declared for it. A prose column cannot be derived, so
add one line about it to Handoff::PENDING_DESCRIPTIONS…"*, and the block test errors on the same
sentence rather than rendering a row with a blank prose column. Removing the file put the note back
in step.

A third control is the empty table itself, because the state this cycle ends in is the one the guard
used to refuse. With one untracked file at the root — `handoff-control-uncarried.md`, not ignored —
`HandoffTest` fails on the comparison that holds the paths no commit carries against the ones section 1
names: *"This checkout holds 1 file(s) that the commit section 2 names as `HEAD` does not carry, and
section 1 names 0 of them. A file nothing names is work the next thread cannot see: - handoff-control-uncarried.md"*.
That is the reading that makes an empty table safe rather than blind, and it was measured before the
refusal it replaced was removed; `php bin/handoff.php --check` exits 1 on the same file with the words
refusal, because the prose column is the one input git cannot answer. Removing the file put both back
in step, and the table is the two header rows it is meant to be.

### What the test above cannot ask

Three claims in this file are a reader's to judge, and are named here so that a green suite is
not mistaken for a checked file:

* **the remote**. The tip of `origin/dev` was asked of GitHub on the day; the test holds the
  commit that answer named, and the commits between it and the basis, but not the ref it sits
  on — a fetch that moved `origin/dev` would not fail it.
* **the plan and the evidence blocks**. The release plan in section 3 and the runs quoted in
  section 5 were runs against this working tree, as is the record check section 1 states as its
  transcript. Their checkable arithmetic is held (the entries the notes carry, the records being
  out of step), and the plans themselves are not.
* **the pinned version in section 4**, which is a fact about the application repository — and,
  this cycle, a reading taken a day before the release it names.
