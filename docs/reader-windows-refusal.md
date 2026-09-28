# What should the package do with a reader window that is not a window?

A design record for malformed `swrr.reader_windows` and `swrr.reader_days` values.

`swrr.reader_windows` says which hours may use the weighted replica pool. What the
resolver reads is one array per window — `['start' => '10:00:00', 'end' => '14:20:00']`
— and what a person writes is a flat string, `'10:00-14:20'`. That string is not a
window. Until this decision, every entry that was not an array was dropped without a
word, and dropping all of them left the resolver reading an empty list, which is its
documented *permissive* mode: reads used the replica pool at every hour, the exact
opposite of the fallback the setting was describing. This document records what the
package does instead, which alternatives were considered, and why they were not taken.

Everything below is implemented in `Support\ReaderWindows`,
`WeightedDatabaseServiceProvider::readerFallbackFindings()` and
`DbDoctor::readerWindows()`. The rules are pinned by the tests named at the end.

---

## The answer in one line

**Refuse it, loudly, and keep serving.** An entry that is not an array never becomes a
window, is named in a log line at `error` level (finding key
`swrr.reader_windows.refused`), and becomes a `FAIL` row in `db:doctor` so `--strict`
fails a deploy. The application still boots and answers requests.

The same rule covers the setting one key over: a `swrr.reader_days` written as one
comma-separated string (`'1,2,3'`) is refused under `swrr.reader_days.refused`, for the
same reason and with the same consequences.

---

## Why this needed deciding at all

Three facts, each of which rules out an otherwise obvious answer.

### 1. The flat string is how the setting is written by hand

Both spellings are in the wild for the same intent, and only one of them is a window:

```php
'reader_windows' => [
    '10:00-14:20',                                  // what people write
    ['start' => '17:00:00', 'end' => '20:30:00'],    // what the resolver reads
],
```

The resolver's shape is not arbitrary — a window has two independent bounds, each
optional, each defaulting to a whole day — but a range written as one string is
shorter, and it is what an operator types when editing `.env`-backed config at 2 a.m.
A package that only understands the array form has to decide what to do about the
other one, whether or not it says so.

### 2. Dropping it was silent, and the silence inverted the setting

An empty `reader_windows` means "permissive" on purpose — it is the documented opt-out
for installations that want SWRR to apply at every hour. So an entry that cannot be
read is not a setting that does nothing; it is a setting that does *the opposite of
what it says*:

| `swrr.reader_windows`                | Resolver mode | Reads                              |
|--------------------------------------|---------------|------------------------------------|
| `[['start' => '10:00:00', 'end' => '14:20:00']]` | strict | pool inside the window, writer outside |
| `['10:00-14:20']` (dropped, silently) | permissive   | pool at every hour, all week       |
| unset (on purpose)                   | permissive   | pool at every hour, all week       |

The last two rows are the same state reached two ways, one of them an accident. Nothing
distinguishes them afterwards: routing is identical, the health surfaces report a
healthy strict-mode-capable resolver, and the only way to discover the mistake is to
notice that night-time reads are hitting replicas.

### 3. It is input, not a setting that merely cannot act

The other reader findings describe values the package reads and acts on, which happen
to describe nothing that can occur: days outside ISO-8601, a window whose start is not
before its end. Those are *understood* values, and a warning is the honest level for
them. A string where an array is required is not understood at all — it is input the
package would have to guess at. That is a different claim, and the audit already has a
way to make it: `BootAuditFinding::$level`.

---

## The candidates

### A — interpret the flat string

Split on the separator, and read `'10:00-14:20'` as a window.

Tempting, and wrong for the same reason parsing any ambiguous hand-written form is
wrong here: `'10:00-14:20'` is unambiguous, but the family it belongs to is not.
`'10:00 - 14:20'`, `'10:00:00~14:20'`, `'10:00,14:20'`, `'10:00'` (a start, with an
open end?), `'14:20-10:00'` (an overnight window, which this resolver cannot express —
see candidate B). Each guess is a silent decision about routing that the operator did
not make and cannot see. The package would be trading one silent inversion for a
family of silent interpretations.

### B — repair the entry that "clearly" meant something

