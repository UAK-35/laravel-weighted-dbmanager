# What does the audit do when two findings in one boot claim the same key?

A design record for `BootAudit::fold()`, and for the test that keeps the provider's own
finding list free of shared keys.

A boot hands its findings to `BootAudit::report()` as a list and the record keeps them as a
map: one entry per finding key, because that key is what makes "still standing" and
"resolved" comparable across the processes that write the record. A list with two entries
under one key therefore cannot be stored as it was given, and the code that resolved that —
fold into a map, then log the survivors — resolved it the wrong way round. This document
records what a shared key does now, which alternatives were considered, and why the others
were not taken.

Everything below is implemented in `Support\BootAudit::fold()`, called by `report()` before
anything is written, and pinned by the tests named at the end. The same map has a second
hazard that is not a collision at all — one record, several boots writing it — and
*One record, several boots* below is where that is decided and why this document is the one
that holds it.

---

## The answer in one line

**Log every finding the boot produced, and name a shared key as the defect it is.** The fold
happens while logging, not before it, so nothing can be dropped unread; a second finding
under a taken key logs an `error` line saying which sentence the record keeps — the louder of
the two, or the first when they are equally loud — and the boot carries on.

## Why this needed deciding at all

The record is a map, and a map is the reason any of this is subtle. `report()` receives
`list<BootAuditFinding>`, and every entry has to survive the trip to disk as its own key, or
`report()` on the next boot cannot tell "this key is still failing" from "this key was fixed
before I started looking". That is the whole mechanism behind remembering a finding instead
of repeating it forever: same key, still reported → the warning is logged again; same key,
not reported and checked → the resolution is logged once.

Folding a list into a map is writable in one line:

```php
foreach ($findings as $finding) {
    $current[$finding->key] = $finding;
}
```

and that line is the defect. It does not fail, does not warn, does not lose a *key* — it
loses the earlier finding's *sentence*, and it loses it before the log loop ever sees the
list. An installation reporting "the pool is never used" because no window can be entered,
and "the pool is never used" because the formula is unknown, would have logged one of the
two; the other would exist nowhere at all, not even in the record, while the payload still
looked complete.

The package noticed the shape of the hazard in words before it was guarded, twice, in
comments and documentation: `readerFallbackFindings()` folds its two causes into one
sentence rather than emit a second finding that "would overwrite this one", and the reader
audit's changelog entry called a finding key "unique by construction". That was a claim about
the *provider* — the four assembly methods it had then happened not to produce the same key
twice, and it spreads eight today — turned into a guarantee the repository did not hold. The
provider is the thing that can change; the audit is the thing that has to survive the change.

So the decision has two halves, and they are not the same half:

1. **What the audit does when it happens.** A shared key must not cost a sentence its log
   line, and it must not be a fact only a reader of the source could know.
2. **What keeps it from happening.** The provider's assembly, pinned for the richest
   configuration the package can be in, so a branch that reuses a key fails a test rather
   than a boot.

## The candidates

| | candidate | cost | verdict |
|---|---|---|---|
| A | throw, so a shared key cannot boot | an installer's application goes down over a diagnostic the audit is explicitly not allowed to stop | **rejected** — a diagnostic never takes the process down |
| B | fold first, log the survivors | the replaced sentence is never logged, and the payload looks complete | **rejected** — this was the state, and it is the failure being fixed |
| C | let the second overwrite, and log a line about it | the overwritten sentence is still never logged; the record carries a sentence whose age belongs to the key | **rejected** — the line describes a loss rather than preventing it |
| D | log every finding, name the collision, keep the louder in the record | one finding's sentence is not remembered, though it is logged | **chosen** |
| E | merge the two findings into one entry | needs one resolution sentence for two states, and the recorded age is then the merger's | **rejected** |
| F | make the collision impossible by construction — a keyed map, or a value object that refuses a second `add()` | moves the overwrite earlier: the map collides before anything is logged | **rejected** — the timing is the defect |
| G | remember the collision as a finding of its own | a state an operator cannot repair stands forever, or resolves on a deploy that fixed nothing | **rejected** |
| H | report the collision in the health payload only | silent in the log the finding itself is written to; same reason as G | **rejected** |
| I | guard it in tests only | a configuration no test walks still loses a sentence in production | **rejected as the only guard** — kept as the second half |

