# What does a resolver say about the replicas it did not use?

A design record for `WeightResolver::resolveWithExclusions()`, `WeightedDatabaseManager::poolExclusions()`,
and the `leaves` flag they replaced.

A pool is a shorter list than the read config that produced it, and three different things can take a
replica out of it: `weight: 0`, which the read list means as a disable; a weight the package refuses to
read, which `ConfigValue` falls back to `0` and which therefore removes the replica too; and the health
filter the caller supplied, which is applied to a pool this class has already built. `resolve()` returned
the survivors and nothing else, so every consumer of it could see only the *length* of the loss. The
`db:doctor` row reconstructed it by classifying the read list a second time, the cool-down warning said
"all replicas" without naming one, and nothing else could say which replica was missing at all. This
document records what the resolver reports instead, which alternatives were considered, and why they were
not taken.

Everything below is implemented in `WeightResolver::resolveWithExclusions()` and `exclusion()`,
`WeightedDatabaseManager::poolExclusions()` and `pickReplica()`, and `DbDoctor::replicaMetadata()`. The
rules are pinned by the tests named at the end.

---

## The answer in one line

**One resolution returns a pair — the pool, and every replica it did not use with the reason for each —
and the pair partitions the read list**, so "the pool is one shorter than the config" is answered by
reading a reason instead of comparing two lists. `resolve()` is that pair's first half, for the read path
where a per-query drop is not news.

---

## Why this needed deciding at all

### 1. The exclusion is the invisible half of a routing decision

Everything the package reports about a replica set is derived from the replicas that *survived*:
`replicaStatus()` prints their weights and shares, `/health/db` embeds that table, and SWRR steps over it.
The replicas that did not survive are the ones a misconfiguration is made of — and a pool's size is the
only evidence they existed. A count short by one is exactly the shape of a silent failure.

### 2. "Why" is not recoverable from a shorter list

Two of the three reasons mean the installation is wrong; one of them is a decision the operator made on
purpose. A replica missing from a pool of two could be a drain, a typo, or a replica in cool-down, and no
amount of looking at the survivors distinguishes them. Only the thing that did the dropping knows.

### 3. The row was compensating, and a log line was lying

`DbDoctor::replicaMetadata()` classified the read list itself to work out which replicas were missing —
a second reader of a decision the resolver had already made — and `pickReplica()`'s warning on an empty
pool said `All replicas in cool-down; resetting health circuits` with `connection` in its context and no
replicas in it. A log line about a shorter list, written by the code that could have named every entry.

---

## The candidates

| | candidate | cost | verdict |
|---|---|---|---|
| A | nothing — `resolve()` returns the pool, as it does now | every consumer infers the exclusion from a length, and the row re-derives it | **rejected** — this is the problem |
| B | a second public method (`excluded()`) the caller calls alongside `resolve()` | two resolutions of one input, so the two answers can come from different states; a caller that forgets the second is back to A silently | **rejected** |
| C | log the drop inside `resolve()` | the read path runs per query, so it is a log line per query about a state that cannot be acted on there | **rejected** |
| D | keep the dropped replicas in the pool with `weight: 0` and a flag | every consumer has to filter the pool anyway, and SWRR would be handed entries it must never weight | **rejected** |
| E | a `ResolvedPool` value object | this class already speaks array shapes (`resolve()`, `replicaStatus()`), and one pair of lists does not need a class | **rejected** |
| F | `resolveWithExclusions()` returning `{pool, excluded}`, with `resolve()` delegating | one resolution, one cache entry, and `resolve()`'s callers unchanged | **chosen** |
| G | report the health filter's exclusions, as if they were a property of the installation | mixes a per-call predicate into a cacheable fact, and a preflight would fail on a transient state | **rejected** |
| H | report all three reasons, the filter's marked as its own and never cached | the caller is told the truth about the list it was handed, and the configuration half stays stable | **chosen** |

### B — a method per question

The cheapest API, and it is the same mistake `resolve()` made: two calls that must agree. They would
share a cache, so the second is usually free — and exactly when it is not, it is because the
configuration moved between them, which is the case where a disagreement is a *report* about a
configuration that has already changed. A caller who forgets the second call is the state being fixed,
silently. One call that returns both makes the pair indivisible.

### C — log it where it happens

Attractive because `pickReplica()` is already the place that acts on it. It fails twice. The read path
is a per-query path: a warning per query is noise that buries the one query that mattered. And a log is
not a surface a *report* can read — `db:doctor` and the health payload would still be reconstructing the
same fact by hand.

### D — a pool of everything, with a zero

Keeping the dropped replicas in the pool would make the length honest, at the cost of moving the filter
to every consumer — including SWRR, which would have to be trusted never to weight a `0`. The pool's
whole meaning is "the replicas that can serve a read", and a decision that adds a second meaning to it
is a decision that has to be honoured in every place the pool is used.