Accept a subset — say, only `HH:MM-HH:MM` with `start < end` — and reject the rest.

Better than A, but the subset boundary is arbitrary and the rejected remainder is
exactly the case that needs the loudest report, so B still needs everything below to
say no. Repairing also has a real cost: the corrected value is not what the operator
wrote, so the next person to read the config file sees one thing and gets another.
Reading is not the place to edit.

One half of B survives, separated from the half that made it wrong: `db:doctor` prints
the accepted spelling as a *suggestion* below the failing row — see "The repair, printed
and not applied" — so the operator can paste it. The package still refuses the value, still
logs it at `error` level and still fails the row; what changed is that the sentence an
operator reads no longer requires them to compose the fix themselves.

### C — keep dropping it, silently

The status quo, and the defect. Cheap, and the only surface that ever contradicted it
was routing behaviour nobody watches per hour. Rejected.

### D — drop it, and warn

Keep the drop, add a `warning` finding and a `db:doctor` row.

This is close to the chosen answer, and it is what the *other* reader findings do. It
fails on one point: a warning describes a value the package understood and used. Here
nothing is used — the value is discarded — so a warning understates what happened, and
under `db:doctor` without `--strict` it would not stop a deploy either. A refused value
that a deploy gate lets through is a refusal in name only.

### E — refuse it, at `error` level, and keep serving (chosen)

Leave the entry out of the windows the resolver is built with, log the refusal at
`error` level with the entry named and the accepted shape quoted, record it so the boot
that sees it corrected can close it out, and fail `db:doctor` on it. The application
boots: a diagnostic never takes the process down.

### F — throw on a malformed value

The strongest signal, and the wrong one at boot. The failure it produces is an
application that does not start, on a deploy that changed configuration — and the
condition it is reacting to is a *read-routing* misconfiguration, not a broken
database. A boot-loop forces an emergency rollback for something that `db:doctor
--strict` should have failed in the pipeline, where it is cheap. It also breaks the
package's own promise that the audit's findings never stop an application from booting.

### G — report it only in `db:doctor`

Keep boot silent, let the preflight fail.

Preflights are run by whoever remembers. The installations that most need this finding
are the ones that have never run `db:doctor`, and the boot is the one moment at which
the package knows the configuration is malformed and has a log to write to. Doing both
is the point: the log is for the installation that never looks, the row is for the
pipeline that does.

---

## The chosen mechanism, in full

### One classifier, three callers

`Support\ReaderWindows::split()` is the only place that decides what a window is. It
returns three fields:

| Field      | Meaning                                                                        |
|------------|--------------------------------------------------------------------------------|
| `usable`   | the entries that can be windows, in the order they appeared                     |
| `rejected` | the entries that cannot, each as `{at: '[0]', entry: '"10:00-14:20"'}`          |
| `shape`    | the value itself when it is not a list at all (`'"10:00-14:20"'`), else `null`  |

The provider builds the resolver from `usable` and reports from `rejected` /
`shape`; `db:doctor` reports from the same three fields. Neither re-derives what a
window is, so a log line and a row cannot disagree with the resolver.

The third caller is `db:doctor --config-file=path`, which judges a candidate
`config/db-manager.php` before it is deployed: it classifies the file's `reader_windows` and
`reader_days` through the same `split()` calls, reports the same refusals with the same
sentences and the same printed replacements, and builds no resolver at all. A value refused
on a branch and a value refused at boot are therefore the same answer from the same code —
which is the property that lets the mode skip the installation and still be worth gating on.
The refusals are the part of this mechanism that is a fact about the *value*; what a usable
window set can never do is a fact about routing, and stays with the installation's row.

### What is refused is shape, not content

An entry that is an array is a window, whatever it holds: both bounds are optional and
default to a whole day (`00:00:00 … 23:59:59`), and scalar bounds are stringified the
way `ConfigValue` narrows every other config read. So the single refusal is *"this is
not an array"*, and everything the package cannot act on inside an array — an
unparseable time, a start that is not before its end — stays a warning, because those
are understood values.

The one-character mistake this also catches is the missing outer list:

```php
'reader_windows' => ['start' => '10:00:00', 'end' => '14:20:00'],   // not a list of windows
```

