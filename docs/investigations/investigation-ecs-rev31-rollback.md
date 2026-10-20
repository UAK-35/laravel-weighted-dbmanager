# Investigation — the two `lpr-backend-sites` tasks that were rolled back

| | |
|---|---|
| Cluster | `lpr-backend-cluster-01` |
| Service | `lpr-backend-sites-svc-02` |
| Container | `lpr-backend-cntnr-01` |
| Tasks | `58e2f1cffdb040f0a132e812d33c8af1`, `88c5912267ec43bc816e01d913eed4a3` |
| Region / profile | `us-east-1` / `lpr` |
| Log group | `/ecs/lpr-backend-sites-tdef-01` |
| Code folder | `/app/site0/html` |
| Task definition at the stop | `lpr-backend-sites-tdef-01:31` (image `…/backend-image:799f5125bba7fa14bf703b7e8e1445094e727b21`) |
| Task definition after the stop | `lpr-backend-sites-tdef-01:29` (image `…/backend-image:e29b9086cc85d806b11d75e585ef78d8048ccad7`) |

## Verdict

These two tasks did not crash and were not failed by their health checks. They were the
canary of task-definition revision 31 and were **terminated by a rollback to revision 29**
eleven minutes after revision 31 reached a steady state. Everything ECS records about them
agrees: they were created by one `UpdateService` call, they served their load balancer the
whole time, and they were stopped by the service when the next `UpdateService` call replaced
the task set.

What *did* fail inside them is the boot, and it fails on every fresh task, not only on this
revision: the site's log file is not writable by the user PHP-FPM runs as, the pooler's state
directory is not writable at all, and a cold boot installs and then upgrades its own vendor
tree from the network instead of using the image's lock file.

## Timeline (UTC)

| Time | What happened |
|---|---|
| 16:36:39 | revision 31 registered — the only difference from 29 is the image digest |
| 16:36:42 | `UpdateService` with `taskDefinition …:31`, by `arn:aws:iam::…:user/lpr_dev2` (deployment `ecs-svc/0821100205027517726`) |
| 16:36:53 | task `58e2…` starts |
| 16:37:24 | task `88c5…` starts |
| 16:38:34 | `58e2…` container boot; first log line, boot stamp written to `/tmp/container-booted-at` |
| 16:38:45 | `58e2…` registered in `lpr-backend-sites-03` and in the SFTP target group |
| 16:39:13 | `88c5…` container boot |
| 16:39:19 | `/var/swrr` warnings begin on `58e2…` |
| 16:39:26 | `88c5…` registered in both target groups |
| 16:39:42 | `58e2…` logs the `laravel.log` fatal (four lines) |
| 16:40:06 | first container health-check beat on `58e2…` |
| 16:40:22 | `88c5…` logs the same fatal |
| 16:46:34 | deployment `ecs-svc/0821…` completes; service reaches a steady state |
| 16:47:57 | `UpdateService` with `taskDefinition …:29`, same IAM user — the rollback (deployment `ecs-svc/6883752813188850573`) |
| 16:48:08 / 16:48:29 | replacement tasks `357e78…` and `22eac9…` start |
| 16:51:32 | `58e2…` stopped by the service; its two targets deregistered ten seconds later |
| 16:51:53 | `88c5…` stopped by the service; targets deregistered ten seconds later |
| 16:57:08 | rollback deployment completes; service steady on revision 29 |
| 16:57:36 / 16:57:45 | last log line from `58e2…` / `88c5…` — a container health-check request |
| 17:00:24 | `UpdateDesiredCapacity` sets the service to three tasks; `98198ba…` starts |

CloudTrail holds exactly three write calls against this service for the window: the deployment
of revision 31, the rollback to revision 29, and the autoscaling update. There is no `StopTask`:
the stop was the service's own replacement, not an operator or a script killing a task.

## Why this is a rollback and not a crash

- **The task definitions differ in one field.** Revision 29 and revision 31 compare equal on
  `command`, `entryPoint`, health check, port mappings, environment, secrets, cpu, memory, roles
  and volumes; only `image` moves. Revision 30 (the day before) differs from 31 the same way, so
  31 was a new image and 29 the image it replaced.
- **Revision 31 was deployed and then replaced by name.** The second call names
  `lpr-backend-sites-tdef-01:29` explicitly — a rollback, not a `forceNewDeployment` of the same
  revision. It arrived one minute and twenty-three seconds after revision 31's deployment
  finished.
- **Both tasks were healthy until they were killed.** The container health check
  (`/app/pooler-healthcheck.sh --quiet && curl -fsS -m 5 -o /dev/null http://localhost/up`,
  interval 30 s, six retries, 300 s start period) produced a beat every thirty seconds with no
  gap at all — 36 beats from 16:40:06 to 16:57:36 on `58e2…`, 35 beats from 16:40:45 to 16:57:45
  on `88c5…`, the last of them seconds before the stream ends. Six failed beats in a row are
  what a health-check stop looks like; this is the opposite.
- **The load balancer agreed.** The `lpr-backend-sites-03` health check is HTTPS
  `/backend/api/v2/health-check`, interval 55 s, healthy threshold 7, unhealthy 3, matcher
  200–302. Both tasks were registered and served that route with 200s through the drain.
