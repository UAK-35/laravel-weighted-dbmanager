# The fleet report — `db:doctor` asked once per task, for every task a service runs

A runbook, not a design record. `db:doctor` reads **one task** — the pooler that task is
configured to reach, the supervisor on its own machine, the paths its own flip would write. A
service runs more than one task, and the tasks can disagree: on the service this was written
against, one task's flip target had been left owned by `root` while its sibling's was writable.
Reading one task and calling the service healthy is the mistake this page exists to stop.

Everything below is read-only. No row flips, restarts, signals or writes anything, and the only
process the report starts is the read-only `supervisorctl status "<program>"` the flip itself
would make. It does open connections — to the pooler for `pool probe answer`, and to the state store —
which is how a report can name an endpoint that refused it.

---

## What it answers

Three subjects, and the row in each task's report that carries it.

| The question | The row | What a failure means |
|---|---|---|
| Which pools the pooler has **loaded** | `pool probe answer` | The row reads the pooler's own `SHOW POOLS` list first: a pool the configuration declares and the console does not list is judged `FAIL`, exactly as a refused pool is. Only a console that cannot be read at all — no credentials in the pooler's file, a refusal, a SCRAM challenge, silence — falls back to the startup packets, and then an answered pool leaves the row `WARN` rather than `PASS`, because a green row would claim a reading that could not be made. The detail names the endpoint the reading went to. |
| What the **supervisor** knows | `pgcat supervisor` | The row runs the read-only equivalent of the flip's own command — `supervisorctl status "<program>"` — and reads the answer. `FAIL` is supervisorctl unable to answer at all (a missing socket, a permission refusal) or a program supervisor does not know. A flip refuses before it swaps the file on this row, so a red row here is a flip that will not happen. |
| Whether a flip could **write** | `pgcat files` | The row asks whether the flip target and both variant sources are readable, and whether the target is writable — the atomic rename needs the file, not just its directory. `FAIL` names the mode and owner it found, and a repair suggestion. A release that also carries the `write paths` row names every path a flip writes with its mode and owner, which is where a hand-back shows up. |

The three are not interchangeable. A task can hold every pool and still fail its flip because
the socket is unreachable, and it can reach the socket and still fail because the file it has to
replace belongs to `root`.

## Before the first run

* **ECS Exec enabled on the service**, and the task role able to open an SSM session — without
  it every `execute-command` below fails before anything is read.
* **`session-manager-plugin` on `PATH`.** On Windows the installer puts it in
  `C:\Program Files\Amazon\SessionManagerPlugin\bin`; a shell that cannot see it answers
  `SessionManagerPlugin is not found` and no session is opened.
* **The application's own path and user.** This page uses `/app/site0/html` and `www-data`,
  which is where the LPR image keeps the application and the user its scheduler runs as. Change
  both if the deployment differs.
* **A shell that keeps nested quotes intact.** The blocks below are bash (Git Bash on Windows is
  fine). PowerShell can make the same `aws` calls, but the `su … -c '…'` quoting has to be
  escaped again for it.

## The loop

Resolve the service's running tasks first, so the report and the tasks it read are named
together.

```bash
CLUSTER=lpr-backend-cluster-01
SERVICE=lpr-backend-sites-svc-02
CONTAINER=lpr-backend-cntnr-01
REGION=us-east-1
PROFILE=lpr
APP=/app/site0/html

aws ecs list-tasks --cluster "$CLUSTER" --service-name "$SERVICE" \
  --desired-status RUNNING --region "$REGION" --profile "$PROFILE" \
  --query 'taskArns[]' --output text
```

Then ask each task, writing one log per task and printing the three rows from it:

```bash
for task in $(aws ecs list-tasks --cluster "$CLUSTER" --service-name "$SERVICE" \
               --desired-status RUNNING --region "$REGION" --profile "$PROFILE" \
               --query 'taskArns[]' --output text); do
  id=${task##*/}
  aws ecs execute-command --cluster "$CLUSTER" --task "$task" --container "$CONTAINER" \
    --interactive --region "$REGION" --profile "$PROFILE" \
    --command "sh -c \"su -s /bin/sh www-data -c 'cd $APP && php artisan db:doctor --no-ansi'\"" \
    > "doctor-$id.log" 2>&1
  echo "== $id"
  grep -E '[0-9]+ checks:' "doctor-$id.log" | tr -d '\r'
  grep -E '^(PASS|WARN|FAIL)  (pool probe (armed|answer)|pgcat supervisor|pgcat files|write paths)' \
    "doctor-$id.log" | sed 's/\r$//' | cut -c1-160
done
```