which is refused entry by entry — `reader_windows["start"] is "10:00:00", reader_windows["end"] is "14:20:00"` —
rather than being read as a window with two awkward keys.

### The sentence is one constant

`ReaderWindows::ACCEPTED` holds the sentence both surfaces quote:

```
each window must be an array like ['start' => '10:00:00', 'end' => '14:20:00'] — a
string such as '10:00-14:20' is not a window
```

The boot line and the `db:doctor` row embed it verbatim. Restating it in either place
would be the way the two surfaces start describing the same rule differently.

### Why `error` level, and why boot survives

`error` is what makes the finding a refusal rather than a nuisance, and the package has
a place for exactly that: `BootAuditFinding::$level`, which `BootAudit::report()` passes
straight to `Log::log()`. Every other finding keeps the `warning` default — they are
values the package understood. Resolutions are always `warning`, whichever level the
finding was: arriving at a working configuration is good news either way.

The audit is wrapped so that nothing it does can stop a boot (candidate F). A refused
value is not a broken database: an application whose reader windows are malformed still
serves every request, it just serves them from the pool at every hour — which is why
the log carries the consequence in the same sentence.

### What the record remembers

The finding is keyed `swrr.reader_windows.refused` and written to `swrr.audit.file`
beside the others, with the context that makes it actionable:

| Context key        | Value                                                        |
|--------------------|--------------------------------------------------------------|
| `rejected_windows` | the entries that were refused, position and value             |
| `unreadable_value` | the value itself when the whole setting was the wrong shape   |
| `windows_in_use`   | how many windows survived, so "typo" and "total loss" differ  |

The boot that finds the setting readable again logs the resolution and clears the key,
so the pair reads across a deploy. `db:doctor` appends
`— recorded unresolved since <first sighting>` when the key is still on record, which
is what separates "this deploy broke it" from "it has been like this since
2026-09-24".

### How many findings a boot reports

Every finding that applies, not the first one that does. A boot that found a flat window
string *and* a comma-separated day list used to log one of them and stop, which left the
other for a later boot — or for whoever went looking through the log. Both are reported,
under their own keys, so each is closed out by whichever boot sees its own fix; a boot that
finds both fixed logs both resolutions.

One thing cannot be reported twice: a finding *key*. `BootAudit::report()` keys findings by
`key`, so two findings sharing one are one entry in the record — and the audit no longer lets
that be silent: `fold()` logs every finding it is given, names a shared key as a defect in
the package, and keeps the louder of the two for the record (see
[boot-audit-finding-keys.md](boot-audit-finding-keys.md)). The two causes that meet at
`always_writer` — no day in 1…7, and no window that can ever be entered — are therefore one
finding naming both causes, rather than two that collide.

What is *not* reported is a finding that does not apply, and the case to keep in mind is
permissive mode. `isAlwaysReaderMode()` answers before a single window is consulted, so when
no day can be matched there is no fact about the windows to state: a window that can never
be entered is not something these reads can be wrong about. Reporting it would describe a
state the resolver never reaches. Refusals are different — they are claims about the value
rather than about routing, so both of them are reported whatever the resolver would do.

### What `db:doctor` says

| State of `swrr.reader_windows`                        | Row      |
|-------------------------------------------------------|----------|
| an entry that is not an array, or the value is not a list | `FAIL` — names the entry (or the shape) and quotes what a window is; `--strict` is irrelevant, a failure is a failure |
| `reader_days` written as one string, or holding an entry that is not a day | `FAIL` — names the entry (or the shape) and quotes what a day is |
| windows fine, `reader_days` empty (permissive)         | `WARN`   |
| windows fine, no day in 1…7 (pool never used)          | `WARN`   |
| windows whose start is not before their end            | `WARN`   |
| both settings refused in the same run                  | one `FAIL` row naming both, with a `suggestion` line per repair |
| unset or empty — the documented opt-out                | `PASS`   |
| a fallback that can apply                              | `PASS`   |

The two `FAIL` shapes are also the two rows `db:doctor --config-file=path` prints for a file
that has never been installed — same sentences, same suggestions, no age clause and no
resolver, because there is no boot record for a candidate and no routing to describe.