### G and H — does the health filter belong in the report?

Yes, and marked differently. The question the report answers is "what did this resolution leave out",
and `resolve()`'s contract has always been that the filter is part of the resolution — so leaving the
filtered replicas out of the report keeps the length unexplained for the one caller that passes a filter.

What must not happen is the filter's exclusions being remembered as a property of the installation. They
are computed per call, appended to a *copy* of the cached list, and gone by the next call: `filtered` is
the reason that says "this call excluded it", and it is why `WeightedDatabaseManager::poolExclusions()`
does not apply a filter at all. Which replicas are out of rotation right now is a fact about the health
monitor, and `replicaStatus()`'s `healthy` flag and `healthSummary()`'s `failure_count` are where it is
published.

---

## The chosen mechanism, in full

```php
$resolved = $resolver->resolveWithExclusions($connection, $replicas, $cpu, $ram, $formula, $healthFilter);

$resolved['pool'];      // list<Entry>     — what resolve() has always returned
$resolved['excluded'];  // list<Exclusion> — every configured replica not in it, with the reason
```

`Exclusion` is `{config, key, weight, reason, detail}`: the replica's own config, its stable `host:port`
identity, the weight it would have carried, which of the three reasons applies, and the sentence for it.
`reason` is a closed vocabulary — a reason a reader has to interpret is a reason nothing can select on.

| reason | meaning | who knows it |
|---|---|---|
| `refused` | a weight the package will not read, read as the disable value instead | `ReplicaMetadata::refusals()` |
| `disabled` | `weight: 0`, which the read list means as a disable | `ReplicaMetadata::disables()` |
| `filtered` | the health filter the caller supplied rejected it for this call | the caller |

### The pair partitions the read list, and that is the point

Every replica passed to the resolution appears exactly once across `pool` and `excluded`. That is the
property a report needs and the reason this is not a "supplementary" list: a consumer that has the pool
and the exclusions has the whole read list, with a reason attached to the half it was never shown. Both
lists are sorted by the replica's stable key, so a report does not reorder between calls and read as if
something had changed.

### Which reason a departure was

A weight that `max(ReplicaMetadata::WEIGHT_FLOOR, ConfigValue::int(…))` reads as that floor is
exactly one of two things, and
`ReplicaMetadata` is what says which: the value the read list means as a disable, or a value the package
will not read and substitutes `0` for. The two cannot both hold of one written value, so the resolver
reads the refusals first and what falls through is the disable — a fact rather than a default, which is
why `ReplicaMetadataTest` asserts the exclusivity for every spelling of `0` and for the values that are
not numbers at all.

That is also where the old `leaves` flag went. It was a prediction, made by the classifier, of what the
pool would look like — and the resolver is the thing that knows. Reporting the consequence from the
resolver removed the flag from `ReplicaMetadata::refusals()`, the re-derivation
(`DbDoctor::disabledReplicas()`) from the row, and the guess from anything that wants to know whether a
refusal cost a replica.

### What the read path does with it

`pickReplica()` resolves with the exclusions and passes them to the warning it already wrote, so the one
line that reports an empty pool now names what was in it:

```php
Log::warning('[WeightedDB] All replicas in cool-down; resetting health circuits.', [
    'connection' => $connectionName,
    'excluded' => [['replica' => '10.1.0.2:5432', 'reason' => 'filtered'], …],
]);
```

### What a report does with it

`WeightedDatabaseManager::poolExclusions($connection)` is the other half of `replicaStatus()`, deliberately
with the same shape of answer and the same absence of a filter: that method returns the pool, this
returns what it does not hold, and the two together are the read list. `db:doctor`'s `replica metadata`
row reads both — `replicaStatus()` for the count and the total weight, `poolExclusions()` for who is
missing and why — and states the consequence of a refusal instead of predicting it.

---

## Tests that pin the rules

| Rule | Test |
|---|---|
| a disabled replica comes back as an exclusion, with the disable's sentence | `WeightResolverTest::test_a_disabled_replica_is_reported_as_an_exclusion` |
| a weight that cannot be read is `refused`, not `disabled` | `test_a_weight_it_cannot_read_is_reported_as_a_refusal_rather_than_a_disable` |
| a filter's exclusion is reported with its own reason and is not remembered | `test_a_replica_the_health_filter_rejects_is_reported_with_the_reason_for_it` |
| `pool` and `excluded` partition the read list | `test_the_pool_and_the_exclusions_partition_the_read_list` |
| both lists are sorted by key, and the exclusions come out of the cached resolution | `test_the_exclusions_are_cached_with_the_pool_and_sorted_by_key` |
| `resolve()` is the pool half of one cached computation | `test_resolve_is_the_pool_alone` |
| a weight is a refusal or a disable, never both and never neither | `ReplicaMetadataTest::test_a_weight_is_either_a_refusal_or_the_documented_disable_and_never_both` |
| the manager's two halves are the read list, with each reason named | `WeightedDatabaseServiceProviderTest::test_the_pool_and_the_exclusions_are_the_two_halves_of_the_read_list` |
| the exclusions are the configuration, not a replica in cool-down | `test_the_exclusions_are_the_configuration_and_not_a_replica_in_cool_down` |
| the row's clause states what happened rather than predicting it | `DbDoctorTest::test_the_metadata_row_fails_and_names_a_replica_whose_weight_cannot_be_read` (and the row's other cases) |

