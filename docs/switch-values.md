# What should the package do with an on/off setting it cannot read?

A design record for `swrr.allow_local_fallback`, `swrr.pgcat.enabled` and
`swrr.pgcat.use_reload`.

All three are switches: on or off, and nothing else. All three were read with
`ConfigValue::bool()`, which narrows a value with PHP's own rules — and that class's
docblock states the consequence, because it is not obvious unless you already knew it:

```
(bool) 'false' === true
```

So `'false'`, `'off'` and `'no'` — the three ways an operator actually writes *off* in an
`.env` file, in yaml, in a published config — were every one of them read as **on**. On
`swrr.pgcat.enabled` that is not a cosmetic slip: the switch arms a runtime file swap, so
a typo in it armed a swap the operator had asked for the opposite of. And `'maybe'`, `''`
or `2` are not on or off in any reading, yet a cast decided them too, in silence.

This document records what the package does instead, which alternatives were considered,
and why they were not taken. Everything below is implemented in `Support\SwitchValue`,
`Pgcat\PgcatConfigFlipper::switchReadings()`,
`WeightedDatabaseServiceProvider::switchFindings()` and `DbDoctor::switchValues()`. The
rules are pinned by the tests named at the end.

---

## The answer in one line

**Refuse it, loudly, and hold the value the setting documents.** A switch is read from a
closed list of spellings; a value that is not one of them resolves to the value its own
documentation prints, is logged at `error` level by every boot that sees it (one finding
key per setting), and becomes a row of its own in `db:doctor` so `--strict` fails a
deploy. Nothing is guessed at and nothing is repaired.

The three keys are `swrr.pgcat.enabled.refused`, `swrr.pgcat.use_reload.refused` and
`swrr.allow_local_fallback.refused`.

---

## Why this needed deciding at all

Four facts, each of which rules out an otherwise obvious answer.

### 1. A cast is right for a value the package computed, and wrong for one a person wrote

`ConfigValue::bool()` exists for the first case, and its strictness is a feature: the
package computes with it and a nonsense value degrades to the fallback rather than
throwing. But these three settings are not computed. They are hand-written, and the
spellings a person writes for *off* are precisely the ones a cast gets wrong.

### 2. The direction of the mistake is not symmetric

For `allow_local_fallback`, reading `'off'` as on means reads may fall back to an
in-process store the operator wanted to hard-fail instead. For `enabled`, it means the
flipper is armed, and it will read `pgcat.toml`, write a variant over it, and signal
`supervisorctl` — an actual change to a running database proxy. A refusal whose fallback
is *on* would therefore be a refusal that does something the operator did not ask for, at
the one switch where doing so is expensive.

### 3. The package already refuses a value it cannot read — one setting over

`swrr.reader_windows` refuses a non-window: the flat `'10:00-14:20'` is the natural way to
write a window and is not one, and reading it as "no window at all" inverted the setting.
That decision is recorded in [reader-windows-refusal.md](reader-windows-refusal.md) and
its rule is exactly this one — refuse the input, report it at `error` level, keep serving.
A switch that cast instead would be the package disagreeing with itself about what to do
with a value it cannot read.

### 4. The shipped sample config cast the value before the package ever saw it

```php
'allow_local_fallback' => (bool) env('SWRR_ALLOW_LOCAL_FALLBACK', true),
'enabled'             => (bool) env('SWRR_PGCAT_ENABLED', false),
```

Whatever the package decided downstream, this upstream cast had already swallowed the
typo: `SWRR_PGCAT_ENABLED=off` reached configuration as `true`, and no reader further in
could tell it from a deliberate on. So the refusal had to start by *not casting* — and
`env()` already does the right thing here, answering a real bool for `'true'`/`'false'`
and passing everything else through as written.

---

## The candidates

### A — keep casting, and document it

The status quo. Cheapest, and it keeps `ConfigValue::bool()` as the one boolean rule in
the package. Rejected for the reason the reader-window record rejects "interpret the flat
string": documenting that `'off'` means on is not a rule anyone wants to learn, and it
leaves `enabled` arming a swap on a typo. It also cannot be described in a way that does
not sound like a defect, which is usually the signal.

### B — cast, but only accept real booleans and the strings `'true'`/`'false'`

Narrower, and it would catch `'off'` by refusing it. Rejected because it refuses the
things people write *on purpose*: `'on'`, `'off'`, `'yes'`, `'no'` and `'1'`/`'0'` are all
spellings of a switch that operators already have in their `.env` files — often in an
existing installation this package was dropped into — and refusing a correct value teaches
operators that the refusal is noise.

### C — refuse, and leave the switch at a hard-coded safe value for all three