`--strict` fails on the warnings, so a release gate rejects every state in which the
setting cannot do what it says — not only the refused one.

The row is one row, not one per problem: it names **every** problem it finds rather than the
first, and the verdict it prints is the loudest one in that list. The alternative was the
behaviour it had first — report the problem the code reached first and stop — which made two
independent mistakes cost two deploys, the second discovered only on the next preflight,
while the boot audit had been saying both all along. The detail is the problems' own
sentences joined, in the audit's order, so a row that names two of them reads as two
sentences rather than a run-on.

Each of those sentences is dated from **its own** finding. The record holds one entry per
finding key — the refusal of `reader_windows`, the refusal of `reader_days`, a fallback that
cannot be entered — and each problem here carries the key it came from, so
`recorded unresolved since …` is looked up for the problem being reported rather than for
whichever entry the record happens to hold first. A record left by an earlier boot can
therefore hold a finding about a *different* reader problem than the one in front of the
operator, and the row says nothing about its age: quoting it would answer "since when" with
somebody else's date, which is a claim about when this installation started being wrong.

### The repair, printed and not applied

A refusal names the mistake and quotes the shape, which leaves the operator to compose the
replacement themselves. The row therefore prints it, on its own line under the row:

```
FAIL  reader windows      reader_windows[0] is "10:00-14:20" — each window must be an array
                          like ['start' => '10:00:00', 'end' => '14:20:00'] — a string such
                          as '10:00-14:20' is not a window. Refused: nothing is left to
                          apply, so reads use the replica pool at every hour.
      suggestion          swrr.reader_windows = [['start' => '10:00:00', 'end' => '14:20:00']]
```

The label sits in the row-name column so it reads as part of the row above it, and the
line carries no verdict: the row is still a `FAIL`, the run still exits `1`, and the
suggestion is not one of the checks the summary counts. The same line covers the setting
one key over, and the one-character mistake in the record's own tests:

```
      suggestion          swrr.reader_days = [1, 2, 3]
      suggestion          swrr.reader_windows = [['start' => '10:00:00', 'end' => '14:20:00']]
```

One line per repair, which is why the row holds a list of them rather than one: a row that
names two refused settings has two replacements to paste, and printing the first would leave
the operator to compose the second from the sentence beside it — the work the line exists to
remove. The order is the order the problems are named in.

| refused value | printed replacement, and why |
|---|---|
| `'10:00-14:20'` — an entry, or the whole value | `[['start' => '10:00:00', 'end' => '14:20:00']]` — a range carrying both bounds, which is the spelling `ACCEPTED` names |
| `['start' => '10:00:00', 'end' => '14:20:00']` — no outer list | the same window, wrapped: the mistake is one level up, not inside the window |
| `'1,2,3'` — as the whole value, or as an entry | `[1, 2, 3]` — the days it names, once each, because a day list is a membership test rather than a sequence |
| `'22:00-06:00'`, `'14:20-10:00'` | **nothing.** This resolver cannot express an overnight window, so the expansion would hand back a window that can never be entered — trading a refusal for a warning |
| `'9:00-14:20'` | **nothing.** The resolver normalises a bound it cannot parse to midnight, so the replacement would not be the value the operator wrote |
| `'10:00 to 14:20'`, `'mon'`, a nested list | **nothing.** These are values the package will not interpret, and a guess pasted into configuration routes reads the wrong way round |

The rule behind the table is one sentence: a suggestion is printed only where the value
names its own replacement, so there is exactly one way to write it. Everything else keeps
the sentence that says what the shape is, because a wrong suggestion is worse than no help.

Three things the line deliberately is not:

- **Not candidate A.** The package never *reads* the flat form: the entry is still left out
  of the windows the resolver is built with, so the accepted grammar is still closed and
  the flat string is still not a form anyone can rely on. What is printed is what the
  operator would have written, for the operator to write.
- **Not candidate B.** Nothing is applied and nothing is rewritten. B's cost — the config
  file holding one thing while the operator wrote another — does not arise: the file is
  theirs until they edit it, and the value stays refused until they do.
