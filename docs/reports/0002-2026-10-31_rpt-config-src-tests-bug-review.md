# Bug review: config, src and tests

Created: 2026-10-31 (Asia/Karachi). Reviewed checkout: `5a26d6b`, including the working tree present during review.

## Scope and method

Reviewed the configuration samples, weighted routing, state stores, replica probes, provider wiring, health controller, reader-window calculations, selected pgcat scheduling/validation paths, and the associated tests and fixtures. This is a risk-focused review, not a claim that every execution path has been proved correct. No production fixes were applied.

The findings below were checked against the implementation. Targeted PHP probes used the installed dependencies, a controlled clock, and an in-memory Redis factory; they did not require a live database or Redis server. Two additional evidence tests booted the package through its existing Testbench fixture, using SQLite for a working read PDO. The backoff finding explicitly bypasses the frozen-clock defect to expose a second, otherwise masked defect.

The existing report remains `0001`; this new report takes the next unused number, `0002`. Existing report names and contents were preserved. Existing changes to repository instructions, handoff files and investigation/planning records were present before this review.

## Findings

### F1 — P1: Replica cooldown never expires in a surviving manager

**Location:** [HealthMonitor.php](../../src/Database/Weighted/HealthMonitor.php#L44), lines 44–46, 53–61 and 92–104.

The constructor captures `time()` into an integer. Both failure timestamps and expiry checks then use that same integer forever. There is no production clock refresh. In a long-lived worker, a failed replica stays excluded even after its cooldown has elapsed, provided another usable replica remains and prevents the manager's all-failed reset.

**Reproduction:** construct at clock 1000, mark `reader:5432` failed, advance the external clock to 1031, and ask `isFailing()`. Actual: `true`; expected: `false` after the initial 30-second cooldown. The probe changed the namespaced clock function's answer, not the monitor's stored clock.

**Impact:** recovered replica capacity is lost until explicit health marking, a circuit reset, or manager recreation. Later failures are also dated from construction rather than from the actual failure.

**Correction and regression coverage:** read a live clock on each operation, retaining an injectable clock for tests. Exercise expiry and a failure occurring well after construction in the same monitor instance.

### F2 — P1: Redis recovery becomes unreachable after three failures

**Locations:** [RedisAtomicStateStore.php](../../src/Database/Weighted/RedisAtomicStateStore.php#L318), lines 318–322; [WeightedDatabaseManager.php](../../src/Database/Weighted/WeightedDatabaseManager.php#L408), `runStep()`.

Three ordinary Redis failures set the store's healthy flag to false. The manager calls `next()` only while `isHealthy()` is true, while the store changes back to healthy only after a successful `next()`. Neither the health accessor nor `reset()` attempts recovery. Consequently a restored Redis server is never tried again by ordinary routing through that manager.

**Reproduction:** perform three weighted picks with a Redis factory that throws, restore the factory, then perform another pick. Actual: only three Redis connection attempts in total, `healthy=false`, `degraded=true`. A direct call to the very same store's `next()` immediately succeeds and sets `healthy=true`, demonstrating that manager gating prevents recovery.

**Impact:** long-lived workers remain on uncoordinated local rotation after a temporary outage. With local fallback disabled, subsequent picks continue failing until the store is replaced or independently retried.

**Correction and regression coverage:** permit a bounded recovery attempt after a retry interval. Drive outage, threshold, restoration and recovery through `readConfigFor()` using one manager and one store; testing the store in isolation misses the deadlock between them.

### F3 — P1: An all-disabled pool still receives reads

**Location:** [WeightedDatabaseManager.php](../../src/Database/Weighted/WeightedDatabaseManager.php#L373), lines 373–379.

When the weighted pool is empty, the manager retries without health filtering. If that pool is still empty because every replica has weight zero or a refused weight, it returns the first original replica anyway. This contradicts the package's explicit weight-zero disable contract and the exclusion diagnostics.

**Reproduction:** configure top-level/writer host `writer` and `read = [['host' => 'drained', 'weight' => 0]]`. `readConfigFor()` returns host `drained`, despite the resolver excluding it.

**Impact:** draining the final replica for maintenance does not stop traffic to it. An invalid weight can also select the endpoint that diagnostics describe as excluded.

**Correction and regression coverage:** distinguish an empty configured pool from an all-cooling pool. For the former, use an explicit writer fallback or fail clearly rather than selecting a disabled endpoint. Assert the final routed host, not only the resolver's exclusions or absence of boot warnings.

### F4 — P1: Inherited endpoint ports break health filtering

**Locations:** [ReplicaMetadata.php](../../src/Support/ReplicaMetadata.php#L210), lines 210–218; [WeightedDatabaseManager.php](../../src/Database/Weighted/WeightedDatabaseManager.php#L343), line 343; [ReadinessProbe.php](../../src/Database/Weighted/ReadinessProbe.php#L117), replica address resolution and health marking.

Routing builds health keys from each raw replica map and substitutes port 5432 when its own `port` is absent. Connection creation instead merges the replica over the base config, and active probes use the base connection's port. These are different keys when a replica inherits a non-default port. The same unmerged-map issue affects an inherited host.

**Reproduction:** base port 5433, two equally weighted replicas `a` and `b` with no per-replica port; mark `a:5433` failed, as the active probe would. Four weighted picks return `["a","b","a","b"]`. Expected: only `b` while `a` cools down. The filter looks up `a:5432`, which has no failure entry.

**Impact:** known-failed endpoints remain in rotation for valid Laravel configurations that inherit connection-level addresses. Status rows also display the substituted port instead of the one actually dialed.

**Correction and regression coverage:** resolve endpoint identity from the effective merged address consistently for routing, marking, probing and diagnostics. Cover inherited port, inherited host and explicitly overridden addresses.

### F5 — P2: The health endpoint blames a reader for a writer connection failure

**Locations:** [DatabaseHealthController.php](../../src/Http/Controllers/DatabaseHealthController.php#L194), lines 194–205 and 1159–1185.

The block described as testing the first replica calls `DB::connection(...)->getPdo()`. For a Laravel read/write connection, that resolves the write PDO. Its catch block then calls `markFirstReplicaFailed()`, which marks the first configured reader even though that reader was never queried by this block.

**Reproduction:** give the connection a write-PDO closure that throws `writer unavailable` and a working SQLite read PDO. A read `select 1` succeeds. Calling the actual controller's `index()` still changes the first replica's status to unhealthy. The targeted Testbench evidence test passed assertions proving both facts.

**Impact:** health polling can remove a healthy reader after a writer outage. Repeated polling can increase the reader's failure count; in a surviving manager, F1 prolongs the false exclusion.

**Correction and regression coverage:** attribute a failed writer check to the writer. If the intended check is a particular replica, probe that endpoint directly and mark that exact address. Merely changing to `getReadPdo()` would still not prove that the selected weighted reader is the first configured reader.

### F6 — P2: Cooldown expiry deletes the failure history needed for exponential backoff

**Location:** [HealthMonitor.php](../../src/Database/Weighted/HealthMonitor.php#L100), lines 100–104, together with lines 57–60.

Expiry unsets the entire state entry, including its failure count. The next failed retry therefore starts at count one again, even though no successful probe or read has marked the replica healthy. The documented 30, 60, 120-second progression does not occur for failures separated by their normal cooldowns.

**Reproduction:** fail at 1000, advance the monitor's stored clock to 1031 using reflection to bypass F1, call `isFailing()` to admit the retry, then fail again. Actual returned cooldown: 30 seconds; expected: 60 seconds without an intervening success.

**Impact:** once clock progression is repaired, repeatedly failing replicas will be retried at the shortest interval rather than backing off. This is a separate defect currently masked by F1 on the ordinary passive-expiry path.

**Correction and regression coverage:** let elapsed cooldown admit a retry while retaining failure history; clear the history on confirmed health or an explicit reset. Test fail → expire → fail separately from fail → healthy → fail.

### F7 — P2: Next-transition calculation depends on configuration order and reports false transitions

**Location:** [TimeWindowResolver.php](../../src/Database/Weighted/TimeWindowResolver.php#L131), lines 131–148.

The method returns a boundary from the first configured window with a future boundary. It neither finds the earliest boundary across windows nor checks that the overall reader/writer mode actually changes there. The configuration reader accepts unsorted and overlapping windows.

**Reproductions:**

- Windows `17:00–20:00`, then `10:00–14:00`; ask on Monday 2026-09-14 at 09:00 UTC. Actual next transition: 17:00; expected: 10:00.
- Windows `10:00–14:00` and `12:00–17:00`; ask that Monday at 13:00 UTC. Actual: 14:00, where `currentModeName()` is still `readers`; expected: 17:00.

**Impact:** `healthSummary().next_mode_change_at` publishes a delayed or nonexistent mode change. This finding concerns that diagnostic calculation; it does not claim that SWRR routing itself uses this timestamp.

**Correction and regression coverage:** sort candidate boundaries and return the earliest actual mode change across the union of windows. Cover reversed order, overlap and adjacent windows in addition to the already ordered sample configuration.

### F8 — P2: A status-command test compares two live timestamps for exact equality

**Location:** [DbReplicaStatusTest.php](../../tests/Unit/Console/DbReplicaStatusTest.php#L183), lines 183–187; timestamp production in [BootAudit.php](../../src/Support/BootAudit.php#L677) and its `timestamp()` helper.

The test first executes the command and captures its audit report, then calls `BootAudit::reported()` again inside `assertSame()`. Each call takes a fresh live reading and stamps `checked_at` with the real UTC time. Crossing a second boundary makes the assertion fail even when the record and configuration are unchanged. Finding ages may change between readings as well.

**Evidence:** a targeted Testbench check called `BootAudit::reported()` twice on the same audit with a 1.1-second delay and confirmed different `checked_at` values. This proves the volatile field involved in the existing assertion; it is not a claim that every ordinary run of that test fails.

**Impact:** intermittent red builds depend on scheduling and machine speed rather than a behavioral regression. Mocking Laravel's usual clock alone would not freeze this helper's native `DateTimeImmutable('now')` call.

**Correction and regression coverage:** inject/freeze the audit's clock, or compare stable content separately while validating the captured timestamp's format and time bounds. Include a deliberate delay in a control case to ensure the assertion no longer requires two independent readings to occur within one second.

### F9 — P1: Scheduled replica health is discarded with the probe process

**Locations:** [HealthMonitor.php](../../src/Database/Weighted/HealthMonitor.php#L33), its private in-memory state; [WeightedDatabaseServiceProvider.php](../../src/Providers/WeightedDatabaseServiceProvider.php#L513), line 513; [DbProbeReplicas.php](../../src/Console/Commands/DbProbeReplicas.php#L177), the health marks made by the sweep.

The documented scheduler launches `db:probe-replicas` as an Artisan command in a separate process. Its marks go only into that process's `HealthMonitor` array. The provider creates a new monitor for each application container, and there is no shared health-state read/write in the monitor, command or routing filter. Redis coordinates SWRR positions, not these health entries.

**Reproduction:** one PHP process constructs the monitor, marks `reader:5432` failed and reports `isFailing() = true`. A second PHP process constructs the monitor as the provider does and reports `false` for the same address. The scheduled command's mark cannot reach an existing HTTP worker either; neither process reads the other's memory.

**Impact:** the advertised scheduled probe can report a failed replica while web requests continue routing to it. A subsequent status command also starts with no remembered replica failures. This remains true even after the clock, backoff and address-key defects are fixed.

**Correction and regression coverage:** persist health/cooldown state in a shared store read by routing workers, with explicit connection/endpoint scoping and expiry. Verify a probe in one process changes routing in another and a later successful probe restores it. Existing command tests inspecting marks in the same application instance cannot establish this behavior.

## Verification

Commands below were started with the PHP interpreter specified by `.agents/machine.local.json`.

- Existing weighted tests: `bin/tool.php phpunit tests/Unit/Weighted --no-progress` — passed, 212 tests and 1,087 assertions.
- Existing status-command tests: `bin/tool.php phpunit tests/Unit/Console/DbReplicaStatusTest.php --no-progress` — passed, 13 tests and 108 assertions. F8 is intermittent, not an observed failure of this ordinary run.
- Temporary controller/audit evidence tests under ignored `build/`: passed, two tests and three assertions. These prove the erroneous reader marking and independently changing audit timestamps.
- Standalone controlled-clock/store/routing probe: exited 0 and produced the observations below. Its purpose was to observe the defects, so exit 0 does not mean the observed routing was correct.
- Separate-process health check: one process marked the endpoint failed and returned `true`; the next returned `false` without a health reset or successful probe.
- Focused handoff and repository-path guards: 101 tests, 361 assertions, one failure. The failing handoff assertion names a pre-existing stale remote-tip row: the note says `origin/dev` is `a99c22b`, whereas the remote answers `d9e8db5`. The report-related checks produced no other failure. Updating the release snapshot was left outside this review's scope.
- Full repository gate: `bin/checks.php` exited 1 after 1,739.6 seconds: 12 checks passed, one failed, none skipped. Syntax, AST, writeback, Composer schema, platform, interpreter, tools, YAML, rendered counts, prose, PHPStan and Pint passed. The failed unit-test check ran 1,683 tests with 11,331 assertions, one error, four failures and nine skips.
- Three full-suite handoff discrepancies (one error and two failures) were transient review-process interference: the gate's test process loaded the old handoff map before this report was registered, then compared it with the files edited during the run. A fresh final handoff/path-guard run after registration completed 101 tests and 361 assertions with only the stale remote-tip failure described above. The registration discrepancies are therefore not counted as package bugs.
- The other full-suite failure is an existing documentation-contract mismatch at `tests/Unit/Http/DatabaseHealthControllerTest.php:1968`: the window payload includes `last_refusal`, `last_refusal_at` and `last_refusal_mode`, but the README table omits them. The implementation, that test and the README were not edited during this review. This documentation repair remains outside the requested folder review's production findings.
- Final report registration: `bin/handoff.php --check` passed with all five rendered blocks in step; Pint passed for the edited `tests/Support/Handoff.php`. The previous recorded gate artifact was preserved rather than replaced with this review run.

```text
cooldown_after_31_seconds=true
second_failure_cooldown=30
all_disabled_selected=drained
failed_inherited_port_selected=["a","b","a","b"]
redis_recovery={"attempts":3,"healthy":false,"degraded":true}
direct_redis_retry_healthy=true
unsorted_next=17:00:00
overlap_next=14:00:00;mode_at_next=readers
writer_failure_marked_healthy_reader_failed=true
audit_checked_at_changes_between_reads=true
fail: failing=true
next-process: failing=false
```

No distinct executable defect was confirmed in the shipped `config/` samples themselves. Their settings supply the valid inherited-address, reader-window and fallback configurations implicated above. This package targets Laravel's Illuminate components and uses Symfony Process; standalone Symfony framework integration was not exercised.

Live PostgreSQL, pgcat, and Redis end-to-end failure/recovery behavior was not verified by the targeted probes. The temporary evidence harnesses were review aids, not committed regression tests. Recommended repairs above remain unapplied.
