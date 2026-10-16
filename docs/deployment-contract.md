# The deployment contract

A `composer require` installs this package's code. It installs nothing this package *needs*: not the
pooler whose configuration a flip swaps, not the supervisord that is asked to reload it, not the
directory the flip's state is written into, and not the store the weighted state is read from. Those
are the deployment's, and until now they were written down in the consuming application's own records
— scattered across a design record about the pooler's boot, a troubleshooting record of what failed in
the field, and the shell scripts that do it — rather than in one place a reader of *this* package can
find. This is that place: what a deployment of this package has to have, what each thing costs when
it is missing, and the row or command that asks about it.

It is a contract in one direction only. Nothing here decides what a deployment's paths are, which
store it runs, or how its container is built; those are the deployment's decisions and this package
reads them through configuration. What it states is the *shape* a deployment has to have for the
package's own readings to be honest: a preflight that reports "a flip could run" is a claim about a
container, and if the container is missing one of these, the claim is false in a way no row can see.

## Where this comes from

Every requirement below is one the consuming application already satisfies, and each is cited to the
file in *its* repository that carries it. Those files are named rather than quoted: this package's
suite cannot open another repository's tree, so a citation here is a reading taken at a moment rather
than a claim a guard holds — which is the limitation at the end of this record, and the reason the
citations name a record or a script rather than a line number in one.

| Cited by name | What it is |
|---|---|
| `docs/pgcat-boot-hardening.md` | the application's design record for the pooler's boot: the prune, the per-minute entry and its window, the rehearsal, and the container health check. Its §3.1 is the sentence that one pair of the rendered variants must be identical and another must not |
| `docs/pgcat-issues.md`, `docs/deploy-troubleshooting.md` | the field evidence: what broke, the rollback it caused, and the failures a deployment's own gates missed |
| `docker-files/production/laravel/entrypoint.sh` | container start: it renders the three pooler configurations, prunes unresolvable pools out of all three, stamps the boot, caches the configuration, and provisions the state directory with the files this package writes |
| `docker-files/production/laravel/pgcat-Dockerfile` | the pooler's own image: the source cloned at a pinned tag and compiled, so the version behind this package is a decision rather than whatever a registry serves |
| `docker-files/production/laravel/pooler-healthcheck.sh` | the ECS container health check: supervisord's state for the pooler and a real TCP connect on its port, `--deep` adding one query |
| `docker-files/production/laravel/pgcat-health-gate.sh`, `flip-rehearsal.sh` | the deploy's two gates that exercise this package: a real query through the pinned connection, and `db:pgcat-flip --dry-run --json` |
| `docker-files/production/laravel/smoke-test-image.sh`, `validate-image.sh` | the image gates that run the application's own connection diagnostics before anything is booted — the place a light `db:doctor` belongs, which is what retires that command rather than duplicating it |
| `docker-files/production/laravel/pool-prune-selftest.sh` | the regression test for the boot's prune, with the fixture discipline a package-side check of the same question copies: a strict allowlist and a case for the no-op that looks like success |
| `docker-files/production/redis/redis.conf` | the store the weighted state lives in, and what it is not configured to survive |

## The requirements