- **Not part of the log.** The boot audit reports the state it sees, on every boot, and the
  measurement that matters there is that the setting *is* still refused. The repair belongs
  where someone is about to act, which is a preflight a pipeline runs, and printing it on
  every boot would say "you have not fixed this yet" in the one place that is already
  saying it.

---

## The same rule, one key over: `swrr.reader_days`

`swrr.reader_windows` is refused for a mistake anyone can make once, and the setting
beside it has the same mistake in its own spelling: a day list written as one string.

```php
'reader_days' => '1,2,3',      // how a list is written in .env
'reader_days' => [1, 2, 3],    // what this setting reads
```

### The same two facts

**Dropping it inverts the setting, just as silently.** Entries that were neither an int
nor a numeric string used to be dropped one by one, and `'1,2,3'` is one entry. Dropping
it leaves no day at all — and no day is the resolver's documented *permissive* mode, so a
day list meant to confine reads to Monday–Friday instead sends them to the replica pool
all week. The windows defect leaves every hour a reader hour; this one leaves every day a
reader day. Same inversion, same silence.

**A warning would not have been enough, and the existing one proved it.**
`'1,2,3'` was not invisible: it left no day, so the audit reported the *consequence* at
`warning` level under `swrr.reader_fallback.always_readers` — "swrr.reader_days is set, but
none of its entries is a usable day". That sentence describes a state, not a mistake. It
never says the value was read the wrong way round, never names `'1,2,3'`, and never says
what to write instead; an operator reading it has to work out that the setting whose
spelling they used is at fault. Refusing the *input* is what turns "this fallback cannot
apply" into "write `[1, 2, 3]`" — the repair, not the symptom.

### Where the line is drawn, and why the line is the same one

A bare scalar stays accepted: `'3'` means the third day because a single day can be read
exactly one way. `'1,2,3'` cannot be read without first deciding what a list of days is
spelled like — comma separated, space separated, semicolons, `'mon,tue'`, a JSON array —
and every one of those decisions is a guess about a value somebody will keep writing.
That is the same line the windows key draws between "a value of the accepted shape" and
"a value to be parsed". Once a package starts parsing, the accepted grammar is a
compatibility surface, and refusal keeps it at zero.

### What it costs

Both keys are answered by one classifier shape and one sentence rule, so the cost is one
more branch in each surface rather than a new policy:

| Surface | Refusal |
|---|---|
| `Support\ReaderDays` | `split()` returns `usable` / `rejected` / `shape`, mirroring the windows classifier, and `ACCEPTED` states the shape in one sentence |
| the boot audit | `swrr.reader_days.refused`, `error` level, naming `reader_days[1] is "1,2,3"` (or the whole value), with the day count that survives |
| `db:doctor`'s `reader windows` row | the same `FAIL` treatment, from the same sentence, so the row and the log cannot describe the rule differently — plus the printed suggestion, where the value reduces to an exact replacement |
| the resolver | nothing — it sees the same days it saw before, which is what makes the refusal a report rather than a routing change |

Only a *configured* value is refused: the `[1, 2, 3, 4, 5]` default is the package's own,
and a list whose remaining entries are days keeps them — a typo costs the entry, not the
fallback, exactly as with windows.

---

## Tests that pin the rules