### A — throw, so a shared key cannot boot

The strongest reading of "cannot": make it impossible. PHP has no compile-time check for
this, so it means throwing at the collision.

It is the one candidate that contradicts a property the audit is built around. The whole
reason `reportBootAudit()` catches `Throwable` and logs "the boot self-audit could not run"
rather than rethrowing is that a diagnostic must never be what stops an application from
booting. A shared key is a defect in the package — the operator cannot fix it, did not cause
it, and would experience the throw as their site being down. Failing a test is the loud
signal for a defect; taking production down is not.

### B — fold first, log the survivors

This is what the code did. It reads naturally — build the map the record needs, then log it —
and it is wrong in a way nothing surfaces: the log line count is correct for the map, the
record is well-formed, the payload is well-formed, and the absence is only visible to someone
who knows there should have been another line.

Two costs, not one. The replaced sentence is lost. And `first_reported_at` is looked up by
key, so the surviving sentence inherits the replaced finding's age: the row that reports "no
window can be entered, unresolved since 2026-09-20" would be dating a sentence that has been
true since this morning. The age belongs to the key, and the key is what the two findings
were fighting over.

### C — let the second overwrite, and log a line about it

The cheap fix: keep the fold, and add `Log::error('two findings share a key …')` in the
overwrite branch. It makes the defect visible and leaves the loss in place — the sentence
that was replaced still exists nowhere, so an operator reading the log learns that something
was lost and not what it said. A diagnostic that reports its own hole is worse than no
diagnostic, because it looks like coverage.

### D — log every finding, name the collision, keep the louder in the record

Chosen. The fold becomes the loop that logs, so the order is "log this finding, then decide
how it relates to the key":

- Every finding is logged, in the order it was handed in, at its own level, with its own
  context and the key's recorded age. Nothing depends on the fold having decided anything.
- A shared key logs one more line, at `error` — a problem reported by nobody is louder than
  either sentence — naming the key, the two levels, how many findings the boot produced, and
  which of the two sentences the record keeps.
- The record keeps the louder: an `error` finding replaces a `warning` under the same key,
  whichever order they arrive in. `standingSummary()` counts exactly that level, so a monitor
  that pages on a refused value cannot be handed the quieter half of a pair. Equally loud
  findings keep the first, which makes the rule total: level first, arrival order as the
  tiebreak.

The collision is logged and *not* remembered. The record's entries are states an operator
repairs — each one carries the sentence a later boot logs when it stops applying — and a
package defect has no such sentence: it resolves on the deploy that fixes the package, which
would be logged as though the installation had fixed itself. It stays a log line, which is
where a defect belongs.

### E — merge the two findings into one entry

Tempting, because the provider already does exactly this by hand for the two causes of
`always_writer`: one key, one sentence naming both. The difference is that both causes are
known at the point where that sentence is written, so it can state a single resolution and a
single claim. A merger inside `report()` has neither: it would concatenate two resolutions
into a sentence nobody wrote, and the recorded age would be the merger's rather than either
finding's. The provider folding two causes it understands is a design; the audit folding two
it does not is a fallback, and fallbacks should be visible rather than tidy.

### F — make it impossible by construction

The appealing version: have the provider hand over `array<string, BootAuditFinding>` (PHP
discards a duplicate key silently — worse, not better), or hand over a small value object
whose `add()` records or throws on a repeat.

Both put the collision check *before* the log, which is the hazard. Whatever the object then
does — throw, or keep the first — the second sentence has been dropped before any line was
written. A guard belongs where the loss would happen, and the loss happens at the fold.

The provider-side half is still kept, as a test rather than as a type. See "The second half"
below.