Mutations this decision has been checked against: reporting a filtered replica under the `disabled`
reason instead of its own (one failure, the filter's reason), dropping the `usort()` of the exclusions
(one failure, the ordering test), and pinning the row's clause to "the replica stays in the pool"
(one failure, the weight case) — the last of which is the change's whole point, since that clause is
now a statement about what the resolver did rather than a prediction about a value. What the suite does
*not* distinguish is the order the two configuration reasons are asked in: a weight the resolver reads
as `0` is a refusal or a disable and never both, so either order reaches the same answer, and the
property that makes that true is asserted rather than the branch that reads it.

---

## Known limitations

- **A filtered exclusion is a fact about one call, not about the installation.** `poolExclusions()` does
  not apply a filter, so it cannot answer "why is the pool my reads are using short right now" — that
  question needs the same filter the read path passes, and today only `resolveWithExclusions()` with that
  filter answers it.
- **`detail` for a filtered exclusion says only what the resolver knows.** It does not know the filter
  was about health, so it says who excluded the replica and not why the caller decided to. A caller that
  wants the reason has it; the resolver deliberately does not guess at a closure's intent.
- **The reason vocabulary is closed at three.** A fourth kind of exclusion — a per-replica lag rule, a
  connection that is disabled at the driver level — would have to be added here *and* to every consumer
  that groups by reason, because a reason nothing handles is an exclusion nothing reports.
- **The report is per resolution, so a caller has to ask for the right one.** `poolExclusions()` resolves
  with the connection's tunables and no filter; a caller that resolves with different tunables gets a
  different pair, and both are correct for the input they were given.
- **The exclusions are cached with the pool.** A hot-reloaded config that does not reach `flush()` serves
  stale reasons in exactly the way it already served a stale pool — the same caveat, one list longer.
- **`resolve()` still hides the second half.** Everything that already consumed the pool keeps working
  and keeps not knowing; the report is opt-in, so a new consumer has to choose it.

## What would change this decision

- **If the health filter became a named policy rather than a closure.** A filter that could describe
  itself would let `filtered` carry the caller's reason, and `detail` would stop being the weakest
  sentence in the set.
- **If a surface wanted the live read pool's exclusions.** Then `poolExclusions()` grows a filter (or a
  second method), and the two questions — what the configuration excludes, and what this moment excludes
  — would have to be distinguishable in the return rather than by which method was called.
- **If a consumer wanted one structure instead of a pair.** Candidate E comes back the moment two
  consumers want to pass the result around rather than read two keys of it.
- **If the exclusion had to travel to a caller that has no resolver.** The boot audit reads configuration
  before the manager exists, so it classifies the read list through `ReplicaMetadata` directly; a report
  that had to be the *same object* everywhere would need the resolver to be constructible from config
  alone, which is a different class.

## Files

| File | Role |
|---|---|
| `src/Database/Weighted/WeightResolver.php` | `resolveWithExclusions()`, `exclusion()`, the three `EXCLUDED_*` reasons, and the cached pair |
| `src/Database/Weighted/WeightedDatabaseManager.php` | `poolExclusions()` (the other half of `replicaStatus()`) and `pickReplica()`'s warning, which now names what it excluded |
| `src/Support/ReplicaMetadata.php` | `refusals()`, `disables()`, `DISABLED` — the reading the reasons are built from, and the exclusivity that makes the fall-through a fact |
| `src/Console/Commands/DbDoctor.php` | `replicaMetadata()` — the row, reading the resolver's report instead of re-deriving it |
| `tests/Unit/Weighted/WeightResolverTest.php` | the three reasons, the partition, the sort, the cache |
| `tests/Unit/Support/ReplicaMetadataTest.php` | the refusal/disable exclusivity, over every spelling of `0` and everything that is not a number |
| `tests/Unit/Weighted/WeightedDatabaseServiceProviderTest.php` | the manager's two halves, and the cool-down boundary |
| `tests/Unit/Console/DbDoctorTest.php` | the row, whose clause is now read rather than predicted |
| `docs/replica-metadata-refusal.md` | the refusal half: which values the resolver will not read |
