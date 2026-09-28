# What should the package do with replica metadata it cannot read?

A design record for `db:doctor`'s `replica metadata` row.

A replica is sized by three settings — `weight`, or `cpu_cores` with `ram_gb` — and
`WeightResolver::resolveWeight()` reads each of them at a floor of its own
(`ReplicaMetadata::WEIGHT_FLOOR` for a weight, `CORES_FLOOR` for a core count). `ConfigValue`
falls back for anything that is not a number, so
`'weight' => 'heavy'` is read as `0` … and `0` is documented, in that same method, as the way a
replica is *disabled*. `buildPool()` then drops it. An installation whose weight was a typo
therefore lost a replica from the pool without a word, and the row that exists to catch a
mis-sized pool reported the pool that was left — a count short by one, printed as health. This
document records what the package does instead, which alternatives were considered, and why they
were not taken.

There are two halves to that, and they answer different processes. The row is a preflight:
somebody runs `db:doctor` and reads it. The pool shrinks when the configuration is read, which is
on the boot — usually long before anybody looks. So the value is now refused at boot as well, at
`error` level under its own finding key, which is the package's existing mechanism for a value it
will not interpret on the operator's behalf. Both halves read one rule,
`Support\ReplicaMetadata`, so they cannot name the same value differently.

Everything below is implemented in `Support\ReplicaMetadata` (the reading, the sentence, and the
replica's identity), `WeightedDatabaseServiceProvider::replicaMetadataFindings()` (the refusal at
boot), and `DbDoctor::replicaMetadata()` (the row).
`WeightedDatabaseManager::replicaKey()` and `WeightResolver`'s pool keys both delegate to
`ReplicaMetadata::key()`. The rules are pinned by the tests named at the end.

One thing the rows no longer do is *predict* what a refusal costs the pool. `refusals()` says which
values the resolver will not read; whether a replica left because of one is a fact about what the
resolver did, and it reports it — see [pool-exclusions.md](pool-exclusions.md). That is why there is
no `leaves` flag here, and why the row stopped re-deriving which replicas are missing.

---

## The answer in one line

**The value is refused at boot and failed by the row, both from one classifier that reads the
*configured* replicas and every metadata value the resolver does not read as written, naming the
replica and the value** — saying whether that replica left the pool or stayed in it sized as
something else. `weight: 0` is the one difference the package means, so a replica disabled that
way is refused by neither; the row names it in the count instead. Nothing throws and nothing is
repaired: the refusal is a report, at the moment the pool shrinks rather than after.

---

## Why this needed deciding at all

### 1. The row's question is not "does this installation look weighted"

A replica with `'weight' => 'heavy'` looks weighted. So does the table, so does the health
payload, so does the config file. The row's actual question is whether the pool is the pool the
read list describes — and the only evidence that it is not is the pool's *size*, which is the one
thing `replicaStatus()` cannot report, because it returns the replicas that survived.

### 2. The resolver's fallback for a weight is the operator's own off switch

`max(ReplicaMetadata::WEIGHT_FLOOR, ConfigValue::int(…))`, where `resolveWeight()`'s docblock says
"0 = disabled", and `buildPool()` skips `weight <= 0`. So there is no number to distinguish "read as 0 because you
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
| H | refuse at boot from the same classifier: an `error` finding, one key per setting, no throw | the resolver still substitutes, so the pool still shrinks — what changes is that the shrink is logged, dated and paged | **chosen** — the other half of D |

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

### H — refuse at boot, without throwing

Chosen, and it is not candidate F. F would make the application stop, which is the one thing a
read-routing misconfiguration must not do — a diagnostic that takes the site down over a
configuration a deploy gate could have caught is a worse outcome than the shrink it prevents. What
H refuses is the *value*: the boot reads the configured replicas through the same classifier the
row uses, logs an `error` line per setting, remembers it under its own key, and leaves the
resolver's arithmetic exactly as it was.

That is the same move the package already makes for `swrr.reader_windows` and the three switches:
the value is input the package will not interpret on the operator's behalf, the report says which
value and what it was read as, and the package carries on with the value it documents. The reason
this one matters more than the others is what the substitution costs — a reader window that is
refused leaves routing permissive, while a weight that is refused removes a replica from the pool.