| Requirement | What it costs when it is missing | What asks about it |
|---|---|---|
| **Three pooler variants, rendered from one template.** The writer-only file, the readers file, and the one in place — the pair §3.1 holds to one shape and the pair it holds to another | a flip swaps in a file the deployment renders differently from the one this package's reading expects, and the pooler comes back serving a different set of pools than the connection names | `db:doctor`'s `variant shape` row; `db:pgcat-flip --dry-run`'s `variant shape` step |
| **The prune, before pgcat starts.** Every pool whose addresses do not resolve *in the container* is removed from all three variants at boot, because pgcat validates every server when it starts | a pool pruned at boot and still declared by a variant a flip swaps to puts the failure the prune exists for back — and a parser that reads more or less than the bare pool name makes every address unresolvable and the prune a silent no-op | the same row's warning, which names the pool and the addresses that did not resolve from where it was asked |
| **A pinned, compiled pooler.** The image clones the pooler's source at a tagged version and compiles it rather than pulling a binary | a pooler upgrade changes this package's runtime: the reload verb a flip sends, the startup packet the pool probe reads, and the file layout the flipper's own reader parses are all that version's | the image build; `pool probe`, which asks the running pooler rather than the file |
| **A boot stamp.** The container writes the time it started into a file the package is pointed at | the flip's boot window is measured from nothing, so a window that never closes lets the entry re-apply the configuration on every run, and a window that never opens leaves the pooler wherever it was | `db:doctor`'s `pgcat files` (it names the mode and owner the stamp was found with) and `schedule beat` (which reports the reading) |
| **One mechanism for the mode, a window, and a lock.** The per-minute entry, or the reader-window tasks — one of the two — and the entry's own `flock` so two runs cannot overlap | two mechanisms armed at once own the same mode, and a lock file in a directory that is not writable is a run that takes no lock and says nothing | `flip schedule` and `pgcat files`; `db:scheduler-watchdog` reports whether the entry is producing runs at all |
| **A writable state directory.** The flip's state file and its `.lock` companion, the boot stamp, the boot audit's record and *its* `.lock` — provisioned before the application starts, owned by the user the schedule runs as | a flip that cannot get its copy into place, a run whose lock cannot be taken, and a probe skipped because its throttle cannot be written: three faults that share one cause and no failing row | `pgcat files`, one sentence per path with the mode and owner it was found with; the boot audit records the same list as a finding so an image is told before a deploy is |
| **A spawnable supervisor.** `supervisorctl` resolvable for the user the schedule runs as, with the pooler's program declared, and a PHP that may spawn a process at all | a flip that swaps the file and then cannot make the pooler read it: the failure ends as a timeout sentence unless `proc_open` is the cause, which the row tells apart | `pgcat supervisor`, which is read-only and names `disable_functions` when that is the cause |
| **A container health check that asks the pooler.** Supervisord's state for the pooler plus a TCP accept on its port, and one query when asked deeply | a health check reading a cached route reports a container as healthy while the pooler behind it serves nothing — which is the failure the deploy's own gate exists to catch | the check itself; `pool probe` from this side, which answers whether the running pooler holds a pool for a database the application uses |
| **A store, and a known answer for its absence.** The weighted state is read out of a cache store, and the same store holds the schedule's overlap mutexes | without the store the weighted state is not shared between processes, and an installation whose store does not answer throws inside the schedule before the entry runs — a dead schedule with a healthy cron | `store reachability` and `store probe`; `db:scheduler-watchdog`'s causes |
| **Read caps that need not match the write cap.** A read endpoint's `statement_timeout` may be shorter than the writer's, which is what makes an escalated read finish | caps far apart are a fact to state rather than a fault, but a read endpoint capped at a fraction of the writer's cancels statements the writer would have finished — and nothing in Laravel's configuration says so | `read caps` and `standby feedback`, while `swrr.read_path.enabled` arms them; [read-path-cancellations.md](read-path-cancellations.md) is the contract an application answers a cancelled read with |
| **The health endpoint is a machine's surface.** `/health/db` is read by a monitor or a load balancer, and the container's own health check asks the pooler instead of a route the application has cached | a container that answers 200 with a cached route is not evidence that the pooler behind it is serving, and the deploy's gate would be the only thing that notices | `/health/db` for a monitor; `pooler-healthcheck.sh` for the container. The two are deliberately different questions |

## The two gates that read this package

The deployment's own pipeline asks this package two questions, and each is a contract in both
directions: the deploy fails on the package's answer, and a change here can fail the deploy.

`flip-rehearsal.sh` runs `db:pgcat-flip --dry-run --json` inside the candidate image and fails the
revision unless the report's `kind` is one of the verdicts a rehearsal can reach. It is therefore the
sharpest consumer this package has of its own JSON report: the envelope, the key, and the set of
kinds are read by a shell script in another repository, which is why the rehearsal's report is
documented as a pipeline rather than as a sentence. The script also detects an image whose
`db:pgcat-flip` is undefined and says so, which is the one failure that is the image's rather than
this package's — a command missing from an image is not a table this package can print. Its selftest
is a fixture set over the same gate, and it is worth naming as a model rather than a dependency: it
was written outside this package, over this package's contract, and it reached the cases a rehearsal
can produce before the package's own suite did.