### G and H — make the collision itself a state

Remember the collision under its own key, or publish it in `/health/db`'s `audit` block, and
it becomes something an operator can see — a page, in the vocabulary `standingSummary()`
uses.

It is not a state, it is a defect. The record's lifecycle is: reported while it stands, closed
out by the boot that checks the key clean, resolution logged once. A collision has no key of
its own to be checked clean, so it would either stand forever on a dashboard (a permanent
page nobody can clear) or resolve on the next deploy regardless of whether anything was
fixed — a resolution line that claims a fix that did not happen, which is the one thing the
record is careful never to say.

## The chosen mechanism, in full

```php
// As this change wrote it. The two `Log::` calls now go through `logContext()`, which
// adds the level the line is written at to the context as `severity` — see
// [boot-audit-log-severity.md](boot-audit-log-severity.md).
private function fold(array $findings, array $record, string $now): array
{
    $current = [];

    foreach ($findings as $finding) {
        $key = $finding->key;

        Log::log($finding->level, '[WeightedDB] '.$finding->warning, [
            'finding' => $key,
            'first_reported_at' => $record['findings'][$key]['first_reported_at'] ?? $now,
        ] + $finding->context);

        $kept = $current[$key] ?? null;

        if ($kept === null) {
            $current[$key] = $finding;

            continue;
        }

        $equallyLoud = $kept->level === $finding->level;

        if (!$equallyLoud && $finding->level === self::SEVERITY_ERROR) {
            $current[$key] = $finding;
        }

        Log::error(sprintf(
            '[WeightedDB] Two findings this boot share the key "%s", so they are one entry in the record: '
            .'it keeps the %s. Both sentences were logged above, and neither setting is wrong — one key is one '
            .'finding, so this is two branches of the package producing the same key, which is a defect in the package.',
            $key,
            $equallyLoud ? 'first of the two' : 'louder of the two',
        ), [
            'finding' => $key,
            'levels' => [$kept->level, $finding->level],
            'findings_in_boot' => count($findings),
        ]);
    }

    return $current;
}
```

`report()` calls it once, before it walks the record to close findings out, so "still
standing" is decided from the same map that was logged. The rest of `report()` is unchanged:
the resolutions, the carried-over keys the boot did not check, and the write.

The line carries no `resolved`-style context and no key of its own beyond the colliding one,
so a grep for a finding key shows the finding's own lines plus this one when a defect put it
there. That is deliberate: the collision belongs to that key's entry, not to a key of its
own.

### The second half: the provider's list stays free of shared keys

Today no two branches produce the same key, and that is worth pinning rather than assuming,
because the fold is no longer a silent place for a mistake to land:
`WeightedDatabaseServiceProviderTest::test_no_two_findings_of_one_boot_share_a_key` boots the
package in the richest misconfiguration it can have — a pgcat gate that cannot act, both
reader lists refused, all three replica settings unreadable, a primary store that is not the
one running, and a formula with no implementation — and asserts on the keys that were logged:
eight findings, eight keys, the exact set, and no collision line among the messages.

A configuration the suite never walks is not covered by that test, and this is the honest
limit of a test-based half: the guard makes such a case loud in production (both sentences,
plus the defect line) rather than silent, and the test makes the cases the package knows
about fail before release.

## One record, several boots

Everything above is a collision *within* one boot. The record has a second hazard one level
up, and it is not about a shared key at all: a boot decides what to remember from the copy it
read near its start — the store probe between the two is a network call, so the window can be a
connect timeout wide — and then replaces the whole file. The boots that write one
installation's record are several processes: two of them in flight together write from the
same starting point, and until this decision the later one discarded whatever the earlier had
recorded — an entry nothing had taken out of the record, dropped by a write that never knew it
was there. The rename that makes the write atomic protects the *reader*; the writer was the
same hazard one level up.

Two mechanisms close it, and it needs both.

