# When does nothing running become a fault?

A design record for noticing that the per-minute entry has stopped being run, which is the one
failure this package could not see: nothing is malformed, nothing refuses, and every reading is of a
world that stopped moving.

## The answer in one line

Every run of the entry stamps a beat in the flip's state file — including the runs that decide
nothing — and one tolerance derived from the cadence says when its silence is a fault. Three
surfaces report that one verdict: `/health/db`'s `schedule` block (which returns `503`),
`db:doctor`'s `schedule beat` row, and `php artisan db:scheduler-watchdog`, which exits `1` for a
container's own healthcheck and reaches nothing to ask: it reads the state file the flip wrote, the
schedule it builds in memory, and the three readings below.

## Why this needed deciding at all

Every other fact this package reports is about a *setting* — a switch written as something that is
not on or off, a path a flip cannot write, a store that does not answer. Those are read when
something asks. A schedule is different: the failure is that something stops *happening*, and the
surface that could report it is the one that stopped running.

There was a partial answer already, and its limit is the whole reason this record exists. The boot
window ([the flip's own bound](../README.md#how-long-the-flip-keeps-trying-the-boot-window)) judges
whether a *booting* container converged: it closes eight minutes after the boot stamp, and the
convergence it records is written by the runs inside it. After that the per-minute entry is a
deliberate no-op — it returns `window_closed` without reading or writing a file — so the state file
goes on describing the first eight minutes of a container's life for as long as the container lives.
A container that converged on its second try and lost its cron line an hour later leaves a state
file indistinguishable from a healthy one, and every field of `/health/db` reads `ok`: the query
answers, the replicas are healthy, the window closed with a convergence inside it, and the last mode
a flip recorded is still the right one because nothing has moved to displace it. That is 85 minutes
on the deployment this was written for.

## The beat, and why it is not the run record

`PgcatConfigFlipper::applyCurrentState()` writes one key before it consults anything — the window,
the lock, the resolver:

```
beat_at    when this run of the entry happened
```

It is deliberately *not* `recordRun()`. That method answers what a run **did** — how many there
were, which kind, when it converged — and all of it is the boot window's evidence, so it is written
inside the window and stops moving when the window closes. Making those keys move again would let a
run after the window change the answer the window gave. `beat_at` is the other fact, and the only
one a post-window no-op can write without touching that answer: *the entry ran*.

It is written **without the lock**, on purpose. The lock serialises flips — the file swap and the
supervisor command — and a beat is not a flip: one `readState()`, one merge, one `writeState()`. A
run that takes the lock to stamp a beat would make every no-op wait behind a real flip, which is the
busiest moment a container has. The cost of not taking it is one lost beat: a flip writing its own
state in the same instant can replace a beat from a copy it read a moment earlier, so the stamp is at
most one cadence stale — and the tolerance below is measured in cadences, so it absorbs that.

And a run the flipper is *told* to make does not stamp it. `forceMode()` — which
`db:pgcat-flip --force-mode` and the reader-window tasks both reach — is a request from outside the
schedule rather than the entry firing, and a schedule being driven by hand is exactly the schedule
whose beat must not be kept alive by the hand.

## The tolerance is the cadence, not a second number

`swrr.pgcat.schedule.stale_after_missed_runs` counts **missed runs** — five by default — and the
bound is that many cadences: five minutes at the shipped one-minute interval, twenty-five at
`interval_minutes: 5`. An installation that slows the entry down therefore slows the watch down with
it and cannot leave the two disagreeing.

A number of seconds was the alternative and it is the wrong shape. A fixed duration has to be raised
by hand every time the cadence is, and the value somebody forgets is the one that pages at 3 a.m.
about a schedule doing exactly what it was configured to do — or, worse in the other direction,
stops noticing a schedule that is slower than the person remembered. Five runs is also the number of
ordinary casualties: a run that loses the cache mutex to the previous one, a container busy for a
minute, a clock adjusted between two runs. Four leaves no room for those; ten lets a stopped
schedule sit for ten minutes while the pooler it maintains is wrong.

Reading the setting is deliberately literal, and the shape of that read is a decision of its own.
`stale_after_missed_runs` is a **number**, not a switch: every spelling of the switch vocabulary
(`'off'`, `'no'`, `false`) is a value this cannot read and therefore the *default* rather than an
accidental zero, because a cast that read `'off'` as `0` would silently switch off the only check
that notices a dead cron. A zero written down *is* zero, and means what the next section says.

## The silences, and why each is reported

A verdict of "fine" and a verdict of "not asked" must never look the same, so the flipper's
`scheduleStatus()` answers with three fields rather than one: `stale` (`true`, `false`, or `null`
when nothing was judged), `not_judged` (the sentence for that `null`), and `applicable` (whether the
question was in scope at all).

| Not judged | Why | Who says so |
|---|---|---|
| The entry is not scheduled | a flipper that is not armed has nothing for a schedule to keep in step, and an installation whose entry is off has said so. Nothing ages a beat that nothing writes | `swrr.pgcat.schedule.enabled` — and `db:doctor`'s `flip schedule` row already owns the choice between the two mechanisms |
| The judgement is switched off | `stale_after_missed_runs = 0`, the documented way to keep this verdict off | `ScheduleBeat::off()`, and `db:doctor` warns on it |
| The container is inside its boot window | a container one minute past boot whose flip has not run yet is a container starting — the line `FlipWindow` already draws, reused here rather than re-argued | `FlipWindow::closed()` |
| No boot time was recorded | a local run, or an image whose entrypoint predates the stamp. Inventing a deadline for a container nobody stamped is how a health endpoint starts failing healthy installations | `FlipWindow::SOURCE_NO_STAMP`, which `toArray()` reports as the window's `source` |

The fourth silence is the one that could have gone the other way, and it is worth saying why it did
not: a container past its window with *no beat at all* is **stale**, not unknown. The entry is
scheduled, the window has closed, and nothing has run it — the same fault as a beat that stopped,
one interval earlier. Reporting it as unknown would leave the incident's shape (a schedule that
never started) as silence, which is the failure this whole mechanism exists to remove.

## What a silence is checked against

`stale` says the entry has stopped, and its sentence names the cause that is nearly always the right
one: nothing is running the schedule. Three other causes leave the same state file behind, and the
verdict cannot see any of them, because none of them is about the file it reads. They are read beside
it — `Uak35\WeightedDbManager\Pgcat\ScheduleCauses` — and each is a **reading** rather than a fourth
verdict: the status and the exit code stay the beat's, and no reading moves them. "The schedule has
stopped" and "a store did not answer once" are different claims, and the second already fails a row of
its own (`db:doctor`'s `store`), so failing this one for it would be one fact reported twice.

| Reading | What it answers | Why the verdict cannot |
|---|---|---|
| the store its runs' mutexes live in | the store `withoutOverlapping()` reaches *before* the entry runs, and whether it answered a read | a store that does not answer throws there, so the beat goes stale while the cron is perfectly healthy — and the throw is inside `schedule:run`, so the entries after it in the same run do not run either |
| how many entries run `db:pgcat-flip` | one carrying the installation's name is this package's entry; one without it is the installation's own; two are two runs a minute | that shape leaves a *fresh* beat — the doubled process is the symptom — and the framework's mutex cannot serialise the pair, because each entry names its own |
| the file the window is measured from | whether it is there, readable and writable, with the mode and owner it was found with | `FlipWindow::bootedAt()` answers `null` to a stamp that is absent *and* to one it cannot read, so an unreadable stamp is a container nobody stamped: the window is not judged and neither is the beat inside it |

Who asks what, and why the four surfaces differ:

| Surface | The store | The other two |
|---|---|---|
| `db:doctor`, the `schedule beat` row | asked, and the answer named | read |
| `db:doctor --connection-only` | **not** asked — the mode exists to be answerable where the store is not there, and the reading says so rather than leaving reachability looking unremarkable | read |
| `db:scheduler-watchdog` | **not** asked — a check that runs every minute answers from the state file and asks nothing that can time out, and the reading names the run that does ask | read |
| `/health/db` | **not** asked — the endpoint answers a monitor on a cadence too, so it takes the same reading and prints the same sentence, naming `db:doctor` as the surface that asks | read |

The endpoint publishes the three under `schedule.causes`; the command publishes them under `causes`.
Both read them through this class and both print its sentences, and `DatabaseHealthControllerTest`
compares the two arrays field for field, so a monitor and a healthcheck cannot drift into two
accounts of one silence.

## The bound, in the runs it is made of

`stale_after_seconds` is one number derived from two settings, and a monitor that receives only the
product can say a beat is late without saying late by how many runs of what cadence — which is the
difference between a schedule that has been down for five minutes and one that has been down for
five runs of a pace somebody deliberately slowed to twenty-five minutes. The command has always
printed both (`missed_runs`, `interval_minutes`); the endpoint did not, so a dashboard could read
`schedule.failed` and be unable to explain the bound that produced it without the configuration.

The `schedule` block carries them now, in the command's order and from the command's two readers —
`ScheduleBeat::missedRuns()` for the count, `FlipSchedule::options()` for the cadence — rather than
from the verdict's own block, which carries neither. They are `null` only where there is no flipper
to read a schedule from, matching the command's evidence for the same absence, and they are present
when nothing is judged: a disarmed entry still has a cadence and a count written down, and the pair
is a fact about what the installation was asked to tolerate rather than about this run's verdict.
Because they are the same two numbers `stale_after_seconds` multiplies, the block holds together —
`missed_runs * interval_minutes * 60` is the bound beside it — and the suite asserts that arithmetic
rather than the three values separately.

An installation whose per-minute entry is not scheduled reads nothing at all, and its schedule is not
even resolved: resolving the schedule is what *registers* this package's entry, so a reading taken for
an installation that never armed it would hand that installation an entry it never asked for. That is
a property of the object rather than an optimisation, and it is why the empty reading list is asserted
where the readings themselves are.

## What was rejected

| Candidate | What it would do | Why it was rejected |
|---|---|---|
| A. A second scheduled task that checks the first one ran | A per-minute entry of its own, watching the flip's | The watchdog would be watching whether *its* entry runs, and a container whose cron is dead would report nothing about either. It also doubles the no-op work a healthy container does every minute |
| B. A timestamp written by the entry into the boot record | Reuse the boot audit's file, which `/health/db` and `db:doctor` already read | That record is per *installation* and its findings are settings; a beat is per *container*, and the question "is this one's schedule running" has no meaning in a shared file. It would also have to be written under the record's lock, which is taken on every boot by every process |
| C. A duration rather than a count of runs | `stale_after_seconds`, default 300 | It has to be kept in step with the cadence by hand, and the pair that drifts is the pair nobody notices until it pages at 3 a.m. — see above |
| D. Only the health endpoint | Let `/health/db` return `503` and be the whole answer | The healthcheck that matters runs *inside* the container, where calling the endpoint means knowing the port, making an HTTP request to the application's own server, and parsing a JSON body — for a fact an exit code carries. It also means a container with no HTTP server on the health port cannot be checked at all |
| E. Fail on `stale_after_missed_runs = 0` always | No flag: a switched-off watch is a failing healthcheck | It makes a documented setting impossible to use deliberately. The preflight already treats a switched-off check as a *warning* that `--strict` fails, and a container permanently unhealthy for a choice its operator made on purpose is the noise that teaches people to ignore the check entirely |
| F. Stamp the beat only under `schedule:run` | Tell a scheduled run from a hand run | It would make the beat depend on a Laravel internal rather than on the command having run, and it would report a schedule that is being driven by hand as stopped — while the operator is there, reading the output |
| G. Judge the schedule from `schedule:list` | Ask whether the entry is registered | That answers what is *configured* to run, which `db:doctor`'s `flip schedule` row already asks, and the failure this exists for is a schedule configured exactly as expected and not firing |
| H. Report a stale beat from the boot audit | File it as a finding, so the audit carries it | Findings are per key and per scope, and this one is about one container's clock at one moment: it would either be re-filed on every boot or be permanently standing with a date that has to be re-derived. The window's own verdict is not filed there either, for the same reason |
| I. Ask the store in the healthcheck too | One reading, taken by every surface, so a container's own check could say whether the store answers | It puts a cache's connect timeout on a check that runs every minute and whose whole design is to answer from the state file — a store that hangs would delay the healthcheck, and a container would be restarted for its cache. The name is reported there instead, the reading says which run asks it, and `db:doctor` is where the answer is taken |
| J. Report the store's reachability from the package's own store probe | Reuse the PING `db:doctor`'s `store` row already takes, rather than reading through the schedule's mutex | The two are not the same store: an installation calls `Schedule::useCache('file')` to keep its scheduled tasks' mutexes somewhere other than the application's default, which is exactly the configuration this reading exists for. Reading the default would name a store the entry never touches |

## Where it is judged, and where it is not

One verdict, three surfaces, and none of them computes it:

| Surface | What it does with the verdict | Why it is that surface's |
|---|---|---|
| `PgcatConfigFlipper::scheduleStatus()` | answers it | The verdict belongs beside `windowStatus()` and `readerWindowVerdict()`, which are the two halves of the same question about a container's life |
| `/health/db`, under `schedule` | degrades the payload and returns `503` | The monitor **outside** the container, which already polls this route and reads one field for its rule |
| `db:doctor`, the `schedule beat` row | fails the row, and `--strict` fails a release on the warning | The preflight **before** a deploy, which already renders one row per way a container can be the wrong shape |
| `db:scheduler-watchdog` | exits `0` or `1`, and writes the same block under `--json` | The healthcheck **inside** the container, which has no HTTP client and no preflight, and which must be able to ask every minute without opening a socket, taking a lock or writing a file |

`db:doctor`'s row is not the row above it. `flip schedule` asks what is *configured* to move the
pooler and which of the two mechanisms answers a boundary; `schedule beat` asks whether that
mechanism has been running. The entry can be registered by the provider, listed by `schedule:list`,
armed exactly as documented, and not firing — nothing in the configuration changed and no other row
is wrong.

## Known limitations

- **A hand run stamps the beat.** `php artisan db:pgcat-flip` reaches the same method the entry
  does, so an operator repairing the pooler by hand keeps the beat alive while the schedule stays
  dead. It is not worth a second marker to tell them apart — the operator is *there*, reading the
  output — and the alternative (candidate F) makes the beat depend on a Laravel internal.
- **One beat can be lost to a concurrent write**, because the stamp is not taken under the lock. The
  cost is one cadence of age, which a tolerance measured in cadences absorbs.
- **The clock is the container's.** A beat stamped by a container whose clock is wrong is judged
  against this one's, and nothing here can tell a stopped schedule from a moved clock. A stamp in
  the *future* is read as an age of zero rather than a negative one, because a negative age would
  make every comparison pass — a schedule reported as fresh because its clock was readjusted is a
  worse failure than one reported as stopped.
- **`stale_after_missed_runs = 0` is a hole with a bell on it.** A container whose operator has
  switched the watch off exits `0` on every surface by default. `db:doctor --strict` and
  `db:scheduler-watchdog --strict` both refuse it, and a healthcheck that must never run unwatched
  passes `--strict` — but the default is the default, and the setting exists to be usable.

## What would change this decision

- **If the entry stopped being a no-op after the window.** The beat exists because the runs that
  happen after the window record nothing else. A flip that kept writing something every minute would
  have that fact already, and the beat would be a second copy of it.
- **If a supervisor managed the schedule.** A container whose cron is a supervised program already
  has a liveness signal — `supervisorctl status` — and the pooler's own rows read it. The schedule's
  absence is a fault here because nothing else in the container watches for it.
- **If Laravel exposed a per-task last-run record.** The framework knows when a scheduled event last
  fired, in memory, for the process that fired it. A durable, queryable version of that would make
  `beat_at` a workaround rather than a design.

## Files

| File | Role |
|---|---|
| `src/Pgcat/ScheduleBeat.php` | the tolerance, the four silences and the two sentences — the one place a beat is judged |
| `src/Pgcat/PgcatConfigFlipper.php` | stamps `beat_at` on every run (`beat()`) and publishes the verdict (`scheduleStatus()`) |
| `src/Pgcat/ScheduleCauses.php` | the three readings beside the verdict — what a silence is checked against, and which run asks for each |
| `src/Console/Commands/DbSchedulerWatchdog.php` | the healthcheck: one route table, `--strict`, `--json`, and no work |
| `src/Http/Controllers/DatabaseHealthController.php` | the `schedule` block — the beat, the bound and the two settings it is made of — and the third `failed` that reaches `$allHealthy` |
| `src/Console/Commands/DbDoctor.php` | the `schedule beat` row |
| `config/db-manager.php` | `swrr.pgcat.schedule.stale_after_missed_runs`, and why it is a count of runs |
| `tests/Unit/Console/DbSchedulerWatchdogTest.php` | the matrix — routes against the flag and the two channels — the sentences each route carries, and the readings beside them |
| `tests/Unit/Pgcat/ScheduleCausesTest.php` | the three readings as states: an entry on a real schedule, a cache binding that answers or refuses, a stamp with a mode the platform respects |
| `docs/documented-exit-codes.md` | the command's cells and documented cases, bound the way the other five commands' are |