Simple, and attractive for `enabled` in isolation. Rejected because the safe direction is
not the same for all three: off is safe for `enabled` and debatable for
`allow_local_fallback`, while `use_reload` has no "safe" of its own — the two commands are
both correct behaviour, and the choice between them is the operator's. A policy invented
per switch is the same thing as a default, but stated somewhere nobody looks for it.

### D — refuse, and fall back to the value the *flipper* used to fall back to

That is `enabled => true` and `use_reload => false` — the historic `?? true` and `?? false`
in the flipper — which disagree with the values `config/db-manager.php` ships (`false` and
`true`). Rejected because it cannot be described truthfully: the finding has to say which
value the refused setting now holds, and "the value this setting documents" would be a
false statement against the config file the operator is looking at. It also sends
`enabled` the unsafe way (see fact 2). This is the candidate that was actually implemented
first, and this record exists because writing the sentence exposed it.

### E — refuse, and fall back to the value the setting's own documentation prints (chosen)

One rule, no per-switch policy: a refusal lands exactly where an absent value lands, and
the sentence can name that value because the published config file states it beside the
setting. It has the side effect of aligning the flipper's absent-key fallbacks with that
file, which is a change in its own right — see "What this changed besides the refusal".

### F — throw

Rejected for the same reason the reader-window record rejects it: a diagnostic must never
take the process down, and the audit is built around that property for every finding, not
just this one.

### G — report it only in `db:doctor`

Rejected because `db:doctor` is a command someone chooses to run. The switch is read at
boot, by every worker, on every request under FPM — that is where the decision is taken,
and that is where the report has to be.

---

## The chosen mechanism, in full

### One classifier, three callers

`Support\SwitchValue` owns the rule and nothing else:

- `resolve(mixed): ?bool` — a bool is itself; an int or float is on at `1` and off at `0`
  (loose on purpose, so `0.0` and `0` are the same switch and `0.5` is neither); a string
  is compared against the lists after trimming and lower-casing; everything else is `null`.
- `read(mixed $value, bool $default): array{on: bool, refused: string|null}` — `null` is
  *not a refusal*: nothing was written, so there is nothing to read. A value that is
  present and unreadable resolves to `$default` and comes back in `refused`, described by
  the same describer the reader-window refusals use.
- `ACCEPTED` — the accepted shape, quoted in every report that refuses one.
- `describeRefused(array<string, string>): string` — `swrr.pgcat.enabled is "flase"`, one
  clause per setting, so a log line and a table cell name the same setting the same way.

The default is the caller's, because a default is a fact about a setting and not about
switches. Each caller passes the value its own setting's documentation prints:

| Setting                      | Documented default | Where that is stated                        |
|------------------------------|--------------------|---------------------------------------------|
| `swrr.pgcat.enabled`         | off                | `config/db-manager.php`, and the flipper     |
| `swrr.pgcat.use_reload`      | on (a HUP)         | `config/db-manager.php`, and the flipper     |
| `swrr.allow_local_fallback`  | on                 | `config/db-manager.php`, and the provider    |

The two pgcat switches are read in one place — `PgcatConfigFlipper::switchReadings()` —
and every question about them goes through it, which is why `refusedSwitches()` exists:
the boot audit asks the flipper rather than re-reading `swrr.pgcat`, so the flip and the
finding cannot disagree about whether pgcat is on. The third switch is the provider's own,
read by `WeightedDatabaseServiceProvider::allowLocalFallback()`, which the manager, the
boot audit and `db:doctor` all call.

### The spellings, and why they are these

`true`/`false`, `1`/`0`, `'on'`/`'off'`, `'yes'`/`'no'`, case- and space-insensitive.
They are what is already in the wild — Laravel's own `env()` vocabulary plus the two
spellings a supervisor or systemd unit tends to use — so the refusal catches mistakes
rather than accents. Nothing else is accepted: `'enabled'`, `'y'`, `'t'` and `'2'` are all
refused, because accepting them would mean the package deciding which near miss was meant.

### What a refusal is not

It is not a repair. `'flase'` is a word the package will not interpret, and the row prints
no `suggestion` line for it — unlike the reader windows, where `'10:00-14:20'` reduces to
an exact replacement. Guessing here would hand back a spelling the operator did not write,
which is the mistake the refusal exists to prevent, so the line is drawn exactly where the
reader-window record draws it.

### Why `error` level

Same reason as the reader refusals, and the same phrase: these are values the package will
not read on the operator's behalf, not settings that merely cannot act. Everything else the
audit reports — a mismatch, a fallback that can never apply, an unknown formula — is a
`WARN`, because those settings are doing the wrong thing but are at least doing *something*
the package understood.

### One finding per setting

