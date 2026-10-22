# The health payload

`GET /health/db` answers one JSON object. Each field it publishes has its own reason to exist and
its own tests, which is why this record is not about any of them: it is about the *shape* — which
blocks the object holds, and which keys each block holds — because that is the part of the payload
no test failed over when it changed.

## Why the shape is pinned

A payload is read by programs, not by people: an alert rule comparing `flip.failed`, a deploy gate
running `jq -e '.pinned.ok'`, a dashboard column bound to `pool_repairs.count`. None of them fails
when a block is renamed or a key moves. They read `null`, and `null` here means the documented
nothing — a key that was never recorded is `null` rather than absent, deliberately, so a consumer
tests a value instead of the shape of the object. The same property that makes the payload pleasant
to read makes a rename silent: the gate passes, the dashboard shows a blank column, and the only
place the change appeared was the payload nobody diffed.

So the shape is written down twice and compared with what the endpoint actually publishes:

- the README's table, under **Every block, and every key in it**, because that is where a consumer
  learns the payload;
- this record's table, under **Every path**, because the decision about the shape is a decision and
  not a menu — the open shapes below are the alternatives that were considered and refused.

`DatabaseHealthControllerTest::test_every_block_and_key_the_payload_publishes_is_documented` reads
both tables and a real payload, and fails in three directions: a path the payload publishes and no
table names, a path a table names and the payload has stopped publishing, and a key set that has
drifted inside one path. It compares the two tables with each other first, so a change to one of
them alone is a failure rather than a half-updated contract.

## What the table's cells mean

A `keys` cell is a backticked list, compared as a set — insertion order is a property of the array
literal in `index()` rather than of the contract. An em dash means the path's value is a scalar, a
list, or an object documented under another heading; the guard checks that the path is still
published and does not read inside it.

`<connection>` is the one name the payload chooses at runtime: `replicas` is keyed by the connection
the package follows, so the tables write the placeholder rather than a name belonging to one
fixture.

## The open shapes, and why they are open

- **`replicas` holds one entry, not the host application's inventory.** The subject is the
  connection `db-manager.swrr.connection` names — else `database.default` — so the map has exactly
  one key and the table writes it as `<connection>`. A second entry would be a claim about a
  connection the package does not route.
- **`errors` is a list of sentences** rather than a map, because the sentences are for a person
  reading a 503 and the blocks beside it already carry the machine-readable halves.
- **`audit.findings`, `audit.current.findings`, `pool_probe.pools` and `schedule.causes` are lists
  of objects** whose members are owned by the classes that build them (`BootAudit` for a finding,
  `PoolProbe` for a pool, `ScheduleCauses` for a cause), each with its own record. Pinning them
  here would be a second copy of a shape another guard already holds.
- **`flip.window` and `pgcat.window` are one array**, published twice: `flip` is the verdict the
  endpoint acts on, `pgcat` is the flipper's own snapshot. Its keys are documented key by key under
  **The window block: every key it publishes** in the README, and pinned there by a test of its own,
  which is why this record's table marks both paths with an em dash rather than repeating
  twenty-five names a second time.

## Every path