- **The six-minute tail is the target group, not the task.** The stop event, then deregistration
  ten seconds later, then `deregistration_delay.timeout_seconds = 300`, then SIGTERM: the last
  log line lands about six minutes after the stop event. Three other replaced tasks in this
  service show the same tail (six minutes twenty-four, six minutes thirty-one, six minutes
  four), so it is the drain, not a hung container.

## What actually fails inside them

### The log file is not writable at boot

Both tasks log the same four lines immediately after their first-time install:

```
NOTICE: PHP message: PHP Fatal error:  Uncaught UnexpectedValueException: The stream or file
"/app/site0/html/storage/logs/laravel.log" could not be opened in append mode: Failed to open
stream: Permission denied
```

at 16:39:42 on `58e2…` and 16:40:22 on `88c5…`. The same four lines appear on the task that
booted the evening before (`f7bd…`), so this is a cold-boot property of the environment rather
than a regression in revision 31: the first-time install copies the site tree and runs its
installs as one user, while PHP-FPM serves as another, and the first request that has something
to log dies instead of logging — Laravel's handler cannot open the file it is reporting to, so
the exception replaces the request. nginx also logs
`[alert] setpriority(-2) failed (13: Permission denied)` at 16:39:36 and 16:40:15, which is the
same ownership story seen from the worker processes.

### The pooler state directory is not writable

From 16:39:19 onward, thirty-three warnings per task, three kinds, from the boot audit:

```
[WeightedDB] flip state directory [/var/swrr] is not writable, so a flip reports success while
recording nothing and restarts pgcat again on the next poll
[WeightedDB] flip lock directory [/var/swrr] is not writable, so the mutex a concurrent flipper
stands down on cannot be taken
[WeightedDB] boot stamp directory [/var/swrr] is not writable, so the container cannot record
when it booted, and the boot window never closes
```

The container writes its boot stamp to `/tmp/container-booted-at` at 16:38:34 and then cannot
write the same fact where the flip machinery reads it. This is the environment half of what the
flip investigation recorded for a different path: the state the pooler decisions are recorded
in has to be writable by the user the schedule runs as, or a flip is a success that leaves no
trace.

### The vendor tree is installed and then upgraded at every cold boot

The boot script finds the site folder empty, copies the image's tree, and then runs composer
twice:

- `composer install` from the lock file — 147 installs, including
  `uak35/laravel-weighted-dbmanager (v0.2.0-alpha6)`;
- then `composer update` — five installs, 140 upgrades, twelve removals, one of them the
  package installed a minute earlier.

Composer says it out loud: `Warning: The lock file is not up to date with the latest changes in
composer.json. You may be getting outdated dependencies.` Both tasks did this at the same time
(16:38:49 and 16:39:28), and the result is that the running dependency set is whatever the
registry answered at boot, not the set the image was built and validated with — the image
digest that was rolled back does not describe the code that ran.

### The repeated pooler warning

The two tasks logged 889 and 777 `two mechanisms own the pooler's mode` warnings in their
seventeen minutes, one per request. That is the known behaviour of a standing boot-audit
finding under FPM; the warning, its cause and the fix are recorded in
`docs/investigations/investigation-pgcat-both-mechanisms-armed-warning.md`, and today's running
tasks carry it at twenty-six to twenty-eight thousand lines per two hours.

## The rollout arithmetic

Two settings explain why this rollback took eleven minutes to undo and why it looks slow:

- a new task needs seven healthy checks at 55 s before the load balancer counts it, so roughly
  six and a half minutes pass between "registered 1 targets" and the deployment completing;
- a drained task keeps running for the target group's 300 s deregistration delay before SIGTERM,
  so replaced tasks show up as alive for about six minutes after their stop event.

With one task replaced at a time, a revision needs ten to twelve minutes to go live and the same
again to come back out.

## Fixes, in the order they remove risk

1. **Make the site's writable tree writable before the first request.** After the copy in the
   boot script, hand `storage/`, `storage/logs/` and `bootstrap/cache/` to the user PHP-FPM runs
   as, and prove it on a cold task rather than a warm one.
2. **Give the pooler a writable state directory.** Either mount `/var/swrr` writable by that
   same user or point `swrr.pgcat.state_dir` and the boot-stamp path at one; until then a flip
   reports success without a record.
3. **Boot from `composer install` against the image's lock file**, and regenerate that lock file
   in the build. A cold boot that upgrades 140 packages makes the image digest meaningless and
   adds roughly ninety seconds to every task start.
4. **Silence the per-request finding** by arming one scheduling mechanism and disarming the
   other (`SWRR_PGCAT_SCHEDULE_ENABLED=false` with the window tasks kept on), as the
   both-mechanisms investigation recommends.
5. **Shorten the rollout** if faster reverts matter: the healthy threshold of seven at 55 s and
   the 300 s drain are what make each replacement cost six minutes on each side.

## Notes and limits

- ECS Exec could not be used from this session: the session plugin needs a terminal, and the
  commands run here had none, so the writability above is read from the tasks' own log lines and
  the nginx alerts rather than from `stat` inside a container. The fix in item 1 should be
  confirmed by reading the mode and owner once, on a cold task.
- `/up` answers 301, which `curl -f` accepts, so the container health check passes on a redirect
  while the application's own health route answers the load balancer with 200.
- The tasks themselves are gone from `describe-tasks` (garbage-collected an hour after they
  stopped), so the stop reasons ECS would have attached to them are not recoverable; the service
  events, CloudTrail, the target groups and the log streams are the record used here.