`pgcat-health-gate.sh` is the other: supervisord's state for the pooler, a TCP accept on its port,
and one real query through the pinned connection. The query is the part this package is on the hook
for, because the connection it goes through is the one the package weights: a gate that reaches the
writer while the pooler is up and not serving reads is a green deploy over a broken read path, which
is why the row that asks about pools exists at all.

Both exit codes belong to the deployment — the pipeline reads them to decide whether to roll back —
so a change to this package's exit contract is a change to a deploy. The doctor's own exit codes are
documented in [documented-exit-codes.md](documented-exit-codes.md), and the rehearsal's kinds in
[pgcat-flip-json.md](pgcat-flip-json.md).

The image gates are the third place this package is read, and they are the reason one of its modes
exists: `smoke-test-image.sh` and `validate-image.sh` run the application's own diagnostics command
before anything is booted, because a build container has no store, no supervisord and no reachable
database to ask. `db:doctor --connection-only` is that command's replacement rather than its
neighbour: it answers every row that is about this boot, this configuration and these files, and
leaves out the six that reach outside the process — the store's two, the supervisor's, the running
pooler's, and the two read-path probes, which ask an endpoint what it is capped at. Which of the two an installation runs is the
deployment's decision, and the record of the port is in the plan beside this one.

## What this record deliberately does not decide

- **The paths.** Which directory the state lives in, where the variants are rendered and where the
  boot stamp is written are the deployment's. The package's sample config spells `/etc/pgcat/...`
  for the pooler's files and a temporary directory for the state, and an installation that provisions
  a directory of its own — as the application does — is doing the right thing rather than overriding
  a default it should have kept. What the package requires is that the paths it is pointed at are
  the paths the entrypoint wrote, which is why the same list is judged in two places instead of one.
- **The store's identity, and its durability.** The package reads a cache store and has a documented
  fallback when the store it names is not reachable; whether that store survives a restart is the
  deployment's decision, and the weighted state is only as durable as the store it was read from.
  The deployment this record was written against runs a store configured to hold everything it is
  given, and does not persist to disk — which is a decision about availability, not about this
  package, and is why the package treats a missing store as a fact to report rather than a fault.
- **Which mechanism owns the mode.** Both ways of moving the pooler are documented and either may be
  armed; what the package insists on is that exactly one of them is, which is a row rather than a
  rule it can enforce. An installation mid-migration between them reads as a warning, not a failure,
  because the state is recoverable and is the state an operator passes through on purpose.
- **The application's own diagnostics command.** Whether it survives `--connection-only` is a
  decision in the other repository. This record states what the mode answers so that the decision can
  be made with the rows in front of it.
- **The pooler's configuration content.** This package judges the *shape* of a variant — which pools
  it declares — and never its values: pool types, sharding, timeouts and the rest are the
  deployment's. A variant this package passes may still be a pooler configured wrongly for the
  workload, and no row here pretends otherwise.

## Known limitations

- **The citations are a reading, not a guard.** Every file named in the table above was read in the
  application's repository on a date rather than held by a check: this package's suite reads its own
  tree, and a deployment that stops rendering a variant, stops pruning, or moves its state directory
  changes the contract without failing anything here. The repair for that is on the other side of the
  boundary and is named in the port plan's application-side list, which is where a change to those
  scripts is recorded.
- **The pooler version is pinned at the image, not here.** The tag the image compiles is the tag whose
  behaviour this package's reads assume, and nothing in this package names it: an upgrade of the
  pooler is a change to this package's runtime that arrives without a change to this package. The
  row that would notice a semantic change is `pool probe`, and it notices only what answers.
- **The deployment's gates are the deployment's.** The two gates quoted above run in a pipeline this
  package is not part of, and their exit codes are read by that pipeline rather than by any test
  here. What this record can do is keep the two contracts in step by saying, in one place, which of
  this package's surfaces a deploy reads.
