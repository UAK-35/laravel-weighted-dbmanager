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
anything is written, and pinned by the tests named at the end.

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
the *provider* — its four assembly methods happen not to produce the same key twice — turned
into a guarantee the repository did not hold. The provider is the thing that can change; the
audit is the thing that has to survive the change.

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
reader lists refused, a primary store that is not the one running, and a formula with no
implementation — and asserts on the keys that were logged: five findings, five keys, the
exact set, and no collision line among the messages.

A configuration the suite never walks is not covered by that test, and this is the honest
limit of a test-based half: the guard makes such a case loud in production (both sentences,
plus the defect line) rather than silent, and the test makes the cases the package knows
about fail before release.

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
| a record another boot wrote mid-boot is named rather than replaced in silence | `BootAuditTest::test_an_entry_another_boot_recorded_mid_boot_is_named_rather_than_replaced_in_silence` |
| an ordinary write says nothing about a lost update | `BootAuditTest::test_a_record_nobody_else_wrote_writes_without_a_lost_update_line` |

Mutations this decision has been checked against: dropping the collision line to `debug`
level (one test fails on the level), folding before logging — the pre-fix shape — (three
tests fail, the first on a sentence that was never logged), keeping the quieter half of a
pair (three tests fail, in both arrival orders), and letting a tie keep the last (one test
fails).

## Known limitations

- **One of the two sentences is still not remembered.** The record keeps one entry per key,
  so a collision loses a sentence from the record — it stays in the log, which is where the
  finding itself was written, and in the payload's list for that key there is only the kept
  one. That is the price of the key being the identity.
- **The collision line cannot be a page.** It is logged and nothing else: no severity in the
  payload, no row in `db:doctor`. A dashboard watching `severity` will not see a package
  defect. What it *will* see is the surviving finding, which is not lost.
- **The record is one file, so the same loss exists across boots.** Everything above is about
  a collision *within* one boot. A boot also replaces the whole file, and two boots in flight
  together each write from the copy they read, so the later one discards entries the earlier
  recorded and nothing has read. The rename that makes the write atomic protects the
  *reader*; the writer is the same hazard one level up. It is now reported rather than
  prevented — `BootAudit::reportLostUpdate()` names the entries about to go — for the reason
  the store-probe decision gave: a lock left behind by a killed worker would stop the record
  being written at all, and losing coverage beats that.
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
- **If a third level were added.** Then "keep the louder" needs an order and the line needs to
  name the ranks it compared rather than "the louder of the two".
- **If host applications assembled findings.** Today only the provider builds the list, inside
  one file with four methods. A public extension point would need the same uniqueness stated
  as part of its contract, because a host that emitted a key twice would be relying on this
  line to notice.

## Files

| File | Role |
|---|---|
| `src/Support/BootAudit.php` | `report()` calls `fold()`, which logs every finding and names a shared key |
| `src/Support/BootAuditFinding.php` | the value being folded: key, warning, resolution, context, level |
| `src/Providers/WeightedDatabaseServiceProvider.php` | the four methods whose lists are spread into one boot's findings |
| `tests/Unit/Support/BootAuditTest.php` | the guard: both sentences logged, the collision named, which sentence the record keeps |
| `tests/Unit/Weighted/WeightedDatabaseServiceProviderTest.php` | the provider half: no two findings in the richest configuration share a key |
| `docs/boot-audit-surfaces.md` | what happens to a finding after it is remembered |
| `docs/reader-windows-refusal.md` | how many findings one boot reports, and why two causes are one sentence |
