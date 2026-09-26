# Static analysis

`phpstan.neon.dist` runs **PHPStan level `max`** (level 10) over `src/` with
**zero baseline entries** — no `ignoreErrors`, no generated
`phpstan-baseline.neon`, no `@phpstan-ignore` comments. Every finding was fixed
rather than silenced.

```bash
composer test:types                              # phpstan analyse --ansi (uses the config)
vendor/bin/phpstan analyse --level=9             # check one level explicitly
```

Measured with PHPStan 2.2.14 on PHP 8.4.16.

## The staged burn-down

The package shipped at level 6. Tightening went stage by stage, each stage green
before the next one started, so a regression would have been attributable to a
single class of change.

| Level | Errors before | After stage 1 | After stage 2 | After stage 3 |
|-------|--------------:|--------------:|--------------:|--------------:|
| 6     | 0             | 0             | 0             | 0             |
| 7     | 8             | **0**         | 0             | 0             |
| 8     | 8             | **0**         | 0             | 0             |
| 9     | 58            | 37            | **0**         | 0             |
| 10 (max) | 124        | 124           | 42            | **0**         |

Files carrying the errors at the start: `WeightedDatabaseServiceProvider` 52,
`WeightedDatabaseManager` 21, `DatabaseHealthController` 17,
`PgcatConfigFlipper` 11, `DbProbeReplicas` 10, `DbReplicaStatus` 6,
`WeightResolver` 5, `RedisAtomicStateStore` 1, `WeightedConnectionFactory` 1.

Error classes at level 10 before the burn-down, all of which are now zero:

| Identifier | Count |
|---|---:|
| `cast.string` | 29 |
| `argument.type` | 26 |
| `offsetAccess.nonOffsetAccessible` | 20 |
| `method.nonObject` | 16 |
| `cast.double` | 10 |
| `cast.int` | 9 |
| `encapsedStringPart.nonString` | 6 |
| `return.type` | 5 |
| `binaryOp.invalid` | 2 |
| `foreach.nonIterable` | 1 |

### Stage 1 — level 6 → 8 (8 errors)

The blockers were `cast.string` ×2 (both `(string) $this->argument('connection')`,
whose union includes `array`) and `argument.type` ×6:

- `TimeWindowResolver` declares `list<array{start?: string, end?: string}>` and
  `list<int>`; the provider was passing the raw config array through `(array)`.
  It now normalises through `readerWindows()` / `readerDays()`.
- `DatabaseHealthController` passed `array_keys()` results (`int|string`) to
  `DB::connection()` and to methods that take a connection **name**, and passed
  `array<int|string, …>` to a method documented `array<string, mixed>`.
  Connection names are now strings and the summaries are a real shape.
- Both commands narrowed `$this->argument('connection')` with `is_string()`
  instead of casting a `mixed`-ish union.

Level 8 added nothing on top of level 7 here, so both landed together.

### Stage 2 — level 9 (37 errors at that point)

One root cause covers nearly all of it: **the configuration repository is typed
`mixed`**, so every consumer did `(string) $config->get(...)`. A cast to string
from `mixed` is a lie to static analysis and a hazard at runtime — an array in
the config file casts to the literal `"Array"`.

Fix: `Uak35\WeightedDbManager\Support\ConfigValue`, a dependency-free narrowing
helper (`string`, `int`, `float`, `bool`, `assoc`, `assocList`) that keeps PHP's
cast semantics for scalars and returns the caller's fallback for anything else.
Applied across the provider, manager, flipper, resolver, both commands, the
Redis store and the connection factory. The manager additionally collapsed nine
repeated casts into `weightTunables()` and gained `pickUnweighted()` for the
unweighted read path.

### Stage 3 — level 10 (42 errors at that point)

Three remaining causes:

1. **Untyped container closures** — `function ($app)` is `mixed` at level 10, so
   `$app->make()` and `$app['config']` were unchecked (16 × `method.nonObject`,
   9 × `offsetAccess`, 11 × `argument.type`, all in the provider).
   Fix: `function (Application $app)` plus
   `$app->make(Repository::class)`, which the container's conditional return type
   resolves to the config repository interface — so a host app that rebinds the
   interface still wins.
2. **Laravel's own untyped seams** — `DatabaseManager::configuration()`,
   `ConnectionFactory::getReadConfig()` and `Arr::except()` return plain `array`.
   Fix: wrap them in `ConfigValue::assoc()` where the package promises
   `array<string, mixed>`.
3. **Imprecise shape docblocks** — `healthSummary()` declared
   `replicas: list<array{...}>` (a literal ellipsis, so every field was `mixed`).
   Fix: the real shape, which also typed `db:replica-status`'s table rows.

## Behaviour changes that came with the typing

Typing a `mixed` is never purely cosmetic; four behaviours moved, all
deliberately:

- **`swrr.reader_days` as a bare scalar now means that day.** `.env` values are
  strings, and the old `(array) '3'` produced `['3']`, which never matched
  `TimeWindowResolver`'s strict `in_array($dow, $readerDays, true)` — so reads
  went to the writer all day, every day. Covered by
  `test_a_scalar_reader_day_from_config_still_means_that_day`.
- **Unusable `reader_windows` entries are dropped**, and if nothing valid
  remains the resolver stays in its documented always-readers mode instead of
  forcing every read onto the writer.
- **Non-scalar config values fall back to their documented default** instead of
  casting to `"Array"`.
- **A `db.factory` binding that is not a `WeightedConnectionFactory` raises a
  `RuntimeException`** naming the binding when `db` is resolved, instead of a
  type error deeper in the constructor. Weighted routing only happens inside the
  factory, so a host app that replaces it has to be told.

`ConfigValue::bool()` keeps PHP's cast semantics on purpose — `'false'` is
truthy, exactly as `(bool) 'false'` was — and the test suite asserts that so the
docblock cannot drift.

## If a new error appears

1. Reproduce it at the level the config uses (`composer test:types`).
2. Fix it. Almost every historical error here came from reading an untyped
   config value — `ConfigValue::*()` is usually the whole fix.
3. Only if the error comes from a third-party type you cannot influence, prefer
   an `ignoreErrors` entry **with a message and a link to the upstream issue**
   over a baseline file: a baseline hides not just today's error but every new
   error added later to the same file, which is how a level silently decays.

Re-measure the whole ladder with:

```bash
for L in 6 7 8 9 10; do
  echo "level $L: $(vendor/bin/phpstan analyse --no-progress --level=$L --error-format=json \
    | php -r 'echo json_decode(stream_get_contents(STDIN), true)["totals"]["file_errors"] ?? "?";') errors"
done
```
