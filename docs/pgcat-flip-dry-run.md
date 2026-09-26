# Which half of a flip can you rehearse?

A design record for `db:pgcat-flip --dry-run`.

A flip is a file replaced and a process restarted, at a moment the operator chooses and
the application does not. Both halves are irreversible in the way that matters: once
`pgcat.toml` has been renamed over, the previous file is the old mode; once
`supervisorctl signal HUP "pgcat:*"` has run, pgcat has re-read the new file. A flip that
half-works leaves the installation in the wrong mode, and the window it was supposed to
open is now.

The person who wants to know whether that will work is often not the person allowed to
perform it — a release engineer checking a deploy, an on-call engineer at 02:00 deciding
whether to wait for the next window, a reviewer reading a change to `pgcat.toml` variants.
This document records what the package offers them, which alternatives were considered,
and why they were not taken.

Everything below is implemented in `Pgcat\PgcatConfigFlipper::dryRun()` and its private
`rehearse()`, reported by `Pgcat\DryRunResult`, and printed by `DbFlipPgcatCommand`. The
rules are pinned by the tests named at the end.

---

## The answer in one line

**Perform every step whose effect can be undone, and report the three that cannot.** The
source is read, the temp file a swap writes is written into the target's own directory and
removed again, and the lock is taken and released — the same steps, by the same code
paths. The rename, the supervisor command and the state write are printed as what a flip
*would* do. The exit code answers "would a flip work", not "did a flip happen".

---

## Why this needed deciding at all

### 1. The interesting failure is invisible to metadata

`db:doctor`'s `pgcat files` row judges the swap's directory with `is_writable()` and
deliberately leaves no trace, which is the right call for a preflight that must be safe on
a read-only deploy. But `is_writable()` is a permission check, and a directory can pass it
and still refuse the write: an ACL that is not the POSIX mode, a read-only mount, a full
disk, an immutable attribute, a path that is a file. Those are exactly the failures that
appear at the window boundary and nowhere else, and the only way to find them is to write.

### 2. A rehearsal that writes has to prove it can un-write

Writing a probe file changes the world, so a dry run that writes has taken on an
obligation: the file must be gone by the time it returns. The rehearsal therefore treats a
cleanup failure as a **failure of the whole run** rather than a warning, because a dry run
that leaves a file behind is not a dry run. That is a strict rule, and it is the reason
the temp path is the one the swap uses — `{config_path}.tmp.{pid}`, in the target's own
directory — rather than a path in the system temp directory that would be trivial to clean
up and would prove nothing.

### 3. The state write is the step nobody thinks of

`applyCurrentState()` ends with `writeLastMode()`, which records the mode it applied. A
rehearsal must not do that: the next real flip compares the resolver's mode against that
record, so recording `readers` from a run that did not rename anything would make the next
flip believe the file had already been swapped — and it would decline to act until the
*next* mode change. So the rehearsal skips the write, and the report says so
(`left as it is`).