| Test                                                                        | Pins                                        |
|-----------------------------------------------------------------------------|---------------------------------------------|
| `ReaderWindowsTest::test_a_flat_string_entry_is_rejected_and_named_by_position` | the refusal, and the wording of the name     |
| `ReaderWindowsTest::test_the_whole_setting_written_as_one_flat_string_is_refused_as_a_shape` | one level up: no entries to blame            |
| `ReaderWindowsTest::test_a_window_written_without_its_outer_list_is_refused_entry_by_entry` | the most common one-character mistake        |
| `ReaderWindowsTest::test_a_refused_entry_does_not_take_the_well_formed_windows_with_it` | partial refusal keeps what works            |
| `ReaderWindowsTest::test_a_missing_bound_stays_missing_so_the_resolver_keeps_its_default` | shape is refused, content is not            |
| `ReaderWindowsTest::test_nothing_configured_is_not_a_rejection`              | the opt-out stays silent                     |
| `WeightedDatabaseServiceProviderTest::test_flat_string_reader_windows_are_refused_at_error_level` | `error` level, context, the record, and its resolution |
| `ReaderDaysTest::test_a_list_written_as_one_string_is_a_shape_problem` | the comma-separated list, refused as a shape |
| `ReaderDaysTest::test_a_bare_scalar_day_is_a_single_day_and_not_a_shape_problem` | `'3'` stays accepted                        |
| `ReaderDaysTest::test_entries_that_are_not_day_numbers_are_named_rather_than_left_out` | position and value, not "no usable day"     |
| `ReaderDaysTest::test_an_array_of_numeric_strings_is_still_a_day_list` | the accepted `.env` shape                    |
| `WeightedDatabaseServiceProviderTest::test_a_day_list_written_as_one_string_is_refused_at_error_level` | `error` level, context, the record            |
| `WeightedDatabaseServiceProviderTest::test_a_refused_day_entry_leaves_the_days_that_are_days_in_place` | partial refusal keeps what works             |
| `WeightedDatabaseServiceProviderTest::test_unusable_reader_days_do_not_push_every_read_to_the_writer` | refusing does not move reads to the writer   |
| `WeightedDatabaseServiceProviderTest::test_an_empty_day_list_that_cannot_decide_anything_is_reported` | the surviving `warning` path                 |
| `DbDoctorTest::test_the_reader_windows_row_fails_a_day_list_written_as_one_string` | the `FAIL` row for the days key              |
| `DbDoctorTest::test_the_reader_windows_row_fails_a_day_entry_that_is_not_a_day` | the entry-by-entry half of the same row      |
| `DbDoctorTest::test_the_reader_windows_row_passes_the_accepted_spellings_of_a_day_list` | the documented shape is not refused          |
| `WeightedDatabaseServiceProviderTest::test_unusable_reader_windows_do_not_push_every_read_to_the_writer` | refusing does not move reads to the writer   |
| `DbDoctorTest::test_the_reader_windows_row_fails_a_flat_string_and_names_the_entry` | the `FAIL` row an operator reads            |
| `DbDoctorTest::test_the_reader_windows_row_fails_even_when_a_well_formed_window_survives` | a typo costs the entry, not the fallback     |
| `DbDoctorTest::test_the_reader_windows_row_dates_a_refusal_the_record_still_holds` | the age clause                               |
| `DbDoctorTest::test_the_reader_windows_row_warns_when_the_day_list_decides_nothing` | `WARN`, and `--strict` failing it            |
| `DbDoctorTest::test_the_reader_windows_row_passes_the_documented_opt_out`    | the opt-out is not nagged about              |
| `ReaderWindowsTest::test_the_repair_it_can_print_is_the_value_re_spelled`     | the suggestion, row by row, `null` included  |
| `ReaderWindowsTest::test_a_suggestion_is_not_a_repair`                        | printing the fix does not accept the value  |
| `ReaderWindowsTest::test_a_literal_is_written_the_way_configuration_spells_it` | the spelling a pasted line has to have      |
| `ReaderDaysTest::test_the_repair_it_can_print_is_the_days_it_names`           | the days half of the same rule              |
| `DbDoctorTest::test_the_reader_windows_row_prints_the_repair_for_a_flat_string` | the line, its column, and that it is not a check |
| `DbDoctorTest::test_the_reader_windows_row_prints_the_repair_for_a_day_list_written_as_one_string` | the days key prints one too |
| `DbDoctorTest::test_the_reader_windows_row_prints_the_repair_for_a_window_written_without_its_list` | the one-character mistake, repaired |
| `DbDoctorTest::test_the_reader_windows_row_prints_no_repair_it_would_have_to_guess_at` | the `null` half reaches the row |
| `DbDoctorTest::test_the_reader_windows_row_names_every_refused_setting` | one row, both refusals, two repairs |
| `DbDoctorTest::test_the_reader_windows_row_names_a_refusal_beside_the_warning_it_leaves_behind` | the verdict is the loudest problem |
| `DbDoctorTest::test_the_reader_windows_row_dates_each_problem_from_its_own_finding` | each age sits with its own sentence |
| `DbDoctorTest::test_the_reader_windows_row_does_not_date_a_problem_it_is_not_reporting` | a record holding another problem's finding is not quoted |