It is deliberately *not* a repair (candidate E): the pool still shrinks, and `db:doctor` still
fails. A finding that silently changed which replicas serve reads would be routing configured by
whichever values happened to parse.

### E — repair the value

Reading `'weight' => 'heavy'` as anything means choosing a size for a machine nobody described,
and the corrected value is not what the operator wrote; the next person to read the config file
sees one thing and gets another. `weight: false` shows why the idea has no floor: the resolver
reads it as the documented disable, so the "repair" would be the value that removed the replica.

---

## The chosen mechanism, in full

### The judgement is the resolver's arithmetic, not a second copy of it

`WeightResolver` reads the three settings at the three `*_FLOOR` constants declared here —
`max(WEIGHT_FLOOR, ConfigValue::int(…))`, `max(CORES_FLOOR, ConfigValue::int(…, CORES_FLOOR))`
and `max(RAM_FLOOR, ConfigValue::float(…))` — and `ConfigValue` falls back for anything that is
not a number. So a written value stops being the value routing uses in exactly two ways:

1. **it is not a number**, so a fallback is read instead; or
2. **it is a number under the floor its key is read at** — `WEIGHT_FLOOR` for a weight,
   `CORES_FLOOR` for a core count, `RAM_FLOOR` for memory.

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

All of that lives in `Support\ReplicaMetadata::refusals()`, and the row and the boot both read it
rather than restating it. That is the second version of this decision: the rule used to exist once
as PHP in the row, describing arithmetic that existed separately in the resolver — which is a
report and a behaviour that can drift apart, and it is the drift the row's own limitation list
used to admit no test could catch. One class, two callers, and the sentence comes out of the class
as well, so a log line and a preflight cannot describe the same value in two ways either.

The third version is about the numbers inside that rule. The floors used to be literals here
(`1.0` for cores, `0.0` for memory) *and* `max()` arguments in `WeightResolver::resolveWeight()` —
the same rule written twice, one copy per reader, agreeing by inspection. They are now
`ReplicaMetadata::WEIGHT_FLOOR`, `CORES_FLOOR` and `RAM_FLOOR`, public constants that the resolver
clamps at, so "the value routing uses" has one definition rather than two that happen to match.

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
PASS  replica metadata    the pool on [pgsql] holds 1 of the 2 configured replicas (total weight 10): 10.1.0.2:5432 — weight is 0, which is how the read list takes a replica out of the pool
```

`2 replicas on [pgsql]` on an installation with three is the same quiet shrink through the front
door, whichever mechanism produced it.

That name is not the passing branch's alone. The row's other two branches are about *values*, and
one of them returns before the pool is described at all, so a drain was named only when nothing else
was wrong: a weight that cannot be read hid a replica somebody had switched off until the deploy
after the repair, and a pool whose every replica is `weight: 0` was reported as a fact about the
pool. Both carry the same clause now, from one spelling of it:

```
replica metadata the resolver cannot read on [pgsql]: [10.1.0.3:5432] weight is "heavy", which the
resolver reads as 0 — a replica weighted 0 leaves the pool, so reads are routed over a smaller pool
than the read list describes and nothing else reports that it happened. Also drained on purpose:
10.1.0.2:5432 — weight is 0, which is how the read list takes a replica out of the pool.