Its *writability* still has to be answered, though, because that write is silenced with
`@`. An unwritable state file means a flip reports success and repeats on every poll,
restarting pgcat each time — the documented failure this package keeps finding
(`db:doctor`'s `pgcat files` row reports it too). A rehearsal that only checked the swap
would call such an installation healthy, so it is reported, from metadata, and it fails
the run.

### 4. The exit code has to be a different question from a flip's

`FlipResult::exitCode()` answers "did the flip happen". The rehearsal cannot: it never
flips, and most installations it is pointed at are already in the right mode. So its exit
code answers "is there anything here that would stop a flip":

| Situation                                        | Kind             | Exit |
|--------------------------------------------------|------------------|------|
| the mode would change and every step works       | `would_flip`     | `0`  |
| mode unchanged, flipper not armed, driver refused | `would_not_flip` | `0`  |
| another instance holds the lock                  | `skipped`        | `0`  |
| a step a flip needs did not work                 | `failed`         | `1`  |

A lock held by another instance is not a fault — a flip would skip too, which is the lock
doing its job — and neither is a mode that is already applied. Only the last row is a
deploy problem, which is what makes `php artisan db:pgcat-flip --dry-run || exit 1` a
usable preflight.

---

## The candidates

### A — run the real flip and roll it back

Rejected. The rollback is a second rename and a second supervisor command, so the
"rehearsal" takes two irreversible steps instead of one, and the restart has already taken
effect by the time the rollback starts. pgcat processes would briefly be serving the wrong
pool — the very thing the whole design avoids — and a rollback that itself fails leaves
the installation in the mode nobody asked for, with the state file now claiming it is
correct.

### B — a richer `--status`

Rejected. `--status` already prints the paths, the commands, the mode and the record; what
it cannot do is write, and the write is where the interesting failure lives (reason 1).

### C — write the probe somewhere that is certainly writable

Rejected. A probe in `sys_get_temp_dir()` proves that `sys_get_temp_dir()` is writable.
The question is whether *the target's directory* accepts the file the swap writes, and
nothing else answers it.

### D — do nothing; point the operator at `pgcat files` and `pgcat supervisor`

Rejected. Those two rows between them check readability, writability, the executable and
the program name — an inference, assembled by hand, from two commands, on two surfaces,
neither of which writes. The reason to have a flip command at all is that the decision is
one answer; the rehearsal is that answer.

### E — perform what can be undone, report what cannot (chosen)

The steps are sorted by whether their effect can be undone, and the sort is the feature:
read, write-then-remove, lock-then-release are performed; rename, signal, state-write are
reported. Nothing is approximated and nothing is claimed that was not done.

### F — rehearse in a sandbox copy of the two files

Rejected. Copying `pgcat.toml` to a temp directory and swapping *there* would rehearse the
copy, in a directory the operator picked rather than the one pgcat reads. It tests the
part that is not in doubt.

---

## The chosen mechanism, in full

### The steps, and which side each falls on

| Step               | Side      | Detail it reports                                              |
|--------------------|-----------|----------------------------------------------------------------|
| the gates          | performed | the `disabledReason()`/`driverSupported()` sentence, or nothing |
| the lock           | performed | the lock file, taken and released                              |
| read the source    | performed | the source path and its byte count                             |
| the supervisor check | performed | supervisor's own answer, or the fault a flip would refuse on   |
| write the temp     | performed | the temp path and the bytes written                            |
| remove the temp    | performed | that the rehearsal leaves nothing behind                       |
| the rename         | reported  | `→ target`, and what it would do to the target's bytes         |
| the supervisor     | reported  | the exact command `supervisorCommand()` returns                 |
| the state write    | reported  | the state path and its writability                             |

The gates are not re-implemented. A scheduled rehearsal asks `disabledReason()` and a
forced one asks `driverSupported()`, exactly as `applyCurrentState()` and `forceMode()` do,
so a rehearsal cannot report a flip a real run would decline. It also takes the lock
before it looks at the mode, so a rehearsal answers "would *this* poll flip" rather than
"would a flip ever be needed".

The supervisor check is performed for the same reason the write is: a flip makes it, it
changes nothing, and a rehearsal that skipped it would predict a flip the flipper would
refuse. It is read-only by construction — the command is derived with `status` in the verb
position, never the configured one — so the one process a rehearsal starts is a question,
not a signal. When it fails, the rehearsal stops there and says so:

```
[dry run] a flip would fail (mode=readers): supervisor does not know "pgcat:*": … — a flip refuses before it swaps the file, so nothing would be replaced
  lock              done     taken and released: /var/lib/lpr/pgcat-flip.lock
  read source       done     /etc/pgcat/pgcat-readers.toml (1892 bytes)
  supervisor check  failed   supervisor does not know "pgcat:*": …
  rename            refused  never reached: the command was refused first, so the file would be left as it is
```

The `rename` row is there on purpose: with the check moved before the swap, "would this
flip replace the file?" is answered *no* by the report itself, rather than by the operator
noticing where the list stops.

### The write is the evidence

The temp file is `{config_path}.tmp.{pid}` — the same name the default copier uses, built
the same way — written with the source's own bytes and removed immediately. Two things
follow from writing *there*:

- The directory is the real one, so the proof covers the ACL, the mount, the disk and the
  path type that `is_writable()` cannot see.
- The rename's precondition is proved rather than assumed. A rename within one directory
  needs the new file to exist on that filesystem; the rehearsal has just put one there.

The byte count is printed for both halves, so the report shows the same bytes reaching
both places. It is the cheapest evidence there is that the read was the real read.

### Reported steps say what they would do, in the terms an operator cares about

The rename's detail names the source of truth for the question the operator is actually
asking — *is this the right variant?* — by comparing the target's current bytes with the
source's:

| Target state                    | Detail                                                                    |
|---------------------------------|---------------------------------------------------------------------------|
| does not exist                  | `the target does not exist yet, so a flip would create it`                |
| already holds the source's bytes | `the target already holds these bytes, so a flip would replace it with itself` |
| different content                | `the content would change`                                                |

The middle row is a report, not a fault: an operator may have applied the variant by hand,
and a flip replacing it with itself is not worth failing a preflight over.

### The failures a `swap()` would throw on are verdicts instead

`swap()` throws for empty paths and an unreadable source, and `applyCurrentState()` turns
the throw into a failed `FlipResult`. A rehearsal cannot throw: it exists to be asked, and
"a flip would fail here, at this step" is the answer. Every one of them is a `failed` step
with the sentence beside it — including the temp write, which is the failure this whole
feature exists to find.

### The command's three interactions

- **`--force-mode` combines with it.** A rehearsal that only ever asked the resolver would
  be unable to answer the pre-window question ("would forcing into readers work now?"),
  which is the one operators ask most. `--dry-run --force-mode=writer` mirrors
  `forceMode()`: it bypasses the resolver *and* the mode-on-record check, and rehearses the
  writer variant. `--force-mode` is validated before either branch, so the rehearsal
  cannot be reached with a value a real forced flip would refuse.
- **`--status` wins when both are passed.** A state report is a direct question about the
  present, and answering "where is the flipper" with a rehearsal would bury it. The dry-run
  plan is one flag away.
- **`--dry-run --watch` is refused**, exit `1`, with the reason. A watch daemon whose every
  pass is a rehearsal prints the same "would flip" line every interval forever, and would
  be indistinguishable from a daemon that is flipping — the one way this flag could
  mislead. A rehearsal is a single question, not a state to live in.

### One trace is left on purpose

The lock file. A flip takes that lock too, and an installation whose lock directory will
not accept the lock file is an installation a flip cannot run on — so the rehearsal
creating it is the same precondition being tested, not a side effect. It is also the same
file every real flip leaves behind, never deleted, so nothing new accumulates.

---

## Tests that pin the rules

| Test (in `tests/Unit/Pgcat/PgcatConfigFlipperTest.php`)                | Rule it pins                                                              |
|------------------------------------------------------------------------|---------------------------------------------------------------------------|
| `test_a_dry_run_performs_the_steps_that_leave_nothing_behind`          | the performed half happened (lock, read, write, remove) and the reported half did not — asserted on the report, on the copier/runner logs, *and* on the filesystem |
| `test_rehearsing_twice_is_still_not_a_flip`                            | the state write was skipped: two rehearsals both say `would_flip`          |
| `test_a_dry_run_of_a_mode_already_applied_reports_nothing_to_do`        | `would_not_flip`, exit `0`, and it stops before the swap                  |
| `test_a_dry_run_is_skipped_when_another_instance_holds_the_lock`        | a held lock is a skip, not a failure                                       |
| `test_a_dry_run_on_a_disabled_flipper_does_not_even_take_the_lock`      | the gates run first, as they do for a flip                                 |
| `test_a_forced_dry_run_rehearses_the_file_force_mode_would_apply`       | forced rehearsals bypass the resolver and the mode record                  |
| `test_a_forced_dry_run_declines_on_a_driver_pgcat_cannot_front`         | the forced path keeps the driver gate                                      |
| `test_a_dry_run_reports_an_unreadable_source_instead_of_throwing`       | a `swap()` throw is a verdict here                                         |
| `test_a_dry_run_reports_empty_paths_as_a_failure`                       | the same, for the missing-target throw                                     |
| `test_a_dry_run_fails_where_a_flip_could_not_write_its_temp_file`       | a directory that refuses the write is found — with the read already done   |
| `test_a_dry_run_fails_when_the_state_file_cannot_be_written`            | the silent state write is a failure, and the rename below it still reported |
| `test_a_dry_run_says_what_the_rename_would_do_to_the_target`            | all three target comparisons, including "replace it with itself" not failing |
| `tests/Unit/Pgcat/DryRunResultTest.php`                                 | kinds, exit codes, summaries, step lookup and the array form               |
| `WeightedDatabaseServiceProviderTest`                                   | the command: the rendered report, `--force-mode` combined, `--status` precedence, `--watch` refused, and a rehearsal on a non-PostgreSQL connection |

The load-bearing assertions are the negative ones. `$copyLog === []` is stronger than any
`assertFileEquals`: the injected copier *is* the rename, so an untouched copy log means the
target was never replaced, whatever the report says. The mutation test used while building
this — replacing the rehearsal's write with a call to the copier — fails six of these
tests, including the exit-code ones.

---

## Known limitations

- **The rename is not proven, only its precondition.** A filesystem could accept the temp
  write and then refuse the rename (an immutable *target*, a sticky directory owned by
  someone else, a rename policy). The rehearsal cannot close that without performing the
  rename, which is the whole point.
- **The supervisor check is a moment.** The rehearsal asks supervisor, and a flip asks
  again a second later; a command that answers `status` can still fail when it runs. That
  is why the flip rolls the file back instead of trusting the check — and why the
  rehearsal's `would_flip` is a prediction, not a promise.
- **The rollback is not rehearsed.** There is no way to rehearse "what happens if the
  command fails" without failing it, so the rehearsal reports the command and stops. The
  rollback is covered by the flipper's own tests (including the mutation that removes it).
- **Cleanup failure is not constructible in a portable test.** The step is reachable (the
  mutation above fires it, and its sentence was verified by hand) but no test forces a
  directory to accept a write and then refuse the unlink.
- **A host that injects its own `fileCopier` gets a rehearsal that writes the local path
  directly.** The copier's contract is "make the target the source's content", which is
  exactly the step a dry run must not take, so the rehearsal cannot route through it. An
  installation whose swaps do not go through the local filesystem gets a probe of the
  local filesystem — right for the default, wrong for that extension point. It is
  documented rather than worked around, because the alternative is a copier contract with
  a "rehearse" mode that every implementation would have to honour.
- **The rehearsal runs as whoever runs it.** Like `db:doctor`, it proves that *this* user
  can write those paths, not that the flipper's user can. Run it as the same user.
- **It says nothing about the content being valid pgcat configuration.** It compares bytes
  and paths. A syntactically broken variant is a different check.

---

## What would change this decision

- **A reversible swap.** If the flip stopped being a rename — pgcat reloading from a
  versioned file, or the target being a symlink the flip repoints — then the irreversible
  half would be gone, and a rehearsal could perform the whole thing inside a probe and put
  it back. The one step this design refuses to take is the one that has no undo.
- **A state write that cannot be silenced.** `writeLastMode()` uses `@`, so a failure there
  is invisible to the flip and reported only by `db:doctor` and this rehearsal. If it
  became fatal, the rehearsal's metadata probe would be redundant.
- **A `--dry-run` on the `db:doctor` side.** The rehearsal is a command because it writes;
  if `db:doctor` gained a row that wrote, it would stop being the thing that is safe to run
  on a read-only deploy.

---

## Files

| File                                              | Role                                                              |
|---------------------------------------------------|-------------------------------------------------------------------|
| `src/Pgcat/DryRunResult.php`                      | the rehearsal's value object: kinds, exit codes, step lookup, array form |
| `src/Pgcat/PgcatConfigFlipper.php`                | `dryRun()` (gates + lock) and `rehearse()` (the swap, short of the rename); the flip's preflight and rollback, which the rehearsal mirrors |
| `src/Pgcat/SupervisorStep.php`                    | `inspect()` — the one verdict the rehearsal prints, the row reports and the flip refuses on |
| `src/Console/Commands/DbFlipPgcatCommand.php`     | `--dry-run`, the rendered report, the `--json` object ([pgcat-flip-json.md](pgcat-flip-json.md)), the `--watch` refusals |
| `tests/Unit/Pgcat/DryRunResultTest.php`           | the value object                                                    |
| `tests/Unit/Pgcat/PgcatConfigFlipperTest.php`     | every step's side of the line                                       |
| `tests/Unit/Weighted/WeightedDatabaseServiceProviderTest.php` | the command as the operator sees it                   |
