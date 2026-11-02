# Investigation — the boot stamp: one file with two roles, not two files

| | |
|---|---|
| Question | is the pgcat boot stamp (`SWRR_PGCAT_BOOT_FILE`) a different file from the ECS task container's boot stamp, and why does the package use one path for both? |
| Package | `uak35/laravel-weighted-dbmanager` at `v0.2.0-alpha11` |
| Config read | `config/db-manager.php` (`swrr.pgcat.boot_file`), `README.md` (boot window), `docs/deployment-contract.md` |
| Code read | `src/Pgcat/FlipWindow.php`, `src/Pgcat/PgcatConfigFlipper.php`, `src/Providers/WeightedDatabaseServiceProvider.php`, `src/Console/Commands/DbDoctor.php`, `src/Pgcat/ScheduleCauses.php` |
| Deployment read | the two records of the consuming application's `entrypoint.sh` and its `setup_dbmgr_swrr` provisioning |
| Answer | one file, two roles. The package never writes it; it reads the deployment's stamp. The mutable file the question is thinking of is `state_file`, which is a different path |

## The short answer

**There are not two files.** There is one path, `swrr.pgcat.boot_file` — `SWRR_PGCAT_BOOT_FILE`,
defaulting to `sys_get_temp_dir() . '/container-booted-at'` and provisioned as
`/var/swrr/container-booted-at` — and it is the ECS task container's boot stamp. It is *named* in the
`swrr.pgcat` block because the only thing that consumes it is the pgcat boot window, not because the
package owns it.

The file the question calls "pgcat related … can be repeatedly written" is a **different path**:
`swrr.pgcat.state_file`, the flip's own record of what it applied. That one is rewritten on every run,
because that is what it is for.

## Who writes what, and who reads it

| | the boot stamp | the state file |
|---|---|---|
| Setting | `swrr.pgcat.boot_file` | `swrr.pgcat.state_file` |
| Path (as provisioned) | `/var/swrr/container-booted-at` | `/var/swrr/pgcat-flip-state.json` |
| Written by | the deployment's `entrypoint.sh`, once per container start, `date +%s` | this package, on every flip that changes or records something |
| Written by the package | **never** | always |
| Read by | `FlipWindow::bootedAt()`, and every surface that publishes the window | the flipper, `db:doctor`, `/health/db` |
| Lifetime of a write | one container start | until the next run |

The empty cell is the finding: a search of `src/`, `bin/` and `scripts/` finds no writer for the boot
stamp at all. The only writes to it in this repository are test fixtures.

## Why one path serves both names

The window has to be measured from the moment the **task** started, and the package is pointed at the
deployment's stamp rather than keeping one of its own. `FlipWindow` says why in its own words:

> The container's own boot, stamped by `entrypoint.sh` into a file whose path both sides read from
> configuration — deliberately not "when this process started", which is a different moment for every
> Octane worker and every queue worker, and not "when the first flip ran", which cannot happen at all
> if the scheduler is the thing that is broken.

`README.md` states the same contract in one line: *"A file `entrypoint.sh` writes before the pooler is
started, holding `date +%s` (`swrr.pgcat.boot_file`)."* And `docs/deployment-contract.md` puts the
ownership on the deployment side: *"The container writes the time it started into a file the package is
pointed at"*.

Two records already say the stamp is not pgcat's, which is the premise of this investigation confirmed
rather than contradicted: `docs/boot-audit-surfaces.md` calls the boot stamp one of the paths on the
fault list that *"are not pgcat's at all"*, and `PgcatConfigFlipper` notes that neither the stamp nor
the audit lock *"is one of pgcat's own"*. The setting's prefix is a grouping — the block that reads it
— not a statement of who writes it.

## Where the premise and the package actually collide

The question's second half is right about a real defect, and it is not a second file. It is the
package's **writability requirement** on a file it never writes.

The flip's fault list judges the stamp for write access:

- `PgcatConfigFlipper::UNNAMED_WRITTEN_PATHS` carries a `boot_file` row whose cost is *"the container
  cannot record when it booted, and the boot window never closes: the entry flips on every run instead
  of standing down after it"*;