**The write re-reads the record as it is about to be replaced, and merges.** The keys this boot
produced are written over whatever is there. An entry this boot evaluated and found clean is
removed — but only while it is still the entry this boot read: one that changed under this boot
while it was about to clear that key is another boot's report, and it is kept, because a boot
that has just logged a setting failing is not contradicted by a copy read earlier that was
about to close it out. Every other key is taken as it is on disk at the write, which is what
"not looked at this boot" has always meant — the difference is that it is now read there rather
than from the copy this boot started with. The probe stamp is merged for its own reason: the
later of the two is what the interval is measured from, so a merge that kept this boot's older
stamp would buy the installation one extra connect timeout.

**The read-merge-write is taken under an exclusive advisory lock.** The merge alone narrows the
window; it does not close it, because two boots can still merge from the same copy and write
over each other a moment later. `persist()` therefore takes `flock(LOCK_EX)` on a *companion*
file beside the record — a companion rather than the record itself, because the write replaces
the record by renaming a temp over it, so a descriptor opened on the record would be holding
the file nothing will ever open again, and two boots either side of a rename would each hold
"the record" while excluding nothing.

*Which* lock is the whole of the answer to the store-probe decision's objection, and it is the
reason there is a lock at all. The objection was that a lock left behind by a killed worker
would stop the record from ever being written again. That is true of a lock whose *existence*
is the lock — a sentinel file, or a `pid` written into one — and it is not true of this one:
`flock` lives on an open descriptor, and the kernel drops it when the process ends however it
ends. What a worker killed mid-write leaves on disk is an empty file beside the record, which
is not a lock, says nothing and stops nothing. A boot does not wait for it indefinitely either:
`LOCK_ATTEMPTS` short attempts, and then the merge goes ahead from the freshest read it can
take and says it could not serialise, because recording the settings this boot checked matters
more than recording them alone.

**What says something, and what does not.** The safe path is the quiet one: a write that merges
an entry it never saw, and a write nobody else touched, both log nothing, because an
installation that named a race on every boot would be crying wolf about its own writes. Two
states still speak, both at `error`, and both carry what triage needs in their context: the
keys that were kept against a clean verdict, and the lock with the attempts and the time they
took.

The remaining ground is a record that cannot be written at all. A directory that accepts no
file is answered the way the store probe answers it — none of this runs: the merge is not
attempted because its result has nowhere to go, and the lock is not asked for eight times over
a filesystem that will not let this process open it. A read-only deploy pays a stat, and the
finding the boot logged is the whole of what an operator gets.

### The two windows, measured rather than argued

The lock is worth having only if it covers the write and not the boot, and neither window can be
shown by a test that runs in one process — a lock between two descriptors of the same process
collides whether or not the writers share a path. So `BootAuditConcurrencyTest` runs four real
boots of one record, as processes, all released at the same instant, each timing around the
*shipped* lock closures: the read of the record to the rename that replaced it, and the part of
that the lock is held for. What the test asserts is the **relationship**, never a number — the
hold stays far below the boot stage it sits between, so a lock taken at boot rather than at the
write fails the run instead of being a sentence in this document that nobody can check. The
numbers themselves belong to the machine: on the one this was written on, four boots on one
record hold it for 3–6ms and their own windows run from 9ms, when there is nothing between the
read and the write, to 424–488ms when a boot stage of 400ms stands in for the store probe.

Two regimes, because they fail for different reasons. A stage between the read and the write is
what a *stale copy* would otherwise be written back over, which is the merge's half; with no
stage at all every boot is inside its critical section at the same instant, so the reads all
happen before any of the writes and a merely *narrow* read-merge-write still collapses to the
last writer — that run is the lock's half, and it is the one a merge alone cannot pass.

## Tests that pin the rules

