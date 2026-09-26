# What should the package do with replica metadata it cannot read?

A design record for `db:doctor`'s `replica metadata` row.

A replica is sized by three settings — `weight`, or `cpu_cores` with `ram_gb` — and
`WeightResolver::resolveWeight()` reads them through `max(0, ConfigValue::int(…))` and
`max(1, ConfigValue::int(…, 1))`. `ConfigValue` falls back for anything that is not a number, so
`'weight' => 'heavy'` is read as `0` … and `0` is documented, in that same method, as the way a
replica is *disabled*. `buildPool()` then drops it. An installation whose weight was a typo
therefore lost a replica from the pool without a word, and the row that exists to catch a
mis-sized pool reported the pool that was left — a count short by one, printed as health. This
document records what the row does instead, which alternatives were considered, and why they were
not taken.

Everything below is implemented in `DbDoctor::replicaMetadata()`, `metadataProblems()`,
`disabledReplicas()` and `describeMetadata()`, plus the visibility of
`WeightedDatabaseManager::replicaKey()`. The rules are pinned by the tests named at the end.

---

## The answer in one line

**The row reads the *configured* replicas, fails on every metadata value the resolver does not
read as written, and names the replica and the value** — saying whether that replica left the pool
or stayed in it sized as something else. `weight: 0` is the one difference the package means, so a
replica disabled that way does not fail the row; it is named in the count instead.

---

## Why this needed deciding at all

### 1. The row's question is not "does this installation look weighted"

A replica with `'weight' => 'heavy'` looks weighted. So does the table, so does the health
payload, so does the config file. The row's actual question is whether the pool is the pool the
read list describes — and the only evidence that it is not is the pool's *size*, which is the one
thing `replicaStatus()` cannot report, because it returns the replicas that survived.

### 2. The resolver's fallback for a weight is the operator's own off switch

`max(0, ConfigValue::int(…))`, where `resolveWeight()`'s docblock says "0 = disabled", and
`buildPool()` skips `weight <= 0`. So there is no number to distinguish "read as 0 because you
wrote 0" from "read as 0 because it is not a number" — the substitution and the deliberate
disable are the same value, and the deliberate disable is legitimate. Any rule here has to tell
those two apart by looking at the value that was *written*, not at the weight that came out.

### 3. A count without its denominator is the quiet part

The old row's `PASS` read `2 replicas on [pgsql], total weight 80`. Nothing in that sentence says
the read list has three. So even after unreadable metadata is refused, a *deliberate* disable — a
replica drained for maintenance, which is documented behaviour — still shrinks the pool and still
prints as though the pool were the installation.

---

## The candidates

| | candidate | cost | verdict |
|---|---|---|---|
| A | keep counting the resolved pool | reports a smaller installation as the installation — the defect | **rejected** |
| B | fail when the pool is smaller than the read list | cannot tell a typo from `weight: 0`, so it fails a documented drain | **rejected** |
| C | warn on unreadable metadata | a warning describes a value the package understood and used; here the pool has already lost a member, and a deploy gate without `--strict` would pass it | **rejected** |
| D | read the configured replicas, fail naming the value, name `weight: 0` separately | the rule has to be derived from the resolver rather than invented | **chosen** |
| E | repair the value — read `'heavy'` as some default | invents a value nobody wrote, and cannot be done at all for `weight: false`, where the "repair" is the disable | **rejected** |
| F | refuse at boot: throw on metadata the resolver cannot read | a read-routing misconfiguration must not stop the application answering requests | **rejected** |
| G | fail on every replica's metadata being present-but-unreadable, ignore the rest | leaves the partial case — the realistic one — silent | **rejected** |

### B — fail whenever the pool is smaller

The cheapest rule, and it needs no knowledge of the values. It fails on the one configuration
that is *correct*: `weight: 0` is documented as the way to take a replica out, so an installation
that has drained one on purpose would be told its read list is broken, and `--strict` would refuse
the deploy. A guard that fails on the documented spelling of an intention is a guard that gets
switched off.

### C — warn instead of fail

This is what the row's other branch does, and it is right there: replicas carrying *no* metadata
are an installation running on the equal-treatment fallback, which works. Unreadable metadata is
different in kind — the resolver has already substituted a value, and for `weight` the
substitution is a removal — so a warning would understate it, and without `--strict` a deploy
would go out carrying it.

### E — repair the value