Three keys, not one `switches.refused`. The record remembers a finding by its key and
dates it, so three typos filed under one key would resolve together and date together: an
operator who fixed one would be told nothing about the other two, and a single date would
claim the installation started being wrong about all three at once. A boot reports all
three when all three hold, for the same reason the reader half reports the windows and the
days together.

### What `db:doctor` says

One row, `switch values`, holding every refused switch as its own sentence — they are one
mistake repeated, and a row each would make them look like three unrelated faults. The row
is `FAIL` (at `error` level, refusals are failures), and each problem is dated from *its
own* finding key, which is the sharing `datedRow()` exists for: the row and `reader windows`
make the same claim about their problems, so they are built by the same method rather than
by two that can drift.

On a pass the row still says something, because "no refusals" is worth reading: it names
the value of each switch. It reads the two pgcat switches through the flipper, so a row
that cannot build the flipper fails rather than reporting a pass it could not establish.

---

## What this changed besides the refusal

### The sample config no longer casts

`(bool) env('SWRR_PGCAT_ENABLED', false)` became `env('SWRR_PGCAT_ENABLED', false)`, and
`allow_local_fallback` likewise. Without this the refusal would be unreachable for the most
common way either switch is set: the cast happens in configuration, before the package is
asked anything. `env()` still answers a real bool for `'true'`/`'false'`, which
`SwitchValue` reads directly, so nothing about the documented defaults moved.

### The flipper's absent-key fallbacks now match the published config

`PgcatConfigFlipper` fell back to `enabled => true` and `use_reload => false` when the keys
were absent, while `config/db-manager.php` ships `false` and `true`. Both are now the
shipped values. This is only reachable for a flipper built without those keys — an
installation whose published config predates the `pgcat` block, or a hand-built flipper —
and in both directions it is the safer and the gentler value: an installation with no pgcat
configuration block is now inert rather than armed against paths it does not have, and a
flip reloads rather than restarting when `use_reload` is absent. The documented value won.

### The test suite declares its switches

`tests/TestCase.php` and `WeightedDatabaseServiceProviderTest::armTheFlip()` now name
`enabled` and `use_reload` explicitly. They were relying on the old absent-key defaults,
which was the suite exercising an undocumented fallback rather than the flipper; declaring
them is what made the change visible instead of silently rewriting what several tests were
asserting.

---

## Tests that pin the rules

| Test                                                                     | Rule                                                        |
|--------------------------------------------------------------------------|-------------------------------------------------------------|
| `SwitchValueTest::test_every_spelling_an_operator_writes_is_read_as_itself` | every accepted spelling, case and space included           |
| `SwitchValueTest::test_a_value_that_is_not_a_switch_is_refused_and_handed_back_as_written` | the refusals, and that each lands on the default |
| `SwitchValueTest::test_a_setting_that_is_not_there_is_not_a_refusal`     | absent is not a mistake                                     |
| `SwitchValueTest::test_a_refusal_falls_back_to_the_default_the_caller_gives_it` | one classifier, two answers                            |
| `SwitchValueTest::test_the_accepted_shape_names_the_spellings_the_classifier_reads` | the quoted message cannot list a spelling the package refuses |
| `PgcatConfigFlipperTest::test_a_switch_written_as_a_spelling_is_read_as_that_spelling` | the flipper honours what it reads    |
| `PgcatConfigFlipperTest::test_off_written_as_the_string_false_actually_disables_the_flip` | the exact inversion, and that nothing is touched |
| `PgcatConfigFlipperTest::test_a_refused_use_reload_holds_the_documented_default_the_other_way` | a refusal does not escalate the action |
| `PgcatConfigFlipperTest::test_both_pgcat_switches_are_refused_together`   | one refusal per setting, both reported                      |
| `PgcatConfigFlipperTest::test_an_absent_switch_holds_the_value_the_shipped_config_prints` | a refusal lands where an absent value does |
| `WeightedDatabaseServiceProviderTest::test_a_pgcat_switch_written_as_something_that_is_not_a_switch_is_refused_at_error_level` | the boot half, and its resolution |
| `WeightedDatabaseServiceProviderTest::test_off_written_as_the_string_false_silences_the_gate_where_the_cast_armed_it` | the safety claim: a readable off arms nothing |
| `WeightedDatabaseServiceProviderTest::test_the_local_fallback_switch_is_refused_at_error_level` | the provider's own switch            |
| `WeightedDatabaseServiceProviderTest::test_a_readable_local_fallback_switch_is_not_refused` | the finding cannot fire on a correct value |
| `DbDoctorTest::test_the_switch_values_row_reads_off_written_as_the_string_false` | the row says `off` where a cast said `on`   |
| `DbDoctorTest::test_the_switch_values_row_names_every_switch_it_refuses`  | one row, every problem, one check                           |
| `DbDoctorTest::test_the_switch_values_row_dates_each_refusal_from_its_own_finding` | one date per setting, from its own key             |
| `DbDoctorTest::test_the_switch_values_row_does_not_date_a_switch_it_is_not_reporting` | a date is never a claim about another setting       |
| `DbDoctorTest::test_the_switch_values_row_prints_no_repair`               | a guess is not a suggestion                                 |
| `DbDoctorTest::test_the_switch_values_row_reports_nothing_when_the_flipper_cannot_be_built` | the row fails rather than inventing a pass     |