| Rule | Test |
|---|---|
| a shared key costs no log line | `BootAuditTest::test_a_finding_that_shares_a_key_is_logged_rather_than_replaced` |
| the collision is logged at `error`, names the key, the levels and the pair it came from | (same test — the third record, its level, its context) |
| the collision is not remembered as a state | (same test — one standing finding, and it is the finding's) |
| the louder of two findings is the one the record keeps, in either order | `BootAuditTest::test_the_record_keeps_the_louder_of_two_findings_that_share_a_key` (two data sets) |
| equally loud findings keep the first, and the line says so | `BootAuditTest::test_two_findings_of_the_same_level_keep_the_first_of_the_pair` |
| the boot survives it | all three: `report()` returns, the record is written, nothing is rethrown |
| the provider's own list has no shared key | `WeightedDatabaseServiceProviderTest::test_no_two_findings_of_one_boot_share_a_key` |
| a record another boot wrote mid-boot is *merged*, not replaced, age and all | `BootAuditTest::test_an_entry_another_boot_recorded_mid_boot_survives_this_boots_write` |
| an entry another boot substantiated is kept against this boot's clean verdict, and no resolution is logged for it | `BootAuditTest::test_an_entry_another_boot_substantiated_is_kept_against_this_boots_clean_verdict` |
| the record is re-read *after* the lock is taken, not before | `BootAuditTest::test_the_record_is_re_read_after_the_lock_is_taken_rather_than_before` |
| a lock file a killed worker left behind stops nothing | `BootAuditTest::test_a_lock_file_left_behind_by_a_killed_worker_stops_nothing` |
| the write takes a real lock on a companion file and leaves it free for the next boot | `BootAuditTest::test_the_record_is_written_under_a_real_lock_and_left_free_for_the_next_boot` |
| a lock this boot cannot take is reported, with the attempts and the wait, and the write still lands | `BootAuditTest::test_a_lock_this_boot_cannot_take_is_reported_and_the_write_still_lands` |
| a probe another boot recorded is not forgotten by this boot's write | `BootAuditTest::test_a_probe_another_boot_recorded_is_not_forgotten_by_this_boots_write` |
| an ordinary write says nothing about the race | `BootAuditTest::test_a_record_nobody_else_wrote_writes_without_a_line_about_the_race` |
| nothing is read, merged or locked where the record cannot be written at all | `WeightedDatabaseServiceProviderTest::test_an_unwritable_record_directory_never_stops_the_boot` |
| a boot stage between reading the record and writing it costs no entry, across processes | `BootAuditConcurrencyTest::test_a_boot_stage_between_reading_the_record_and_writing_it_costs_no_entry` |
| boots that read and write at the same instant still leave every key, and hold the record for the write rather than the stage | `BootAuditConcurrencyTest::test_boots_that_read_and_write_at_the_same_instant_still_leave_every_key` |

Mutations this decision has been checked against: dropping the collision line to `debug`
level (one test fails on the level), folding before logging — the pre-fix shape — (three
tests fail, the first on a sentence that was never logged), keeping the quieter half of a
pair (three tests fail, in both arrival orders), and letting a tie keep the last (one test
fails).

Mutations the record's write has been checked against: writing this boot's own copy instead
of the merged one (four tests fail, the first on the entry that went missing), reading the
file before taking the lock rather than inside it (one test fails, on the entry the other
boot recorded under the lock), taking the probe stamp from this boot's copy rather than the
later of the two (one test fails, on the stamp), and giving up on the write when the lock
cannot be taken (one test fails, on the finding that was never recorded).

Run against the harness, the two mutations that separate the mechanisms fail the two halves
that are each other's: writing this boot's own copy instead of the merged one loses three of
the four keys **in silence** in both regimes, which is the defect this document is about
reproduced across processes, and giving each boot a lock path of its own — a lock the writers
do not share — fails both runs on the boot that wrote its key and could not find it, because
the reads all land before the writes whatever the stage is.

## Known limitations

- **One of the two sentences is still not remembered.** The record keeps one entry per key,
  so a collision loses a sentence from the record — it stays in the log, which is where the
  finding itself was written, and in the payload's list for that key there is only the kept
  one. That is the price of the key being the identity.
- **The collision line cannot be a page.** It is logged and nothing else: no severity in the
  payload, no row in `db:doctor`. A dashboard watching `severity` will not see a package
  defect. What it *will* see is the surviving finding, which is not lost.
- **Two boots in flight can disagree about a key, and the record keeps the finding.** A clean
  verdict is about the copy the boot read, and configuration is read once per process, so two
  boots can genuinely be running different configurations — one saying a key is fine is not
  evidence about the other. The entry stands until a boot evaluates the key cleanly with
  nothing re-reporting it in between, and the write names the keys it kept so that the silence
  of a resolution line is not the only signal.
- **A write that could not take the lock is the one write that can still lose an entry.** The
  merge happens anyway, from the freshest read it can take, and the line says so. Refusing to
  write would leave the settings this boot checked unrecorded over a lock the operator may not
  be able to fix from where the line is read, which is the same trade the store-probe decision
  made in the other direction.
- **The record survives a killed worker, not a full disk.** What is left behind is the record
  as it was before that write — the merge is not a journal, so an entry a dying boot had read
  but not yet written is not recovered from anywhere.
- **"Louder" is a boolean, not an order.** The vocabulary has two levels, so `error` over
  `warning` is total. A third level would need a rank rather than an `if`, and the rule would
  have to be stated as an order.
- **The provider test is as wide as its configuration.** A branch that reuses a key on a path
  no test configures is caught in production logs — with both sentences and the defect line —
  rather than by the suite.
- **`first_reported_at` is still the key's.** A kept finding whose sentence changed under the
  same key inherits the key's age. That is the intended meaning of the field (when did this
  key start failing), and it is why a collision is dated a second time in the defect line's
  own context rather than by moving the age.