every replica on [pgsql] resolves to weight 0 — reads cannot be routed: 10.1.0.1:5432, 10.1.0.2:5432
— weight is 0, which is how the read list takes a replica out of the pool
```

The rule under all three branches is one sentence, and it is asserted as one: every replica the pool
does not hold is named in the row.

### The symptom stops being blamed for the cause

With every replica's weight unreadable, the row used to print `every replica on [pgsql] resolves to
weight 0 — reads cannot be routed`. True, and useless: "every replica is deliberately drained" and
"every replica's weight is a typo" are the same picture without the values, and the values are the
repair. The unreadable case is now reported before that sentence can be reached, and a test asserts
both halves — the value is named, the symptom is not. The sentence is still reachable, and rightly
so, when the replicas really were switched off on purpose — and there it names them, because a
disable is not a value an operator repairs but a replica they chose to take out.

### Naming a replica the pool has dropped

`replicaStatus()` returns the pooled replicas, which is precisely the wrong list here, so the row
walks the configured `read` list. That needs an identity for a replica that has no status row, so
`WeightedDatabaseManager::replicaKey()` — already *the* spelling of "which replica", since the
health monitor keys failures by it — became public. The alternative was a second spelling of a
replica's identity in the doctor, which is a second thing to keep in step with the health record.

---

### The refusal at boot, in full

The boot half is `WeightedDatabaseServiceProvider::replicaMetadataFindings()`, and it is
assembled the way the reader and switch refusals are: one `BootAuditFinding` per setting, at
`level: 'error'`, with a resolution sentence and a context that names what was refused.

| setting | key | what the refusal leaves behind |
|---|---|---|
| `weight` | `database.read.weight.refused` | the replica leaves the pool |
| `cpu_cores` | `database.read.cpu_cores.refused` | the replica stays, weighted as one core |
| `ram_gb` | `database.read.ram_gb.refused` | the replica stays, weighted with no memory |

Three keys rather than one, for the reason the switches have three: the record remembers a finding
by its key, so a single key for a replica's metadata would resolve all three settings when one of
them was fixed, and would date the other two from the wrong boot. It also keeps the consequence
separate, which matters here — a refused weight removes a member of the pool and a refused memory
figure does not, and one sentence covering both would have to describe the quiet case with the loud
one's words.

**The key is the setting's own path** — `database.connections.*.read.*.weight`, abbreviated to
`database.read.weight` — rather than one of the `swrr.*` names. The read list is not an `swrr`
setting: it is the connection's, it is where the operator edits it, and it is what they would grep
for. The connection the package follows is in the finding's context instead, because a key that
carried the connection's name would resolve the wrong warning the day that name changed.

**The boot reads the config repository, not the manager.** Resolving `db` to ask it would build the
store, the factory and the pool during a diagnostic whose whole cost is meant to be reading a file,
and the question is only ever about the configured list. `ReplicaMetadata::key()` is what makes
that possible: the replica's identity is the same string whether it is a boot naming a replica the
pool is about to drop or the health monitor counting that replica's failures, and it moved into
the classifier rather than being spelled a third time here.

**`database.read.*` is a key every boot checks**, clean or not, so the finding is not permanent: the
boot that reads a readable value logs the resolution once and forgets it. That is also why the
resolution sentence is about the *values* rather than about a repair — the same key stops reporting
if the value is fixed, the replica is dropped from the read list, or the whole list is removed, and
"no replica's weight is refused any more: every value written under it is a number" is true of all
three.

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
| a drain is named beside a value the resolver cannot read | `test_the_metadata_row_names_a_drained_replica_beside_the_value_it_cannot_read` |
| a pool whose every replica is drained names them | `test_the_metadata_row_names_every_replica_when_every_one_is_drained` |
| every replica the pool does not hold is named, whatever it left over | `test_the_metadata_row_names_every_replica_the_pool_does_not_hold` (five read lists) |
| no metadata at all is still the row's warning | `test_it_warns_when_the_replicas_carry_no_weight_metadata` |
| the reading itself, and the identity: which values are readable, which are the documented disable, what each refusal says, and how a replica is keyed | `ReplicaMetadataTest` (nine cases) |
| the floors are one boundary, not two: the resolver is driven to each constant and asked at `floor - 1` and at `floor`, and the classifier is asked the same values | `WeightResolverTest::test_the_floors_the_classifier_reads_at_are_the_floors_the_resolver_clamps_at` |
| an unreadable weight is refused at boot, at `error` level, naming the replica and the value, under `database.read.weight.refused` | `WeightedDatabaseServiceProviderTest::test_replica_metadata_the_resolver_cannot_read_is_refused_at_boot` |
| each setting has its own key, and each says what the refusal cost the pool | `test_each_size_setting_is_refused_under_its_own_key` |
| a weight is a refusal or the documented disable, and the exclusion report's reasons are read from exactly that | `ReplicaMetadataTest::test_a_weight_is_either_a_refusal_or_the_documented_disable_and_never_both` |
| the resolver reports what it did not use, with the reason, for all three reasons | `WeightResolverTest` (five cases, plus pool/exclusion partition) |
| the pool and the exclusions are the two halves of the read list, for a manager and its connection | `WeightedDatabaseServiceProviderTest::test_the_pool_and_the_exclusions_are_the_two_halves_of_the_read_list` |
| a replica in cool-down is out of the read pool and is *not* a configuration exclusion | `test_the_exclusions_are_the_configuration_and_not_a_replica_in_cool_down` |
| `weight: 0` is a drain and not a refusal | `test_a_replica_disabled_with_weight_zero_is_not_refused` |
| metadata that reads as written is not refused — the vacuity guard | `test_replica_metadata_that_reads_as_written_is_not_refused` |
| a repaired value closes the finding out, and so does removing the read list | `test_a_refused_replica_weight_is_closed_out_when_the_value_is_repaired`, `test_removing_the_read_list_closes_a_refused_metadata_finding_out` |

Mutations this decision has been checked against, each against the eight tests above: stopping the
naming of a documented disable (one failure, the disabled case), reporting only non-numbers so the
floor clause goes (two failures — the negative weight and the truncation), reporting no unreadable
metadata at all (seven failures; the disabled case survives, which is the point of it being a
separate branch), and blaming a smaller pool in every case rather than only when a weight was read
as 0 (one failure, the memory case, whose sentence says the replica stays). Reverting the drained
clause out of the refusal branch fails four tests, and out of the empty-pool branch two, so neither
branch can go back to reporting a value while the replica that is not answering reads goes unnamed.

The two halves have since been checked against each other, which is the property this decision is
now built on: dropping the boot's call into the findings list fails five boot tests, and making
`ReplicaMetadata::refusals()` return nothing fails sixteen across the classifier, the row and the
boot — so neither caller can stop refusing without the suite saying so, and neither can drift from
the other without a test naming the value differently.

---

## Known limitations

- **A value that reads as written is not checked against anything.** `weight: 1000000` is read as
  written and the row passes; so is a `cpu_cores` of 64 on a machine with two. The row judges
  readability, not plausibility, and `db:replica-status` is where the shares it produces are
  visible.
- **The refusal does not change what routing does.** The pool still shrinks, and the value is still
  read the way the resolver reads it. The finding makes the shrink non-silent, not impossible —
  which is the deliberate difference between this and candidate E. An installation that wants the
  replica kept has to fix the value; nothing here keeps it for them.
- **One key per setting, so two bad replicas share a date.** The sentence and the context name every
  offending replica, but the record holds one entry per key, so a second replica whose weight is
  unreadable joins the first finding rather than adding one: `first_reported_at` then means "weights
  have been unreadable since", not "this replica has". The alternative — a key per replica — would
  strand an entry forever when a replica was removed from the read list, because a key that is not
  checked is carried over rather than resolved, and the audit would be reporting a replica that no
  longer exists.
- **The floors are declared once, and the arithmetic reads them.** They were `max()` arguments
  inside `WeightResolver::resolveWeight()` *and* numbers written out again in this class — two copies
  of one rule, and the thing this list used to admit no test could catch without reading the resolver.
  They are now `WEIGHT_FLOOR`, `CORES_FLOOR` and `RAM_FLOOR`, public constants here that the resolver
  clamps at, so where a boundary is has one definition. The constants live in this class rather than
  in `WeightResolver` because the boot audit classifies a read list without the manager ever being
  resolved — and because the resolver already reads this class for the refusals and the replica's
  identity, so the reverse would be a cycle for a number.
- **A constant can still be ignored.** Declaring the floors once makes them agree today; it does not
  make the resolver use them. That is what the boundary test is for:
  `WeightResolverTest::test_the_floors_the_classifier_reads_at_are_the_floors_the_resolver_clamps_at`
  drives the resolver to each constant and asks both halves about `floor - 1` and about `floor`, so a
  clamp moved by one fails a test rather than a routing decision. It is written against the weights
  the floor produces rather than against the replica at the floor, because a moved clamp moves both
  of those together and an equality between them would keep passing.
- **One number in `resolveWeight()` is deliberately not a floor.** Its final `max(1, $weight)` is the
  formula's own minimum — a replica that declares hardware is never weighted nothing — and nothing
  outside that method has an opinion about it, so it is not a constant. A reader comparing it with
  `CORES_FLOOR` will find the same value and a different fact.
- **The row's verdict and the resolver's consequence are two readings of one configuration.** A
  refused value is reported here; what happened to the replica because of it is read from the
  resolver. They cannot disagree about the *value* — the reason the resolver gives is this class's
  sentence — but a caller reading the row's `FAIL` and then the resolver's exclusion list is
  reading two producers, and the row is deliberately not one of them.
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

- **If the resolver stopped substituting for a value it cannot read.** A refusal would then cost
  routing something visible — an error, a skipped replica that is logged at the point of the read —
  and the finding would be a warning ahead of it rather than the only place the removal is visible.
  This is the boundary the boot refusal stops at: nothing in the package throws, because a
  read-routing misconfiguration must not stop the application answering requests.
- **If `weight: 0` became a `WARN`.** A drain is a choice today because a drained replica is a
  plausible operational state; if the package ever took the view that a pool smaller than its read
  list is always an incident, the naming would move to the warning branch and `--strict` would
  fail it.
- **If the metadata gained a schema.** A fourth setting, or a `weight` with a documented range, would
  want its floor declared once as a fourth constant beside the three, with the boundary test driving
  the resolver to it — the same "one place" move `ConfigValue` made for typed reads. A *range* rather
  than a floor would want more than a constant: the check would stop being "is it under the value the
  resolver clamps at" and become a validation the resolver would have to share, which is a different
  decision from this one.
- **Done: the doctor asks the resolver for the pool's exclusions.** `WeightResolver` knew exactly
  which replicas it dropped and why while returning only the survivors, and this row compensated by
  re-deriving the names from the read list. It now reads `resolveWithExclusions()`'s report instead,
  so an exclusion is reported rather than inferred, and the `leaves` flag this class used to carry —
  a prediction of what the pool would look like — is gone. That decision, its candidates and its
  reasons are in [pool-exclusions.md](pool-exclusions.md).
- **If the boot validation were a repair.** The boot now refuses the value, which is input the
  package will not interpret; a step that *corrected* it — reading a weight that is not a number as
  some default — would make the row's failure unreachable, and is the same objection candidate E
  had: it invents a size nobody wrote, and for `weight` the value it would have to invent is the
  disable.

## Files

| File | Role |
|---|---|
| `src/Support/ReplicaMetadata.php` | `refusals()`, `disables()`, `key()`, `describe()`, `isReadable()`, `atOrAboveFloor()`, and the three `*_FLOOR` constants the resolver clamps at — the one reading, its sentence, its floors, and the replica's identity |
| `src/Console/Commands/DbDoctor.php` | `replicaMetadata()` — the row: the class above for which values are refused, `poolExclusions()` for what the pool does not hold |
| `src/Providers/WeightedDatabaseServiceProvider.php` | `replicaMetadataFindings()` — the `error`-level refusal at boot, the three keys and their consequences |
| `src/Database/Weighted/WeightedDatabaseManager.php` | `replicaKey()` made public, so a replica the pool has dropped can be named; it delegates to the class above |
| `src/Database/Weighted/WeightResolver.php` | `resolveWeight()`, which clamps at this class's floors rather than at numbers of its own, and `buildPool()` — the arithmetic and the drop the row and the finding are judging; `resolveWithExclusions()` and `exclusion()` are the report they now read |
| `tests/Unit/Support/ReplicaMetadataTest.php` | the reading itself: readable values, the documented disable, each refusal's sentence, the refusal/disable exclusivity, and how a replica is keyed |
| `tests/Unit/Console/DbDoctorTest.php` | the row's eight cases above |
| `tests/Unit/Weighted/WeightResolverTest.php` | the exclusions: each of the three reasons, the partition, and the cache |
| `tests/Unit/Weighted/WeightedDatabaseServiceProviderTest.php` | the boot refusal (key, level, sentence, repair, vacuity guard) and the manager's two halves of a read list |
| `docs/pool-exclusions.md` | the decision behind the exclusion report, and why the row stopped predicting |
| `README.md` | the row's line in the `db:doctor` table, the paragraph explaining pool versus read list, and the paragraph on the boot refusal |
