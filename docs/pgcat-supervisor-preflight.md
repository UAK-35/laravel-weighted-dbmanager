# How does `db:doctor` check a step it must not take?

A design record for the `pgcat supervisor` row.

A pgcat flip replaces `pgcat.toml` and then tells supervisor to pick the new file up —
`supervisorctl signal HUP "pgcat:*"` to make each pgcat process re-read the file it has
open, or `supervisorctl restart "pgcat:*"` to stop and start the programs. The file swap
is atomic and the package proves it (`pgcat files`). The command after it is a *shell*
command line, and it is the last thing between a correct file and traffic actually
moving between pgcat's reader and writer pools.

It fails in ways that have nothing to do with the file that was just written. This
document records what the package does about that, which alternatives were considered,
and why they were not taken.

Everything below is implemented in `Pgcat\SupervisorStep`,
`Pgcat\PgcatConfigFlipper::supervisorCommand()` and `DbDoctor::pgcatSupervisor()`. The
rules are pinned by the tests named at the end.

---

## The answer in one line

**Ask supervisor the read-only version of the flip's own command — and let the flip ask
it too.** `SupervisorStep::inspect()` derives `supervisorctl status "<program>"` from the
configured command — the same executable token, the same program argument, `status` in the
verb position — runs it, and reads supervisor's answer. Nothing is restarted, signalled or
stopped, and the only commands it can run are ones it derived itself: that one, and — for the
one fault whose repair is a name — a second, bare `supervisorctl status` that lists what
supervisord is running.

One verdict, three readers: `db:doctor`'s `pgcat supervisor` row reports it, the flip
refuses the swap on it, and `--dry-run` prints it as a step. A row that passes while the
flip refuses is therefore impossible, and the sentence an operator reads is the same one
in all three places.