| path | keys | what it is |
|---|---|---|
| `status` | — | `ok`, or `degraded` when one of the blocks below fails — the field a monitor pages on |
| `pinned` | `checked`, `connection`, `driver`, `error`, `host_ip`, `latency_ms`, `ok`, `query`, `refused`, `source` | the one real query, on the connection the package follows, and the driver's own answer — the block `status` rests on |
| `connector` | `binding`, `connection`, `connection_class`, `connection_driver`, `connection_error`, `connector`, `driver`, `error`, `factory`, `factory_error`, `resolver`, `resolver_applied`, `source` | the class that builds that connection's PDO, reported and never judged |
| `replicas` | `<connection>` | a map holding the one connection the package follows |
| `replicas.<connection>` | `cpu_factor`, `degraded`, `formula`, `next_mode_change_at`, `primary_store`, `ram_factor`, `read_mode`, `replicas`, `resolver_cache_size`, `store`, `store_healthy` | that connection's replica summary: weights, mode and store, as `db:replica-status` prints it |
| `pgcat` | `armed_reason`, `configured_enabled`, `connection`, `driver`, `driver_supported`, `enabled`, `last_mode`, `mismatch`, `reader_window`, `reason`, `resolver_mode`, `schedule`, `warning`, `window` | the flipper's own snapshot, the array `db:pgcat-flip --status` shows |
| `pgcat.reader_window` | `applied`, `direction`, `expected`, `failed`, `in_step`, `reason` | whether the pooler is on the variant the reader windows are asking for |
| `pgcat.schedule` | `applicable`, `age_seconds`, `last_beat_at`, `not_judged`, `reason`, `stale`, `stale_after_seconds` | the schedule record's beat, read off the state file the entry writes |
| `pgcat.window` | — | the boot window inside the snapshot — the same array as `flip.window` |
| `flip` | `applicable`, `failed`, `reason`, `window` | the boot-window verdict, and the one pgcat reading the status rests on |
| `flip.window` | — | the boot window, documented key by key under **The window block: every key it publishes** |
| `schedule` | `applicable`, `age_seconds`, `causes`, `failed`, `interval_minutes`, `last_beat_at`, `missed_runs`, `not_judged`, `reason`, `stale_after_seconds` | whether anything is still running the entry that moves the mode, and why not when it is silent |
| `reader_window` | `applicable`, `applied`, `expected`, `failed`, `reason` | the window verdict alone, for a reader that does not want the flipper's snapshot |
| `pool_probe` | `address`, `admin`, `answered`, `applicable`, `control`, `discriminates`, `enabled`, `failed`, `host`, `judged`, `pools`, `port`, `reason`, `verified`, `warned` | what the running pooler holds, and whether a pool the application reads from is missing |
| `pool_repairs` | `applicable`, `count`, `in_window`, `judged`, `last_at`, `ledger`, `max_repairs`, `reason`, `warned`, `window_seconds` | how often the container has had to repair a pool, whether that rate is past the threshold, and the repairs as a ledger of `at`/`pool`/`mode`/`cleared` entries — the one block that warns rather than fails |
| `audit` | `available`, `checked_at`, `count`, `counts`, `current`, `error`, `findings`, `oldest`, `scope`, `severity` | the boot audit's standing findings, as `db:doctor` reads them |
| `audit.counts` | `error`, `warning` | the standing findings by level |
| `audit.scope` | `app_env`, `connection`, `driver`, `source` | what the boot that wrote them was running as |
| `audit.current` | `available`, `count`, `counts`, `error`, `findings`, `severity` | the same audit re-read live by this process, which is not the same thing as what is on record |
| `audit.current.counts` | `error`, `warning` | the live findings by level |
| `errors` | — | a list of sentences naming what this process could not do, which no block above owns |

## The readiness probe is a second object

`DatabaseHealthController::readiness()` answers the same question a load balancer asks, and it is a
second object rather than a filter on the first: `status` and `pool_probe`, and nothing else. The
table above is `/health/db`'s and does not cover it, because a path added here would be a key the
full payload does not publish — the one direction that guard reads. What keeps the two honest is
narrower than a table: the block is the same array, from the same call, so a key added to
`pool_probe` arrives on both surfaces at once and cannot be documented for one and not the other.

The two keys are fixed because a kubelet's reader is a boolean. `status` is `degraded` on exactly
`pool_probe.failed`, which is the rule `index()` already applies to that block, and `200` otherwise:
an unarmed probe asked nothing, a silent pooler is `warned` rather than failed, and both judgements
wait for the boot window to close. The cost is why the object is not the whole payload — no query, no
replica read, no state store, no boot audit, and no log line, since a probe polled on a timer would
write the degraded line far more often than anybody reads it.

## What this record deliberately does not decide

- **The values.** Each block's own record and tests own its values, its null semantics and what it
  does to `status`: this one owns the names and nothing else.
- **A versioned schema.** The payload is not stamped with a version, because there is one consumer
  contract and it is this file: a second number would have to be kept in step with two tables, and
  the tables are the thing the guard can check.
- **What a host application adds.** A host may wrap the payload in its own envelope; the route is
  the application's, and this record describes the object the controller returns rather than what
  the application serves.
