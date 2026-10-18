# The pool repair rate

A repair is the self-heal working. The per-minute flip finds the running pooler refusing a pool the
applied file declares, re-applies the file, and — if the pooler still refuses it — restarts the
pooler on the file just written. The run reports `repaired` and the state file counts it. That is
the feature doing its job, and it is also the only place an installation can see a pooler that keeps
needing the job done.

The count alone cannot say which of the two it is looking at. One repair inside a boot window is a
container that came up; eight minutes of repairs on run after run is a pooler that is being
re-applied every minute and refusing the result, which is a pooler to look at rather than a flip to
trust. `last_kind` reads `repaired` in both, and `last_pool_repair_at` is one moment either way — so
the fact that separates them is a **rate**, and `GET /health/db` is where it is published.

## What the endpoint publishes

`pool_repairs` is a block beside `pool_probe`, and it is a reading rather than a verdict:

| Field | What it is |
|---|---|
| `applicable` | whether a flipper was built for this request; `false` means nothing was read |
| `judged` | whether the repairs inside the window could be counted at all — see below |
| `warned` | the repairs inside the window are more than the threshold |
| `window_seconds` | the window the count is taken over, `swrr.pgcat.pool_repairs.window_seconds` |
| `max_repairs` | the threshold, `swrr.pgcat.pool_repairs.max_repairs` |
| `count` | every repair this container has recorded, which is what `db:pgcat-flip --status` prints |
| `in_window` | the repairs inside the window, or `null` when they cannot be read |
| `last_at` | when the last repair happened, or `null` |
| `reason` | the sentence for a warning, and for a file that carries a count and no times |

`warned` moves no status code and no other field. The blocks that fail this endpoint answer a
question about now — `pool_probe` asks the pooler at this moment, `flip` asks whether this container
ever came up, `reader_window` asks whether the mode is the one the windows want. A repair is history:
the flip already acted, and the state it acted on may be gone. Failing an installation for it would
fail it for the self-heal having worked, which is the line `db:doctor`'s `pool repairs` row draws
when it passes in every state it can find.

The shipped line is **more than three repairs in an hour**. Three because a repair is only possible
inside the boot window — `applyCurrentState()` returns before it takes the lock once the window has
closed — so the repairs a container can record are the ones its first eight minutes produced, and
three of them in that time is a pooler that refused the pools it was just given more than once. An
hour because a container booting twice inside it is not one pooler failing twice. Both settings are
the sample's, so an installation with a slower cadence or a wider window can move the line without
a release.

## Why the times are kept in the state file

A rate cannot be derived from a count and one timestamp by any reader, however careful: the file is
the only place the moments are known. `recordRun()` therefore appends the moment of a repair to
`pool_repair_times` beside the count, prunes everything older than the configured window, and caps
the list — so the array is bounded by the setting that measures it rather than by the life of the
container, and the state file stays something the poll path can rewrite on every run.

Widening the window later starts from the times the narrower one kept. That is stated rather than
hidden: it is the cost of keeping the history small enough to write on the poll path, and a rate
that had to reach back past the setting's own retention would be a reading the file cannot support.

A file written before this reading existed carries `pool_repairs` and no times. That is the one
state the block cannot judge, so `judged` is false, `in_window` is `null`, and `reason` names the
count it holds and the window it could not measure. Reporting zero there would be the most
comfortable answer and the wrong one: the file says repairs happened and says nothing about when. A
file that has never seen a repair is different — zero is then the whole history — so it is judged
and quiet.

## What was rejected

* **A count, not a rate.** The count is cumulative for the container's life, so it never comes back
  down and never says when: an installation repaired twice on its first boot and one repaired twice
  on its last read the same. The threshold would have to be a lifetime limit, which is a number no
  working installation can stay under for long.
* **A second counter with a reset window.** Storing `pool_repairs_since` and the window's start
  beside it is the rate computed on the write side — and it loses the moments, which is exactly what
  a reader asks for when the threshold or the window is changed after the fact. The list is smaller
  than the bookkeeping it replaces and answers more questions.
* **Failing the endpoint.** See above: a repair is the self-heal working, and a container that
  repaired itself is more available than one that did not. A monitor that wants to page on the
  warning selects `pool_repairs.warned` — one comparison, the same shape `pool_probe.warned` and
  `audit.severity` already give it.
* **A `db:doctor` row.** The preflight already has `pool repairs`, and it reports the count and the
  time; a row that failed on a rate would fail an installation for the flip having done its job,
  which is the rule that row is written against. The doctor reports what happened; the endpoint
  warns about how often, and the two read the same state file through the same class.

## Where it is held

`PgcatConfigFlipperTest` pins the history the write leaves behind — appended, pruned by the window,
capped, and untouched by a run that did not repair — and the reading against state files seeded by
hand: inside and outside the window, at and past the threshold, a count with no history, and a
window or threshold the configuration cannot express. `DatabaseHealthControllerTest` pins the block
the endpoint publishes: a quiet container, one past the threshold, and a flipper that was not built,
each with the status the payload keeps either way.