- `db:doctor`'s `pgcat files` and `write paths` rows print it, the second as one of *"the paths this
  package must be able to write while the flipper is armed"*;
- the provider raises `swrr.pgcat.boot_file.unusable`, whose docblock is *"The container's boot stamp
  cannot be written where `swrr.pgcat.boot_file` points."*

And the test behind all three is `is_writable()`, run **as the application**:

```php
private static function writable(string $path): bool
{
    return file_exists($path) ? is_writable($path) : self::directoryWritable(dirname($path));
}
```

So the package asks "could *this process* write it?" and reports the answer as "can *the container*
record its boot?". Those are the same question only while the application and the entrypoint are the
same user with the same view of the path — and that is precisely what a stamp that is stamped once and
then made immutable would break.

Both directions of the mismatch are reachable:

- **Write once, then `chmod a-w`** — the shape the question asks for — leaves `is_writable()` false
  for ever. The window works perfectly (it only ever reads), while `pgcat files` and `write paths` fail
  on every boot and the audit logs a finding that cannot be resolved without undoing the immutability.
- **Stamped by a different user** — the entrypoint as `root`, the application as `www-data` — is the
  same false alarm from the other side: a `0444` file owned by `root` is one the entrypoint rewrites on
  every start and one the application cannot write. The finding says the container cannot record its
  boot; in fact it did, and it will again.

The signal it gives up is real: the one recorded incident this requirement was written for is a
container whose stamp directory stopped taking the write, which left the window open for the whole
life of the container. That is exactly the state the check exists to name. The defect is that it names
it by asking the wrong principal, not that it looks at all.

## What the difference means for the two models

| | write once, then immutable | written on every start |
|---|---|---|
| The window | works — it is a reader | works |
| The package's current verdict | **fails**, permanently | passes when the application's user can write |
| What has to be true | the stamp is world-readable, and the flip never needs to rewrite it | the writer can still write it after a restart on a persisted volume |

The second column is what the current LPR image does: `setup_dbmgr_swrr` pre-creates the file empty at
`0664` owned by the application's user, and the entrypoint stamps it. Immutability would work for one
container and break the next one on the same volume — the file would still hold the *previous* start,
so the window would be judged against a boot that is not this container's. Write-once is safe only if
the path is fresh per start.

## The recommendation

Keep one file, and stop asking the application to write it:

1. **Judge the stamp as the window's input, not the flip's output.** Fail on what actually leaves
   `FlipWindow::closed()` false for ever: the file is missing, unreadable, empty, or holds no positive
   number. That is the fault, and it is answerable from the reading the package already takes.
2. **Report the mode and owner as a note, not a verdict.** Keep printing them — the recorded incident
   was diagnosable because the mode travelled with the sentence — but a mode the application cannot
   write is not, on its own, a container that cannot record its boot.
3. **Take the stamp off `write paths`.** That row's subject is the paths *this package* must write; the
   stamp belongs on a reading of what a flip *reads*, beside the two variant files. The audit lock
   stays where it is: the package does write that.
4. **If immutability is wanted, say so where it is required.** The deployment's entrypoint stamps and
   then `chmod a-w`s the file, and the application only needs it readable. No second file is needed:
   two stamps would be two clocks, and the window has exactly one meaning.

What would move if this is taken: `PgcatConfigFlipper`'s table and `fileProblems()`; the provider's
finding prose; `db:doctor`'s `pgcat files` and `write paths` rows; and the records that tabulate them —
`README.md`, `docs/deployment-contract.md`, `docs/boot-audit-surfaces.md` — with the tests that hold
each sentence.

## Deliberately not investigated

- **Which user the entrypoint runs as.** That is a fact about the image, and it is not in this
  repository. The recommendation does not need it: it stops asking the application the question either
  way.
- **Whether `/var/swrr` is a per-task or persisted volume.** It decides whether write-once is safe, and
  the provisioning record says the file is pre-created rather than whether the directory survives a
  start.
- **`/health/db`'s `probe stamp` block and the audit record.** Those are the *other* clock — when the
  audit record was last written — and they are already judged as a reader's question. They are the model
  the boot stamp should follow.