Reading `'weight' => 'heavy'` as anything means choosing a size for a machine nobody described,
and the corrected value is not what the operator wrote; the next person to read the config file
sees one thing and gets another. `weight: false` shows why the idea has no floor: the resolver
reads it as the documented disable, so the "repair" would be the value that removed the replica.

---

## The chosen mechanism, in full

### The judgement is the resolver's arithmetic, not a second copy of it

`WeightResolver` reads the three settings through `max(0, ConfigValue::int(…))`,
`max(1, ConfigValue::int(…, 1))` and `max(0.0, ConfigValue::float(…))`, and `ConfigValue` falls
back for anything that is not a number. So a written value stops being the value routing uses in
exactly two ways:

1. **it is not a number**, so a fallback is read instead; or
2. **it is a number under the floor its key is read at** — `0` for a weight, `1` for a core
   count, `0` for memory.

The floors are the resolver's own `max()` arguments, so the row cannot disagree with routing about
what a value becomes. `weight` is the key whose floor a value is *meant* to sit on, so for that key
condition 2 is stated as its opposite — a reading of `0` out of a value that is not `0` — and the
explicit `0` is left to the count. That single restatement is what makes three near-misses
reportable for the first time:

| written | read as | why it is now reported |
|---|---|---|
| `'weight' => -5` | `0` | `max(0, …)` clamps it to the disable value its author never wrote |
| `'weight' => 0.5` | `0` | `ConfigValue::int()` truncates like `(int)`, so a fraction can land on the disable |
| `'weight' => null` | `0` | the key is *present* with nothing in it — which `isset()` reads as absent, so it used to fall into "no weight metadata", a warning about a different fault |

`weight: 0`, `'0'`, `0.0` and `false` are read as written and are the disable. `ConfigValue` reads
a bool as a number, and the rule follows it: `weight: true` is weight 1, and nothing is substituted
for it.

### The sentence quotes the value and the reading

```
[10.1.0.2:5432] weight is "heavy", which the resolver reads as 0
```

The value is described by `ReaderWindows::describe()` for anything that is not a number — the
package's one describer for a value in a report, already shared with `ReaderDays` — and printed as
itself when it is one, because `weight is int` says nothing about *which* int, and the numbers this
row reports are the ones the resolver changed. The reading is quoted because "the weight could not
be read" is not actionable without it.

### A weight that leaves the pool and a size that changes are told apart

The two consequences are different faults, and the row says which happened. A weight read as `0`
takes its replica out of the pool:

```
replica metadata the resolver cannot read on [pgsql]: [10.1.0.2:5432] weight is "heavy", which
the resolver reads as 0 — a replica weighted 0 leaves the pool, so reads are routed over a smaller
pool than the read list describes and nothing else reports that it happened
```

Cores or memory are substituted for, so the replica stays and is weighted as something the read
list does not say: `cpu_cores` becomes one core and `ram_gb` none. Same failure, different ending.

### `weight: 0` is named and not failed, and the count carries its denominator

The disable passes, because it is the documented spelling of an intention. What it no longer does
is disappear into a smaller number:

```
PASS  replica metadata    the pool on [pgsql] holds 1 of the 2 configured replicas (total weight 10): 10.1.0.2:5432 disabled with weight 0
```

`2 replicas on [pgsql]` on an installation with three is the same quiet shrink through the front
door, whichever mechanism produced it.

### The symptom stops being blamed for the cause