---

## Known limitations

- **The refusal is about shape only.** `'22:00-06:00'` — an overnight window, which this
  resolver cannot express because the window end is exclusive and within the same day —
  is a window with an unreachable range, so it is a `warning`, not a refusal. A
  `reader_windows` list that *looks* like it covers the night therefore still passes a
  non-strict preflight.
- **An empty list is indistinguishable from the opt-out, by design.** There is no way
  to tell "I removed the last window" from "I never configured any", so removing the
  last well-formed entry opts an installation out rather than failing it.
- **The record is per installation, not per environment.** The age clause reports the
  first sighting by any boot that writes that `swrr.audit.file`. Two environments
  sharing a record file would share the date.
- **A read-only deploy cannot record the finding**, so it logs the refusal and closes
  nothing out (the same limitation as the store probe; `db:doctor`'s `pgcat files` row
  reports that directory as unwritable, because the state file shares it).
- **Bound formats are not validated at boot.** `['start' => 'half ten']` is a window
  whose start normalises to midnight, which can leave it unreachable — a warning, not a
  refusal, because the entry has the right shape.

## What would change this decision

- **If the flat string became a documented form.** Then candidate A is right, and the
  work is a parser with its own tests rather than a refusal. It is not a documented form
  precisely because the family has no obvious members (candidate A). This applies to the
  day list as well: `'1,2,3'` would be refused today even if a comma were declared the
  separator, because the entries beside a comma are the same question one level down.
- **If the framework parsed list values before configuration reached the package.**
  `env('READER_DAYS=1,2,3')` arrives as a string; a host application that resolved its
  list-valued settings into arrays would leave nothing for the days key to refuse, and
  the days half of this record would reduce to the array cases.
- **If a deploy-time schema check existed elsewhere.** If configuration were validated
  by something that fails the pipeline before the image is built, the boot could stay
  quiet and candidate G would be enough. The package now has half such a hook —
  `db:doctor --config-file=path` fails a pipeline over a candidate `config/db-manager.php`,
  and it reports exactly these refusals — but half is the honest word: it judges the file it
  is handed, on the machine that runs it, and a boot is the only thing that sees the
  *environment* a value will finally be read in (`env()` in the file is resolved at boot, and
  a value can come from a `.env` no pipeline has). So the boot finding stays, and the vet is
  an earlier place to hear about the same mistake rather than a replacement for it.
- **If the audit were allowed to stop a boot.** Then F would be on the table, and the
  refusal could be an exception. That would contradict the property the audit is built
  around — a diagnostic never takes the process down — and it would have to be decided
  for every finding, not just this one.
- **If the resolver could express an overnight window.** Then part of the "warn, do not
  refuse" line would move, because a start that is not before its end would stop being a
  mistake.

## Files

| File                                                         | Role                                                     |
|--------------------------------------------------------------|----------------------------------------------------------|
| `src/Support/ReaderWindows.php`                               | the classifier (usable / rejected / shape, `ACCEPTED`) and the printable replacement |
| `src/Support/ReaderDays.php`                                  | the same for `reader_days`, plus `split()` and the printable replacement |
| `src/Support/BootAuditFinding.php`                            | carries `$level`, `warning` by default                     |
| `src/Support/BootAudit.php`                                   | logs each finding at its own level, record + resolutions   |
| `src/Providers/WeightedDatabaseServiceProvider.php`           | builds the resolver from `usable`, reports the refusal     |
| `src/Database/Weighted/TimeWindowResolver.php`                | `isAlwaysReaderMode()`, `unreachableWindows()`             |
| `src/Console/Commands/DbDoctor.php`                           | the `reader windows` row — every problem, each dated from its own finding — and the `suggestion` line under it |
| `tests/Unit/Support/ReaderWindowsTest.php`                    | the classifier's contract                                  |
| `tests/Unit/Support/ReaderDaysTest.php`                       | the day classifier, refusals included                      |
| `tests/Unit/Weighted/WeightedDatabaseServiceProviderTest.php` | the boot half                                              |
| `tests/Unit/Console/DbDoctorTest.php`                         | the row half                                               |
