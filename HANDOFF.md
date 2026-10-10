# Handoff — the state of this package, taken 2026-10-10

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
2026-10-07, with the remote asked directly (`git ls-remote`) rather than trusted from the local
`origin/dev` ref. Section 1 was re-taken on 2026-10-10, the date above, and it is the one part of
this file that describes *now* rather than the commit it is read against.

---

## 1. Pending — written, green, uncommitted

Five batches that were pending when this note was last taken — and the reading that lets it be
taken at all — are commits now, and this re-take is the eighth. They are the pgcat *direction*, the
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
which is a rule for an editor's own project file and nothing this package installs). What is left in
the tree and in no commit belongs to no batch here: the rule file another hand left at the root while
this one was being re-taken, whose fate — this project's rules or the machine's — is its reader's. One
path differs from the commit section 2 names as `HEAD`: zero tracked paths changed, and one new file.

What this section's register (`f92abd2`) does not carry — which is the question its table is read as, and not the same one as what is uncommitted — is the two release-suite files the release batch wrote, both of them in `f10c318`, the three the pgcat answerability batch added, all of them in `0a880cb`, and one file no commit carries at all, left in this tree by another hand while this note was being re-taken:

| File | What it is |
|---|---|
| `tests/Unit/Release/DryRunTest.php` | committed in `f10c318`: a `--weigh --dry-run` held to the tree, the refs and `HEAD` it left alone, read against the same command run for real |
| `tests/Unit/Release/PublishedPageTest.php` | committed in `f10c318`: a fixture release held to the page it publishes — the bytes the record renders, the stamp both of them name, and the commit the tag points at |
| `src/Pgcat/PoolProbe.php` | the pool-answerability probe the pgcat self-heal grew: a PostgreSQL v3 startup packet and the first message back, read as `answered`, `missing` or `silent` — new in `0a880cb` |
| `tests/Unit/Pgcat/PoolProbeTest.php` | the test beside it — the packet's shape, the three answers and the injected exchange — new in `0a880cb` |
| `docs/pgcat-pool-probe.md` | the design record for the probe: why an unanswerable pool is a fault the flip repairs, why only `failed` folds into the endpoint's verdict, and why the reading is off by default — new in `0a880cb` |
| `BEHAVE.mdc` | another hand's, and no batch here's: a coding-agent rule file left at the root while this note was re-taken — uncommitted, and named here because a file nothing names is work the next thread cannot see |

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

The batches this section describes, and the five commits it used to describe:

```
chore: the editor's project file is ignored by the repository              aa5f695
refactor: every derived file is named once, in the list that writes it    bab7c4e
fix: the audit record is handed back writable, whoever boots next         c6b5d0c
feat: a pooler that cannot answer for a pool is a fault a flip repairs    0a880cb
feat: the reader-window verdict names which end of the window disagreed   3f05cff
fix: the release script renders the page its record publishes             f10c318
docs: a session starts by reading the machine record for its interpreter  4f4967d
docs: the handoff note is re-taken at the four batches it described       e90cc6f
fix: the plan names the files it is ignoring on a dirty tree              22de815
```