---

## Known limitations

- **Only three settings are covered.** They are the three switches the package has; a
  fourth would have to be added to `WeightedDatabaseServiceProvider::SWITCHES`,
  `DbDoctor::SWITCH_KEYS` and `SWITCH_CONSEQUENCES`, and the row would gain a sentence. The
  two tables restate the same three relationships, and nothing checks that they agree — a
  switch added to one and not the other would be a setting with a finding and no row, or a
  row with a key that is never written. `DbDoctorTest` would catch the second case only if
  a test happened to refuse that switch.
- **A switch nested deeper is not discovered.** The three are named by hand, not found by
  walking the config block, so a switch inside another block is invisible until someone
  adds it.
- **The refusal does not survive a cached config.** `php artisan config:cache` freezes the
  value into `bootstrap/cache/config.php`; the boot still reads and refuses it, but the
  resolution has to be re-cached after the fix, exactly as any other config change does.
- **`'0'` and `0` are indistinguishable, and so are `'1'` and `1`.** A value that arrived
  as a string because it came from `.env` is reported as the string it was
  (`swrr.pgcat.enabled is "flase"`), which is what the operator wrote; a value that already
  arrived as a bool is reported as `true` or `false`. Neither report can say whether the
  original `.env` line had quotes.
- **The consequence prose is written twice.** The boot finding and the doctor row quote the
  same rule (`SwitchValue::ACCEPTED`, `describeRefused`) but each states the consequence in
  its own words, as the reader-window refusal already does. The rule cannot drift; the
  sentence could.

## What would change this decision

- **If the package accepted a wider vocabulary.** `'y'`/`'n'`, `'t'`/`'f'`, `'enabled'` and
  `'disabled'` are all in the wild. Adding them is a change to two lists and the `ACCEPTED`
  sentence, and the test that asserts `ACCEPTED` only names spellings the classifier reads
  would keep both honest. It was left out because each one is a decision about what an
  operator meant, which is the thing this record is otherwise refusing to do.
- **If a switch gained a third state.** A `'auto'`, or a tri-state for `use_reload`, would
  make `?bool` the wrong return type and `read()` the wrong shape, and this record would
  have to be rewritten rather than extended.
- **If configuration were validated before boot.** Then the boot finding could reduce to
  the doctor row (candidate G), and the `.env` cast could stay where it is. The package has
  no such hook, and the boot is the moment the decision is actually taken.
- **If the audit were allowed to stop a boot.** Then candidate F returns, and the refusal
  could be an exception. That would contradict the property the audit is built around.
- **If the flipper's absent-key fallbacks were documented rather than shipped values.**
  Then candidate D's sentence could be told truthfully, and the alignment in "What this
  changed besides the refusal" would not have been necessary. It would still send
  `enabled` the unsafe way on a refusal.

## Files

| File                                                          | Role                                                      |
|---------------------------------------------------------------|-----------------------------------------------------------|
| `src/Support/SwitchValue.php`                                  | the classifier, `ACCEPTED`, `describeRefused()`            |
| `src/Pgcat/PgcatConfigFlipper.php`                             | reads its two switches once, `refusedSwitches()`, the documented defaults |
| `src/Providers/WeightedDatabaseServiceProvider.php`            | the three finding keys, `SWITCHES`, `allowLocalFallback()`, `switchFindings()` |
| `src/Console/Commands/DbDoctor.php`                            | the `switch values` row, `SWITCH_KEYS`, `SWITCH_CONSEQUENCES`, `datedRow()` |
| `config/db-manager.php`                                        | the switches handed over as written, and the documented defaults beside them |
| `tests/Unit/Support/SwitchValueTest.php`                       | the classifier's contract                                  |
| `tests/Unit/Pgcat/PgcatConfigFlipperTest.php`                  | the flipper's half                                         |
| `tests/Unit/Weighted/WeightedDatabaseServiceProviderTest.php`  | the boot half                                              |
| `tests/Unit/Console/DbDoctorTest.php`                          | the row half                                               |