With every replica's weight unreadable, the row used to print `every replica on [pgsql] resolves to
weight 0 — reads cannot be routed`. True, and useless: "every replica is deliberately drained" and
"every replica's weight is a typo" are the same picture without the values, and the values are the
repair. The unreadable case is now reported before that sentence can be reached, and a test asserts
both halves — the value is named, the symptom is not.

### Naming a replica the pool has dropped

`replicaStatus()` returns the pooled replicas, which is precisely the wrong list here, so the row
walks the configured `read` list. That needs an identity for a replica that has no status row, so
`WeightedDatabaseManager::replicaKey()` — already *the* spelling of "which replica", since the
health monitor keys failures by it — became public. The alternative was a second spelling of a
replica's identity in the doctor, which is a second thing to keep in step with the health record.

---

## Tests that pin the rules

| Rule | Test |
|---|---|
| an unreadable weight fails, names the replica, and says the pool is smaller | `DbDoctorTest::test_the_metadata_row_fails_and_names_a_replica_whose_weight_cannot_be_read` |
| an unreadable memory fails and says the replica **stays** in the pool | `test_the_metadata_row_fails_and_names_a_replica_whose_memory_cannot_be_read` |
| an unreadable core count is read as one core, and says so | `test_the_metadata_row_fails_and_names_a_replica_whose_cores_cannot_be_read` |
| a negative weight is a value it would clamp, not a disable | `test_the_metadata_row_fails_on_a_negative_weight_it_would_clamp` |
| a weight that truncates to 0 fails rather than reading as a disable | `test_the_metadata_row_fails_on_a_weight_that_truncates_to_zero` |
| an unreadable value is blamed, not the weight it reads as | `test_the_metadata_row_blames_the_unreadable_value_rather_than_the_weight_it_reads` |
| every replica and every value is named | `test_the_metadata_row_names_every_replica_and_every_value_it_cannot_read` |
| `weight: 0` passes and is named, with the read list as its denominator | `test_the_metadata_row_names_a_disabled_replica_without_failing` |
| no metadata at all is still the row's warning | `test_it_warns_when_the_replicas_carry_no_weight_metadata` |

Mutations this decision has been checked against, each against the eight tests above: stopping the
naming of a documented disable (one failure, the disabled case), reporting only non-numbers so the
floor clause goes (two failures — the negative weight and the truncation), reporting no unreadable
metadata at all (seven failures; the disabled case survives, which is the point of it being a
separate branch), and blaming a smaller pool in every case rather than only when a weight was read
as 0 (one failure, the memory case, whose sentence says the replica stays).

---

## Known limitations

- **A value that reads as written is not checked against anything.** `weight: 1000000` is read as
  written and the row passes; so is a `cpu_cores` of 64 on a machine with two. The row judges
  readability, not plausibility, and `db:replica-status` is where the shares it produces are
  visible.
- **The floors are the resolver's as of now.** They are `max()` arguments inside
  `WeightResolver::resolveWeight()`, copied here as numbers. A change to the resolver that is not
  a change to this row would make the row disagree with routing about a substitution — the
  property the rule is built on, and the one thing no test can catch without reading the resolver.
- **A bool is a number to `ConfigValue`, so the row treats it as one.** `weight: true` is read as
  weight 1 and passes. It is incoherent configuration that behaves consistently, and reporting it
  would mean the row disagreeing with the reader it is asserting about.
- **The row is read-only, so it cannot see a replica that was never booted.** It reports what the
  read list says and what the resolver reads from it, not whether the pool's size matches the
  replicas that are actually answering — that is `db:probe-replicas`.
- **`weight: 0` passes without a note in the JSON contract.** The disable is in the row's `detail`,
  which is prose; a gate that wants "nothing is drained" has to match a string, and there is no
  field for it.

## What would change this decision

- **If the resolver stopped dropping a replica with no readable weight.** A weight that could not
  be read would become a read error or an explicit failure at pool-build time, and this row would
  be a diagnostic ahead of that rather than the only place the removal is visible.
- **If `weight: 0` became a `WARN`.** A drain is a choice today because a drained replica is a
  plausible operational state; if the package ever took the view that a pool smaller than its read
  list is always an incident, the naming would move to the warning branch and `--strict` would
  fail it.
- **If the metadata gained a schema.** A fourth setting, or a `weight` with a documented range,
  would want the floors declared once — in the resolver — and read from there rather than restated
  here. That is the same "one place" move `ConfigValue` made for typed reads.
- **If the doctor could ask the resolver for the pool's exclusions.** `WeightResolver` knows
  exactly which replicas it dropped and why; it returns only the survivors. Reporting the reason
  from the resolver would remove the floors from this row entirely, at the cost of a public method
  whose shape is "what I did not use".
- **If the read list were validated instead of reported.** A config-validation pass at boot —
  rejecting metadata that is not a number — would make this row's failure unreachable, which is
  the better end state and a much larger change.

## Files

| File | Role |
|---|---|
| `src/Console/Commands/DbDoctor.php` | `replicaMetadata()`, `metadataProblems()`, `disabledReplicas()`, `describeMetadata()`, `isNumeric()`, `aboveFloor()` — the row and its rule |
| `src/Database/Weighted/WeightedDatabaseManager.php` | `replicaKey()` made public, so a replica the pool has dropped can be named |
| `src/Database/Weighted/WeightResolver.php` | `resolveWeight()` and `buildPool()` — the arithmetic and the drop the row is judging |
| `tests/Unit/Console/DbDoctorTest.php` | the eight cases above |
| `README.md` | the row's line in the `db:doctor` table, and the paragraph explaining pool versus read list |