**The batches were commits of their own rather than one**, because they are unrelated work that happens
to share a checkout — and the note rides with the last of them rather than the first, because its
pending counts are a claim about this working tree and the only state they can be true in is the one
after everything else has landed. The package has no `core.hooksPath` (so stock `.git/hooks`, and no
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

| Field | At the snapshot |
|---|---|
| Branch | `dev` |
| HEAD | `f92abd2` — *test: the pending list in the handoff note is held in both directions* |
| `origin/dev` | `0907fce8fb351c62863320cd78a0ec6e1cbf84dd` — *fix: the published API page is re-rendered for the tag the release cut* |
| Unpushed | 10 commits — the ones from the table's `HEAD` back to the commit above it |
| Since the last tag | chore 2, docs 2, feat 2, fix 2, test 2 |

The `HEAD` row is the basis every number in this file is read against. The tip of `origin/dev`
was asked of the remote directly (`git ls-remote`, not the local ref) on the day — and again when
this section was re-taken, which named the same commit; the commits between the two are what is
unpushed.

The ten most recent are the ones a reader is most likely to be standing in the middle of:

```
f92abd2  test: the pending list in the handoff note is held in both directions
e5c4a7c  fix: the two install events that ran a script a consumer's copy does not have
928808e  feat: a path that names a machine is a finding in the files a commit carries
6f5cb6c  test: the handoff note's environment table is held to the machine's own record
343d645  chore: the package stops shipping the scripts that write its records
bcc64ea  docs: the handoff note is re-taken at the rule and its guard
7cbd43d  docs: the rule that the work is this folder, and nothing outside it
b4c7dcf  feat: a walk refuses a record's path that climbs out of the package
b8f3b5e  fix: the README and the config publisher stop pointing above the package
4f0516f  chore: a copy of the machine record beside it cannot be committed
```

They are also the whole range — `git log --oneline origin/dev..HEAD` has nothing the fence does
not.

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
| `## Unreleased` at the basis | 1 entry — 1 added, 0 fixed, 0 changed |
| The notes weigh as | minor — the one `### Added` entry is the loudest heading the notes use |

The absent version is the exact sentence that decides whether the next one may be a prerelease,
and it may. The headings the weighing reads are the `### Added`, `### Fixed` and `### Changed` a
Keep a Changelog section writes; the section carries one of them — the machine-path guard's own
entry — so `### Added` is the loudest heading it has and the severity is the one the weighing gives
that. The marker is the one a note claims rather than the word alone, which is why the last release
could be declared breaking from a section that carries four headings and no `Removed` one.

### The release the weighing would ask for, and the line that refuses it

`v0.2.0-alpha2` was cut and pushed on 2026-10-06. Since then the entries under `## Unreleased` have
grown from the one the register carries to six in the working tree — five under `### Added` and one
under `### Fixed` — so the table above is the register's single entry and the section the weighing
reads carries them all; the severity does not move, `### Added` staying the loudest heading either
way. Asked on 2026-10-07 what a release would do, `exit 0`:

```bash
php bin/release.php --weigh --branch=dev --dry-run
```

```
  branch        dev  (dirty)
  ci            HEAD is not the tip of origin/dev
  latest tag    v0.2.0-alpha2
  bump          minor  (weighed: a minor change)
  next version  0.2.0  (tag v0.2.0)
  CHANGELOG     ## Unreleased -> ## 0.2.0 - 2026-10-07, new Unreleased section above
  release notes 2 bullet(s) in the promoted section
  inventory     39 file(s), 198 method(s), 224 key(s)/member(s)  (fresh, weighed against v0.2.0-alpha2)
  branch-alias  dev-dev, dev-main -> 0.2.x-dev  (unchanged)
  commit        Release v0.2.0
  tag           git tag -a v0.2.0 -m "v0.2.0"
  push          git push origin dev --follow-tags
```

One line is unsatisfied, and it is the check working rather than an obstacle: `ci` (section 2 is
unpushed, so the commit being released is not one a consumer can fetch). The CHANGELOG line is
satisfied twice over now — the register carries the machine-path entry, and the working tree carries
that and this batch's — where, at the last taking, the section was empty at the register and a
release declared against that commit had nothing to publish. The `minor` comes from both signals:
the notes, whose `### Added` is the loudest heading they carry (the weighing reports `### Added —
2 entries, the loudest heading the notes use`), and the eleven commits since the last tag — `test 2, fix 3,
feat 2, chore 2, docs 2` — in which the two `feat:` commits are new capabilities. The public API,
the config and the inventory all weigh `patch`: *nothing removed, renamed or added since the last
tag*.

That plan is a transcript of a run against this working tree, and nothing re-runs it. The numbers
in it that *are* checkable are held, though they are two different readings now: the entries the
notes carry **at the register** (the table at the top of this section — one) and the state of the
three records (section 1). Its own `2 bullet(s)` is the weighing's answer about the working tree,
which no guard here holds.

### What is actually in the unreleased section

Six entries in the working tree, and the register carries one of them: the machine-path guard — a
reading that refuses a path naming the drive, the account or the share it was written on, held
beside the walk that refuses one climbing above the root, with every path it reported rewritten to
name nobody. The five the cycle adds are the pgcat batch's four, the pooler-direction entry, and the
half-applied audit change's `### Fixed` one, and they are commits now — `0a880cb`, `3f05cff` and
`c6b5d0c` — rather than the working-tree state the paragraphs above were written in.

The ten commits in section 2 are the rule at the root, the walk that reads it, the two records
whose path pointed outside the package, the ignore rule for the machine record, this note's own
re-take, the decision that a consumer's copy carries none of the tools under `bin/`, the guard that
holds section 5's environment rows to the machine record, the reading that refuses a path naming
the machine it was written on, the two install events that ran a script a consumer's copy does not
have, and the guard that holds this pending list in both directions. What they change for a
consumer is `README.md` alone — prose about which commands are a checkout's — because `AGENTS.md`,
`tests/`, `.gitattributes`, `.gitignore` and `bin/` are all `export-ignore`d. The weighing agrees with that
reading rather than with a summary of it: `public API` and `config` are *nothing removed, renamed
or added since the last tag*, and the inventory is current.

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
`outside_window`) and the `db:doctor` row that prints it are in the working tree, uncommitted like
the entry that describes them.

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
| Left out of it | `tests/`, `.agents/`, `bin/api.php`, `bin/checks.php`, `bin/counts.php`, `bin/publish-config.php`, `bin/release.php`, `bin/surface.php`, `bin/weighing.php`, `bin/inventory.php`, `bin/blame.php`, `bin/tools.php`, `bin/tool.php`, `bin/interpreter.php`, `bin/composer-link.cmd`, `bin/composer-ca.ps1`, `CHANGELOG.md`, `RELEASING.md`, `PUSHING.md`, `STATIC-ANALYSIS.md`, `HANDOFF.md`, `AGENTS.md`, `phpstan.neon.dist`, `phpunit.xml.dist`, `pint.json`, `.gitattributes`, `.gitignore`, `.editorconfig`, `.idea`, `files.tsv`, `methods.tsv`, `surface.tsv` |
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
*"…carries added 2, changed 0, fixed 0; the weighing counts added 1, changed 0, fixed 0"*; editing
section 1's counts sentence to state *one* file where the tree has two fails it at line 34 with
*"…says 21 path(s) differ from the register, 19 of them tracked paths that changed and 1 file(s) it
does not carry; this checkout has 21, 19 and 2"*; and editing this table's Composer row to 2.10.4
fails the environment test, which names the row's line and both versions — *"HANDOFF.md line 323
puts Composer at 2.10.4, and the phar it names reports 2.10.3"*. The second and third are the two
readings that had to learn the singular, and each still fails on a count the tree does not have —
which is what makes the reading the change widened a reading rather than a shrug.
Every edit was restored byte for byte afterwards, which is why the file the suite reads is the file
described here.

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