## What would change this decision

- **If a finding key stopped being the identity.** A record that stored findings as a list
  with their own timestamps — no cross-boot keying — would have no collision to resolve:
  both findings would simply be two entries. That trades away the resolved/unresolved
  mechanism, so it is a different audit rather than a different guard.
- **If the audit were allowed to stop a boot.** Then candidate A comes back on the table, and
  so does every other "fail loudly and early" mechanism the store probe doc considered. That
  is one decision for the whole audit, not one per finding.
- **If the record were one file per boot.** Then there would be no shared copy to merge and no
  lock to take: each boot would write its own and a reader would union them. That moves the
  merge to every surface that reads the record — `/health/db`, `db:replica-status`,
  `db:doctor` — and makes "what stands" a question about a directory rather than about a file,
  so it is a different reading of the record rather than a different write of it.
- **If a third level were added.** Then "keep the louder" needs an order and the line needs to
  name the ranks it compared rather than "the louder of the two".
- **If host applications assembled findings.** Today only the provider builds the list, inside
  one file with eight methods. A public extension point would need the same uniqueness stated
  as part of its contract, because a host that emitted a key twice would be relying on this
  line to notice.

## Files

| File | Role |
|---|---|
| `src/Support/BootAudit.php` | `report()` calls `fold()`, which logs every finding and names a shared key; `persist()` merges the record and takes the lock |
| `src/Support/BootAuditFinding.php` | the value being folded: key, warning, resolution, context, level |
| `src/Providers/WeightedDatabaseServiceProvider.php` | the eight methods whose lists are spread into one boot's findings |
| `tests/Unit/Support/BootAuditTest.php` | the guard: both sentences logged, the collision named, which sentence the record keeps, and the merge and the lock in one process |
| `tests/Unit/Support/BootAuditConcurrencyTest.php` | the guard across processes: real boots on one record, every key surviving, and the two windows measured |
| `tests/Support/boot-audit-child.php` | one boot as a child process — the merge and the lock with nothing shared but the filesystem |
| `tests/Unit/Weighted/WeightedDatabaseServiceProviderTest.php` | the provider half: no two findings in the richest configuration share a key |
| `docs/boot-audit-surfaces.md` | what happens to a finding after it is remembered |
| `docs/reader-windows-refusal.md` | how many findings one boot reports, and why two causes are one sentence |