Three details in that command are the whole point.

**`sh -c "…"` is required.** ECS Exec splits the command it is given and executes the first
token; a bare `id; ls` answers `exec: "id;": executable file not found`. Handing it a shell
makes the rest a command line.

**`--no-ansi` is what makes the rows greppable.** The report colourises each verdict, so a line in
the log begins with an escape sequence rather than `PASS`; without the flag the anchored `grep`
below matches nothing and the task looks like it reported no rows at all.

**`su -s /bin/sh www-data -c …` is required, and it is the reason a manual session misleads.**
`aws ecs execute-command` opens a **`root`** session, and `root` can use a supervisord socket
that the application cannot. Run as `root`, the report says the socket works — which is true for
root and false for the scheduled flip. Run as `www-data`, the same command reports what the
application's own flip will meet. To see both, run the loop twice and change the one segment:

```bash
--command "sh -c 'cd $APP && php artisan db:doctor --no-ansi'"   # as root — the manual session's view
```

## Reading one task's answer

`db:doctor` exits `0` when every row passes or warns, and `1` when a row fails. In the loop above
that exit code is not visible — the `aws` call's status is — so read the verdict line the report
prints:

```
22 checks: 15 passed, 4 warnings, 3 failed
This installation will not behave as configured.
```

`WARN` is a state an installation may be in deliberately: an unarmed probe, a published config
one generation behind, two scheduling mechanisms still armed halfway through a migration. `FAIL`
is the set a deploy gate would stop on — read those first, and read the rows together, because
the whole point of the fleet view is that two tasks can disagree.

For a scrape or an archive, add `--json`: the same rows arrive as `checks` entries with `name`,
`verdict`, `detail` and `suggestions`, beside the run's own `verdict`, its `counts` and its
`exit_code`. The session banner (`Starting session with SessionId: …`) is on the same stream, so a
parser has to find the first `{` rather than decode the file as it stands.

## What it was measured against

Taken on 2026-10-20 against the two tasks that were `RUNNING` on
`lpr-backend-sites-svc-02`, as `www-data`.

| Task | Report |
|---|---|
| `f7bd22f8ef1a49f4bc7b8de5ff0a381e` | `22 checks: 14 passed, 4 warnings, 4 failed` |
| `144683894d6d4086ba901faf67e81e96` | `22 checks: 15 passed, 4 warnings, 3 failed` |

The difference is the one row a single-task reading would have hidden:

```
FAIL  pgcat files       flip target [/etc/pgcat/pgcat.toml] is not writable (mode 0644, owner root:root) — older task
PASS  pgcat files       a flip could run: both sources are readable and /etc/pgcat/pgcat.toml is writable — newer task
```

Both tasks failed `pgcat supervisor` with supervisorctl's `PermissionError` on the socket, and
both reported `pool probe answer` as unreadable rather than empty: the pooler at `tcp://127.0.0.1:5432`
refused the connection, so no pool list was read at all.

## What this report cannot say

* **It covers the tasks that are `RUNNING`.** A task that is draining, or that `list-tasks`
  omits, is exactly the one a flip can still hit. Ask again after a deployment settles.
* **A refused connection is not an empty pool list.** `pool probe answer` `PASS` with *did not answer …
  Connection refused* means the console was never read, and the row says so; check that the
  pooler is listening before reading it as "no pools loaded".
* **It is a preflight, not a record.** Every row asks whether the next flip would work. Whether
  the last one did is the flipper's state file and the `pgcat mode` row, and neither is a fleet
  question.
* **Root's answer is not the application's.** A report run as `root` can be green on the two
  rows the `www-data` report fails, and a flip run as `root` leaves the target owned by `root`,
  which is the fault the next `www-data` flip meets.
* **Aggregating is the mistake.** There is no service-level verdict here on purpose: the tasks
  are separate machines with separate files, and one green task beside one red task is a
  service that will fail at the next boundary.