**And the ordering changed with it.** Since a flip now asks *before* it replaces the file,
a command that cannot work can no longer leave a new `pgcat.toml` behind — and a command
that passes the check and fails anyway is rolled back. That was the gap the first draft of
this record ended on; how it closed is at the bottom, under [The ordering, closed](#the-ordering-closed).

---

## Why this needed deciding at all

### 1. The failure lands after the point of no return

This was true of the flipper as it was first written:

```php
// PgcatConfigFlipper::swap(), before this work
rename($tmp, $this->configPath);   // ← the new file is now live
$this->restartSupervisor();        // ← and only now does the flip find out
```

Order matters. A flip that cannot run its supervisor command still leaves the *new*
`pgcat.toml` in place: the next process to read the file gets the new pools, and the
running pgcat processes keep the old ones. That is a state a deploy looks successful in
and is not.

The file does have to be right before supervisor is asked to re-read it, so the order of
those two lines cannot change — but everything *before* them can, and the check does not
need the file to be in place. That is why the flip now judges the command first (and rolls
back if the judgement was wrong about a command that fails anyway): see
[The ordering, closed](#the-ordering-closed).

### 2. Four distinct faults, one symptom

| Fault | What the operator sees |
|---|---|
| The executable does not resolve — not an absolute path, not on this user's `PATH` | `sh: supervisorctl: not found`, exit 127 |
| The program name was rewritten by the shell before supervisorctl saw it | supervisorctl acts on the wrong program, or on a file name |
| supervisorctl runs, but supervisord does not know the program | `pgcat:*: ERROR (no such group)`, non-zero exit — and then the names supervisord *is* running, nearest to the configured one first |
| supervisorctl cannot reach supervisord at all | socket missing, permissions, `error: <class 'socket.error'>` |

Each has a different repair — install it, quote the name, fix the group name, start or
unmask supervisord — and each is a *fault code* rather than a message, so the row, the
flip's refusal and the rehearsal can say which one it is without re-deriving it.

### 2b. The answer that is not a fault: a program supervisord knows and is not running

`supervisorctl status` exits non-zero the moment one program it was asked about is not
RUNNING, so `pgcat:pgcat_00   FATAL   Exited too quickly` arrives at this check the same
way a missing socket does — and for a long time it was read as the socket's case:
`errored`, `usable: false`, the flip refusing *before it swaps the file*. That is backwards
exactly when it matters. A pgcat that is crash-looping is crash-looping on the config that
is in place, so the file the flip wants to write is the repair, and the refusal left a dead
pooler with the config that killed it and nothing on the host willing to replace it.

The verdict is its own (`not_running`), and it is one of the three that do **not** refuse.
It carries the two facts the repair needs:

| Key | What it holds |
|---|---|
| `state` | the state word the answer named — `STARTING`, `BACKOFF`, `EXITED`, `FATAL` — with `RUNNING` winning whenever any program of the group is up, and the most severe state chosen otherwise |
| `start_command` | `supervisorctl start "pgcat:*"`: the configured command with the verb replaced |

The verb matters as much as the verdict. `signal HUP` and `restart` both act on a program
that is already up, and supervisor answers `ERROR (not running)` for one that is not, so a
flip that ran the configured command after finding pgcat FATAL would have replaced a file
and changed nothing. `start` is the one verb that works there, and it is derived from the
configured command rather than configured beside it, so the binary, the group name and the
quoting are the ones this check already judged.

What the flip does with it is [the ordering, closed](#the-ordering-closed) read the other
way round: the file goes in first — it *is* the repair — then `start`, then the state is
read back, up to `swrr.pgcat.start_attempts` times with `swrr.pgcat.start_retry_delay_ms`
between them. The read-back is what keeps the retry from being a guess: a program inside
supervisor's `startsecs` window answers `STARTING` after a start that is going to succeed,
and a program in a crash loop answers the same thing after one that is not. If the attempts
run out, the new file **stays** — rolling it back would restore exactly the config pgcat
could not start on — and no mode is recorded, so the next poll writes the same file again
and tries again. That is the whole self-healing loop: it needs no operator, and it cannot
leave the disk holding a mode the daemon is not running.

### 3. The quoting is not cosmetic, and it is easy to get wrong

`pgcat:*` is a glob. In supervisor's own vocabulary a *group* is addressed as
`group:*` and each program in it by its own name, so the name has to reach supervisorctl
unchanged. Unquoted, the shell expands it first: any file in the working directory whose
name starts with `pgcat:` turns the argument into that file's name. It usually survives
by accident — with no match, POSIX shells pass the pattern through literally, and
`supervisorctl restart pgcat:*` works on most hosts most of the time. "Usually survives
by accident" is exactly the property worth failing a preflight on, because the accident
depends on a directory listing at the moment of the flip.

---

## The candidates

### A — run the configured command with a "dry" flag

There is none. `supervisorctl` has no `--dry-run`, and there is no way to ask "what would
`signal HUP` do" other than doing it. Rejected by fact, not by taste.

### B — read supervisord's configuration instead of asking it

`/etc/supervisor/conf.d/pgcat.conf` says what supervisord *would* read at start-up, which
is a different question from what the *running* supervisord knows: a group added since
start-up needs `reread`/`update` first, and a program can be in a config file that this
container's supervisord never loaded. It is also Linux-only and hardcodes a path, where
the rest of the row is derived from the command the flip will actually use. Rejected: it
answers about a file, when the flip fails because of a *process*.

### C — check nothing; let the flip fail

The status quo. It fails in production, during the traffic window, after the file has
changed, and the message names neither the quoting nor the group. Rejected.

### D — check only that the executable resolves

Cheap, catches fault 1, and nothing else. The glob and "no such group" faults are the two
this package has actually seen, and both need supervisor's answer. Rejected as a floor,
not a ceiling.

### E — derive the read-only twin of the flip's own command and ask supervisor (chosen)

`supervisorctl signal HUP "pgcat:*"` becomes `supervisorctl status "pgcat:*"`. `status`
is the only verb that both contacts supervisord and mutates nothing; its exit code and
its own words (`no such group`, `no such process`) distinguish the faults. Because the
command is derived from the configured one, the row cannot drift from the flip: a
customized `restart_command` is inspected as written, and the program name asked about is
the name the flip will use.

### F — run `supervisorctl status "pgcat:*"` unconditionally

Simpler, and wrong for anyone whose group is not called `pgcat`: it would report on a
program the flip never touches, and a `FAIL` that cannot be fixed by editing the command
trains operators to ignore the row. Rejected — the name is read out of the command.

### G — report only in the boot audit

The boot audit runs in every web worker and has no business spawning processes; the row
is where an operator already looks before a deploy, next to `pgcat files`. Rejected on
placement.

---

## The chosen mechanism, in full

### One inspector, derived from the flip

`PgcatConfigFlipper::supervisorCommand()` is the single source of truth for the command —
reload when `swrr.pgcat.use_reload`, restart otherwise, each with a quoted default:

```php
reload_command  => 'supervisorctl signal HUP "pgcat:*"'
restart_command => 'supervisorctl restart "pgcat:*"'
```

The flip calls that method, and `db:doctor` calls the same one, so the report and the flip
cannot disagree about what will run next.

### A tokenizer that knows what the shell will do

`SupervisorStep::tokens()` splits the command line the way a shell would, and marks each
token with `quoted` and `glob_exposed`. It handles single and double quotes, and — on
POSIX only — backslash escapes, because the same command line on Windows goes through
`cmd.exe`, where `C:\bin\supervisorctl.exe` carries real path separators. It does not
attempt expansion, substitution or command lines with more than one command; when it
cannot tell what supervisorctl will receive, the row declines to guess and says so.

### Programs, not arguments in general

`statusCommand()` walks past the verb (`restart`), and past the signal for the verbs that
take one (`signal HUP pgcat:*`), stopping at the first shell operator — everything after
`&&`, `||`, `;`, `|` or `&` belongs to a different invocation with its own program names.
So:

- `supervisorctl signal HUP "pgcat:*"` → ask about `pgcat:*`;
- `supervisorctl reread && supervisorctl update` → nothing to ask about, and the row
  says that rather than reporting a pass it did not earn;
- `/usr/bin/my-wrapper` → not supervisorctl at all, nothing to ask about.

`supervisorctl` is recognised under any directory and with a `.exe`/`.bat`/`.cmd` suffix,
so a Windows or a copied-in binary is still recognised as supervisorctl.

### Two commands, and only one of them is run

The row builds both and runs one:

```
flip_command  supervisorctl signal HUP "pgcat:*"     ← reported, never run
command       supervisorctl status "pgcat:*"          ← derived, and the only one run
```

The derived command is quoted unconditionally: this class executes it, so it has to mean
one thing. Whether the *configured* command's pattern reached supervisorctl unquoted is a
separate fact (`glob_exposed`), and that is the one the row fails on, because it is what
the flip will do — not what the inspection does.

### An unknown program asks a second question

Three of the four faults are repaired at the thing the command *is* — install the binary,
quote the name, start or unmask supervisord. The fourth is repaired at a *name*, and a
refusal that only says "supervisor does not know `pgcat:*`" leaves the operator with the
one question they cannot answer from this host: what should it be instead. So that fault
asks once more, and only that fault:

```
command            supervisorctl status "pgcat:*"        ← unknown program
command            supervisorctl status                   ← …so: what is it running?
```

Both are the same binary derived from the same command, and both are read-only. The
second one runs after the first has already been found missing, so a command that works —
the ordinary case, on every flip and every `db:doctor` — never pays for it.

`runningPrograms()` reads that answer the way supervisor writes it, one line per program:
`pgcat:pgcat_00   RUNNING   pid 4242, uptime 0:12:34`. It is deliberately not "the first
word of every line": the same command answers `unix:///var/run/supervisor.sock no such
file` when it cannot reach supervisord, and `supervisord` is a plausible name for a
program in an installation that names one after the daemon. So a line counts only when its
first word could be a supervisor name and its *second* word is one of supervisor's states.

`nearMisses()` then ranks the running names against the configured one and offers the
nearest few, which is the whole of what "probably should be" can honestly mean. The
relations are checked in the order an operator would think of them — the same name, the
group that name belongs to (`pgcat` and `pgcat:pgcat_00`), a prefix (`pgcat-1`), a name
that holds it (`lpr-pgcat-a`), and finally an edit distance within a third of the longer
name. Both spellings of the configured name are compared, because `pgcat` and `pgcat:*`
mean the same programs. A name with nothing in common is **not** a suggestion: pointing an
operator at a program that was never going to match is a guess dressed as an answer, and
the sentence says so instead — "none of them is close to `pgcat:*`, so the name to use is
the one above that this flip belongs to, or the program has to be added to supervisord".

At most three are named, and the sentence is written for one and for several: "the closest
is `pgcat_primary`, which is the name to write" or "the closest are `a`, `b`, which are the
names to write". Both give the group line when there is one to give — `or the whole group
"pgcat_x:*"` — because restarting a pool and signalling one program are different intents,
and only the operator knows which was meant. It is omitted when the command already
addressed that group (a `pgcat:*` asking about a `pgcat:pgcat_00` needs no hint that
`pgcat:*` exists), and it is kept when the command named a group the way it would name a
program — `pgcat` alone — which is the spelling supervisorctl reads as a single program and
the one the group line can actually repair. The full list, the exit code and the answer
are in the verdict (`running`, `near_misses`, `discovery_command`, `discovery_exit`,
`discovery_answer`), so a surface that would rather read the data than the sentence can.

### Resolution is checked the way the shell checks it

`locate()` treats a token containing a separator as a path and checks it as such;
anything else is searched for across `PATH`, with `PATHEXT` extensions on Windows. On
Windows `is_executable()` reports a `.bat`/`.cmd` script as not executable while the
shell the flip uses runs it as readily as a `.exe`, so those count as runnable — the check
exists to predict whether the command runs.

### What fails, and why the wording matters

Every `FAIL` names the command that would run, so the repair is an edit and not an
investigation. The sentence comes from `inspect()['detail']` and is used verbatim by all
three readers; the row appends what the refusal means for a report, and the flip appends
what it means for the flip:

| Fault | Detail |
|---|---|
| `empty` | `the configured supervisor command is empty, so a flip would replace the config and then have nothing to tell pgcat with — write swrr.pgcat.restart_command …` |
| `unresolved` | `a flip would run … doesn't resolve to an executable: tried supervisorctl on C:\…, … and 78 more PATH entries` |
| `unquoted` | `a flip would run … but pgcat:* is unquoted: the shell may expand it before supervisorctl sees it. Write supervisorctl signal HUP "pgcat:*" — the quoted name is the one supervisorctl receives unchanged` |
| `unknown_program` | `supervisor does not know "pgcat:*": pgcat:*: ERROR (no such group). The name has to match what supervisord runs: a group called pgcat is addressed as "pgcat:*", a single program by its own name. supervisorctl status says supervisord runs 1 program(s): pgcat_primary — the closest is "pgcat_primary", which is the name to write` |
| `unanswered` | `supervisorctl status "pgcat:*" did not answer in time: …` |
| `errored` | `supervisorctl could not answer for "pgcat:*" (exit 3): …` |

The row's `FAIL` reads `… — a flip refuses before it swaps the file, so nothing is replaced
and no mode is recorded`; the flip's error reads `… — a flip refuses before it swaps the
file, so nothing was replaced and no mode was recorded`. The fault sentence is one string;
only the consequence clause is per-surface, and it says the same thing in both.

A long `PATH` is reported as the directories it was searched in plus a count, rather than
the hundreds of candidate files a full search produces: the operator needs the first few
entries, not the arithmetic.

### Two faults also carry the line to write

The sentence names the repair for every fault; two of them reduce to an exact value, and
`db:doctor` prints that value as a `suggestion` line under the row, in the shape the config
file uses:

| Fault | Suggestion |
|---|---|
| `unquoted` | `swrr.pgcat.reload_command = 'supervisorctl signal HUP "pgcat:*"'` — the key a flip reads, and the inspected command with its program name quoted |
| `empty` | `swrr.pgcat.reload_command = 'supervisorctl signal HUP "pgcat:*"'` — the command that setting documents |

The line is `PgcatConfigFlipper::suggestionForSupervisor()`'s, because the two things it
needs are the flipper's: which of `restart_command`/`reload_command` a flip reads (the
`use_reload` switch decides it, and `inspect()` does not know), and the commands those keys
document. That is why the fault sentence names *both* keys with a parenthetical — `write
swrr.pgcat.restart_command (or reload_command, with use_reload on)` — while the line names
one: the step cannot know which, and the flipper can.

The other four faults get no line, which is `ReaderWindows::suggestion()`'s rule applied
here: a value is suggested only when the fault reduces to one exactly. An executable that
does not resolve is a PATH or an install; an unanswered command is a socket or a running
daemon; an errored one is whatever supervisorctl said. A plausible-looking value in any of
those columns would replace the operator's intent with this tool's guess.

An unknown program is the near case, and it is worth saying why it also gets no line *now
that the row can name the candidates*. The second question gives the row names — often
exactly one, sometimes three, sometimes a group they could all be addressed by — and the
sentence offers them in that shape: "the closest is …", "the closest are …". Putting that
in the value column would change what the column means. A `suggestion` is written to be
applied — `db:doctor --json` hands it to a gate, and a checklist applies it without reading
English — while `pgcat_x:pgcat_00` being the closest *name* is not evidence that this flip
belongs to that pool. That judgement is the operator's, so it belongs in the sentence, where
it is offered as the probable answer, and not in the line. The repair the row names when
there is one to name is still the `[program:]` section: a name supervisor does not have at
all is a fact only supervisord's own configuration can change.

Both faults fail the row whether or not a line is printed under it, and the sentence stays
exactly as it was: the flip's refusal and `--dry-run` print that same sentence and have no
suggestion column. The line is the half a report can be acted on without being read as
English — and, in `--json`, a string a gate can select on.

### What passes

- the flipper is not armed — nothing swaps a file, so nothing restarts supervisor either;
- the command resolves and does not invoke supervisorctl, or invokes it without naming a
  program — stated as such, so a pass is never mistaken for a verified program name;
- supervisorctl answers `status` with exit 0 and without the word `error`, and the row
  repeats supervisor's own line (`pgcat:pgcat_00 RUNNING pid 4242, uptime 0:12:34`) as
  the evidence.

### The user matters

`PATH` is the one of whoever runs the command. `db:doctor` run as a developer does not
prove the flip — run from a worker, a cron or an operator's shell — will resolve the same
way. The row's wording is deliberately about *this* user, and the preflight is worth most
when it is run as the user that performs flips.

---

## Tests that pin the rules

`tests/Unit/Pgcat/SupervisorStepTest.php`

| Test | Rule |
|---|---|
| `test_a_quoted_name_is_one_token_with_its_quotes_stripped`, `test_single_quotes_are_quotes_too` | quoting, spacing, empty tokens |
| `test_an_unquoted_pattern_character_is_marked_as_reaching_the_command` | `glob_exposed` on the token and nowhere else |
| `test_a_backslash_escapes_the_next_character_where_the_shell_would`, `test_a_windows_path_keeps_its_backslashes` | the platform split, asserted per platform |
| `test_the_read_only_command_keeps_the_binary_and_gets_a_status_verb` | the derivation, verb for verb |
| `test_the_flip_command_is_shown_with_the_program_quoted` | the quoted form the same derivation hands back |
| `test_supervisorctl_without_a_program_has_nothing_to_ask_about` | `reread && update` |
| `test_a_command_that_is_not_supervisorctl_has_nothing_to_ask_about` | `invokes()` |
| `test_a_path_qualified_binary_stays_path_qualified` | `/usr/bin/supervisorctl`, `…\supervisorctl.exe`, `.bat`, `.cmd` |
| `test_a_path_that_is_not_there_does_not_resolve`, `test_a_path_that_is_a_file_resolves`, `test_a_file_without_the_executable_bit_does_not_resolve` | path form, not PATH search |
| `test_a_bare_name_is_looked_for_on_the_path`, `test_a_name_nothing_answers_to_reports_the_directories_it_searched` | `searched` is directories, not candidates |
| `test_a_windows_script_counts_as_runnable` | `.bat`/`.cmd` under `is_executable()` |
| `test_the_runner_is_handed_the_command_and_nothing_else` | the injectable runner, so no test spawns a process |
| `test_the_discovery_command_is_the_same_binary_with_a_bare_status` | the second question is derived from the first, not configured |
| `test_the_programs_are_the_lines_whose_second_word_is_a_state` | what a program line is, and that a program is named once |
| `test_a_line_that_is_not_a_program_is_not_read_as_one` | the socket-error line, and `supervisor`'s own name |
| `test_the_nearest_name_is_the_one_the_configured_name_relates_to_most_directly` | the ranking order, not supervisor's print order |
| `test_only_names_close_to_the_configured_one_are_offered_and_never_more_than_three` | the cap, and that an unrelated name is not a candidate |
| `test_a_refusal_names_what_supervisor_is_running_and_which_name_is_nearest` | both commands run, the context, and the sentence a reader gets |
| `test_a_near_miss_that_is_the_group_already_asked_about_gets_no_group_line` | no "did you mean the thing you wrote" |
| `test_a_program_name_that_is_really_a_group_gets_the_group_line` | a bare `pgcat` asking about `pgcat:pgcat_00` is told the wildcard, because a bare name reads as one program |
| `test_the_discovery_offers_no_name_when_no_running_program_is_close` | a list with no repair in it says so |
| `test_supervisor_is_asked_nothing_further_when_the_first_answer_is_not_an_unknown_program` | the second command is asked for one fault only |

`tests/Unit/Console/DbDoctorTest.php` — the row, with a fake supervisor:

| Test | Rule |
|---|---|
| `test_the_supervisor_row_passes_when_supervisor_knows_the_program` | the pass, and the read-only guarantee: the recorded command is the derived `status` one, and a program supervisor knows is never asked a second question |
| `test_the_supervisor_row_fails_an_unquoted_program_name` | fault 2, and the shell is never given the chance |
| `test_the_supervisor_row_fails_a_binary_that_does_not_resolve` | fault 1, and names where it looked |
| `test_the_supervisor_row_fails_a_binary_that_is_not_on_the_path` | the same, through the real PATH search |
| `test_the_supervisor_row_fails_when_supervisor_does_not_know_the_program` | fault 3, with supervisor's own words |
| `test_the_supervisor_row_fails_when_supervisorctl_cannot_answer` | fault 4, kept apart from fault 3 |
| `test_the_supervisor_row_fails_a_command_that_never_answered` | the timeout path |
| `test_the_supervisor_row_fails_when_the_command_is_empty` | the empty command |
| `test_the_supervisor_row_is_moot_while_the_flipper_is_not_armed` | moot means moot |
| `test_the_supervisor_row_checks_resolution_for_a_command_that_is_not_supervisorctl` | no program to ask about |
| `test_the_supervisor_row_says_when_a_supervisor_command_names_no_program` | `reread && update` |
| `test_the_supervisor_row_names_the_running_program_without_printing_a_repair_line` | the row reports the nearest names and still prints no `suggestion`, so the two columns keep their meanings |

A live run of the row against a real application is recorded in the pull request that
added it: five configurations, one stand-in supervisorctl, and a log showing that the
only command ever executed was `status "pgcat:*"`.

`tests/Unit/Pgcat/PgcatConfigFlipperTest.php` — the flip's half:

| Test | Rule |
|---|---|
| `test_a_flip_refuses_before_the_swap_when_supervisor_does_not_know_the_program` | the ordering: no copy, no command, no mode recorded |
| `test_a_flip_refuses_before_the_swap_when_the_program_name_is_unquoted` | refused without asking anything |
| `test_a_flip_refuses_before_the_swap_when_supervisorctl_does_not_resolve` | the resolution fault, at the flip |
| `test_a_flip_refuses_before_the_swap_when_supervisorctl_cannot_answer` | the timeout fault, at the flip |
| `test_a_forced_flip_is_judged_before_it_swaps_too` | `forceMode()` goes through the same swap |
| `test_a_command_that_fails_after_the_check_puts_the_previous_config_back` | the rollback, with the file contents asserted |
| `test_the_rollback_removes_a_target_the_flip_created` | the target that did not exist before |
| `test_a_command_that_is_not_supervisorctl_is_only_checked_for_resolution` | nothing to ask, so nothing to refuse |
| `test_the_supervisor_row_quotes_the_verdict_a_flip_refuses_on` | one verdict, two surfaces (in `DbDoctorTest`) |

The ordering and the rollback were mutation-tested: moving the check after the copy fails
five of the refusal tests, and dropping the rollback fails both of its tests.

---

## The ordering, closed

This record originally ended with the gap it had opened: the row checked *before* the
window, but the flip itself still replaced the file before it discovered its command was
unusable — a preflight, not a guarantee. That gap is now closed in the flipper, in two
steps:

```php
// PgcatConfigFlipper::swap(), now
$verdict = $this->supervisorStep->inspect($this->supervisorCommand());
if (!$verdict['usable']) { throw … }        // ← nothing is copied

$previous = file_get_contents($target);     // the current mode, before it is gone
($this->fileCopier)($source, $target);

try { $this->restartSupervisor(); }
catch (\Throwable $e) { … $this->rollBack($target, …); }   // ← and put back if it fails anyway
```

The first step catches all six faults this record lists, before the file moves. The second
covers what a check cannot: supervisorctl answered `status` and then failed the command —
it raced with a restart, or pgcat would not come back up. Rolling back is the only
honest answer there, because the invariant that matters is "disk agrees with the running
processes", and after a failed command it does not.

The check costs one read-only process per flip attempt, and only when a flip is about to
happen (the mode has changed, so the poll that does nothing runs nothing). A refusal is a
failed flip with **nothing recorded**, which means the next poll tries again: once the
operator fixes the command, the flip completes by itself, with no state to reset.

The flip's error names which of the three rollback outcomes happened — put back, nothing
to put back, or **not** put back. The last is the one that needs a human: that is a new
file on disk while pgcat runs the old one, and the message says so rather than reporting
a plain failure.

---

## Known limitations

- **A near miss is a name, not an assignment.** `nearMisses()` answers "which running
  program is this name most like", which on a host running several pools can name a program
  the flip was never meant to touch — the ranking is why the row offers a short list, and
  why the offer is in the sentence and not in a `suggestion` line. The row cannot see the
  operator's intent, and does not claim to.
- **It does not prove the *next* read.** The row checks that supervisor knows the
  program. Whether pgcat then re-reads the right file is `pgcat files`, and whether the
  pools in that file are correct is the deploy's business.
- **Only the literal command is inspected.** A command that builds its arguments
  (`$(which supervisorctl) restart "$GROUP"`) is read as written; expansion is out of
  scope, and the row reports on the token it sees rather than on the runtime result.
- **One user's `PATH`.** Resolution is checked for the user running the command.
- **`status` can answer for a program that is stopped.** The row's job is "supervisor
  knows this name", not "the program is healthy"; an authenticated `status` proves the
  name is a real one, which is the fact the flip depends on.
- **A non-supervisorctl reload command is not verified end to end.** When the command
  drives something else, the row checks that the executable resolves and then says it had
  no program to ask about. Shipping a different supervisor is a supported escape hatch,
  not a verified path.
- **The check is a moment, not a guarantee about the next second.** A command that passes
  `status` can still fail when it runs; that is what the rollback is for, and it is why the
  rollback exists rather than the check being trusted.
- **The rollback writes locally.** It restores the previous bytes through a temp file in
  the target's directory, rather than through the injectable `fileCopier` — whose contract
  is "make the target the source's content", and the rollback's source is a string in
  memory. A host that replaced the copier to swap files somewhere else gets a local
  rollback.
- **The target cannot be put back if it could not be read first.** `is_file()` true and
  `file_get_contents()` false is a narrow state (an exclusive lock, an odd ACL), and the
  failure message says the file is the new one instead of pretending otherwise. That branch
  is not reachable in a portable test.

---

## What would change this decision

- **`supervisorctl` growing a dry-run mode** — candidate A becomes available and the
  derivation becomes unnecessary.
- **A deployment where the reload command is not a shell command line** — if the flipper
  ever executes an argv array instead, the tokenizer's quoting rules stop mattering and
  only resolution remains.
- **Evidence that the accident is harmless on the hosts in use** — if every target host
  is known to run with a group called `pgcat` and a working directory that can never
  contain `pgcat:*`, the unquoted-glob `FAIL` could be argued down to a warning. The
  quoted default would stay either way.

---

## Files

- `src/Pgcat/SupervisorStep.php` — tokenizer, derivation, resolution, the discovery and
  ranking of the running programs, and the injectable runner; the only class that decides
  what the inspection may run.
- `src/Pgcat/PgcatConfigFlipper.php` — `supervisorCommand()`: the command the flip runs,
  and the one the row inspects; `suggestionForSupervisor()`: the line a row prints for the
  two faults that reduce to a command, and the documented commands it prints;
  `swap()`: the refusal and the rollback.
- `src/Console/Commands/DbDoctor.php` — `pgcatSupervisor()`: the row, reading the verdict.
- `src/Providers/WeightedDatabaseServiceProvider.php` — the one inspector per application
  that the row, the flip and `--dry-run` all resolve.
- `config/db-manager.php` — the quoted defaults, with the reason in a comment.
- `tests/Support/FakeSupervisor.php` — a runner that records instead of running, with
  per-command answers for the faults that ask twice.
- `tests/Unit/Pgcat/SupervisorStepTest.php`, `tests/Unit/Console/DbDoctorTest.php`.
