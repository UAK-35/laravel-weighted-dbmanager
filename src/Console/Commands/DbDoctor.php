<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Uak35\WeightedDbManager\Database\Weighted\EndpointReadings;
use Uak35\WeightedDbManager\Database\Weighted\TimeWindowResolver;
use Uak35\WeightedDbManager\Database\Weighted\WeightedConnectionFactory;
use Uak35\WeightedDbManager\Database\Weighted\WeightedDatabaseManager;
use Uak35\WeightedDbManager\Database\Weighted\WeightResolver;
use Uak35\WeightedDbManager\Pgcat\FlipSchedule;
use Uak35\WeightedDbManager\Pgcat\PgcatConfigFlipper;
use Uak35\WeightedDbManager\Pgcat\PoolProbe;
use Uak35\WeightedDbManager\Pgcat\ScheduleBeat;
use Uak35\WeightedDbManager\Pgcat\ScheduleCauses;
use Uak35\WeightedDbManager\Pgcat\SupervisorStep;
use Uak35\WeightedDbManager\Pgcat\WindowFlipSchedule;
use Uak35\WeightedDbManager\Providers\WeightedDatabaseServiceProvider;
use Uak35\WeightedDbManager\Support\ActiveConnection;
use Uak35\WeightedDbManager\Support\BootAudit;
use Uak35\WeightedDbManager\Support\ConfigSchema;
use Uak35\WeightedDbManager\Support\ConfigValue;
use Uak35\WeightedDbManager\Support\ConnectorInUse;
use Uak35\WeightedDbManager\Support\DeploymentContract;
use Uak35\WeightedDbManager\Support\PinnedQuery;
use Uak35\WeightedDbManager\Support\ProbeStamp;
use Uak35\WeightedDbManager\Support\ReaderDays;
use Uak35\WeightedDbManager\Support\ReaderWindows;
use Uak35\WeightedDbManager\Support\RedisAccess;
use Uak35\WeightedDbManager\Support\ReplicaMetadata;
use Uak35\WeightedDbManager\Support\SwitchValue;

/**
 * php artisan db:doctor [connection] [--strict] [--json] [--config-file=path]
 * [--connection-only] [--contract]
 *
 * One command that answers "will this installation do what it is configured to
 * do", before traffic arrives. Twenty-two things can be wrong while the application
 * still boots and answers requests:
 *
 *   boot environment  the settings every row below judged are not the ones this
 *                     installation's files spell: the configuration is cached from
 *                     before the environment file was last written, so a value the
 *                     operator changed has not reached the running process. The two
 *                     facts — the file this boot read and whether the configuration
 *                     is cached — are the framework's own (`environmentFilePath()`,
 *                     `configurationIsCached()`), and the row is the one place a
 *                     container that cached at build time says so before a deploy
 *   provider swap     the framework's DatabaseServiceProvider is still the one
 *                     binding `db`, so reads are unweighted and nothing says so
 *   weighted factory  the factory is where the replica is chosen, so a host app
 *                     that rebinds `db.factory` silently loses weighting too
 *   connector         the connection is built through a connector class, and a host
 *                     application can replace the framework's own with one of its own
 *                     — a `db.connector.<driver>` binding, which is the first branch
 *                     the framework's factory takes. Nothing in configuration shows
 *                     that: an installation can read exactly as documented while every
 *                     connection goes through a class nobody can see, which on a
 *                     PostgreSQL connection is where an application adds the option
 *                     path it writes its own hosts with. The row names the class, says
 *                     whether it is the framework's or the installation's and which
 *                     binding answered, and fails when nothing can be built at all —
 *                     the connection's name is not declared, or its driver has no
 *                     connector. It asks the factory that builds connections rather
 *                     than restating the framework's map, so it cannot describe a
 *                     factory this installation does not have; where `db.factory`
 *                     cannot be asked (resolving it threw, or it is not a factory)
 *                     the row says so and leaves that binding to `weighted factory`.
 *                     It also builds the followed connection and names the class it
 *                     resolves to, because the connector is only half of what builds
 *                     one: `Connection::resolverFor($driver, …)` is a second, global
 *                     replacement point the factory never sees, and a resolver
 *                     registered after the application built its connection leaves the
 *                     application on the framework's class — a state only the resolved
 *                     object shows
 *   published config  the application never published its own config/db-manager.php
 *                     and is running on the sample values
 *   pgcat gate        swrr.pgcat.enabled is on for a connection pgcat cannot
 *                     front, or armed without the paths a flip needs
 *   pgcat files       the flipper is armed but a file a flip reads or writes is
 *                     missing or unreachable, so the swap fails at the moment
 *                     traffic is supposed to switch. The one row that never prints a
 *                     suggestion: what is wrong is a path or a permission, and neither
 *                     is a value this package can name for somebody else's filesystem
 *   write paths       every path this package must be able to write while the flipper is
 *                     armed, in one row: the flip target and the directory its temp file is
 *                     written in, the two variants a deployment renders, the state and lock a
 *                     flip records itself in, the boot stamp the window is measured from, and
 *                     the audit record with its lock. Each names the path whose mode decides
 *                     the write, the mode and owner it was found with, and what an unwritable
 *                     one costs — the inventory `pgcat files` only speaks of when one of them
 *                     fails, so an operator can see what the package writes and a gate can
 *                     find a state directory an entrypoint moved. Fails naming every path that
 *                     cannot be written, and passes with the labels it judged otherwise; prints
 *                     no suggestion, because the `chmod` lines and the findings they are dated
 *                     from are `pgcat files`'
 *   variant shape     the file a flip would swap *in* is one this installation cannot
 *                     serve: a configured path that is not a readable file, a `[pools…]`
 *                     section no pool name can be read out of, a variant that declares no
 *                     pool the pinned connection writes through, a readers variant missing
 *                     a pool that connection weights, a writer-only variant that still
 *                     declares one of them, or a file in place that is a mixture of the
 *                     two shapes — half a rendered variant. Reads the three configured
 *                     files and the connection's own pool names rather than the running
 *                     pooler, so it answers before anything is swapped; a pool whose
 *                     addresses do not resolve from here is carried as a warning, because
 *                     resolution is a fact about this host rather than about the container
 *   pgcat supervisor  the flipper is armed but the command it runs *after* the swap
 *                     cannot work: the executable does not resolve for this user, the
 *                     program name is an unquoted glob the shell may rewrite, or
 *                     supervisor does not know the program — so the flip refuses
 *                     before it swaps anything, and this row is the preflight that
 *                     says so before the window rather than at it. A program
 *                     supervisor does not know is the one fault repaired by a name, so
 *                     the row asks what supervisord is running and names the programs
 *                     nearest the configured one
 *   pgcat mode        the pooler is on the configuration the reader windows are not
 *                     asking for: the writer-only config while a window is open, so
 *                     every read meant for the replica pool reaches the writer, or the
 *                     readers config outside every window, so reads keep reaching a
 *                     replica through the hours the fallback holds them on the writer.
 *                     An armed flipper whose file cannot be read at all is not judged
 *                     — `pgcat files` judges the file itself. The row leads with which
 *                     end of the last window the flip missed
 *   pool probe armed  nothing can ask the running pooler about its pools: the switch
 *                     `swrr.pgcat.pool_probe.enabled` is off, or the file the pooler runs on
 *                     names no port to dial, so the pooler is taken at supervisord's word.
 *                     Configuration only — one file read, no socket — which is what makes
 *                     the arming question one a build container can answer, and what makes
 *                     it a row of its own rather than the first branch of the answer's
 *                     row. Passes whenever there is nothing to arm: an inert flipper, a
 *                     probe that is off, and a variant that declares no pool all read as
 *                     a pass with the sentence for which it is, because none of them is a
 *                     fault
 *   pool probe answer the running pooler is not answerable for a pool the configuration
 *                     declares: it is RUNNING, supervisord says so, and it holds no pool
 *                     for a database the application is connected to, so every read to
 *                     that pool fails. The one row that asks the pooler itself rather
 *                     than reading a file or a program list — a startup packet per
 *                     declared pool, no credentials and nothing written — and it is the
 *                     read-only half of the self-heal the per-minute flip performs.
 *                     Warns, rather than failing, for a pooler that answered nothing at
 *                     all or that answered a sentinel name no configuration declares —
 *                     the second is `indeterminate`, not `answered`, because the same
 *                     answer would have come back for a name it cannot hold. Not judged
 *                     while the boot window is still open, where the flip still
 *                     re-applies the configuration. It says `not asked` and passes when
 *                     the row above found nothing to ask, rather than going missing from
 *                     a gate that selects it by name
 *   pool repairs      how many pool repairs this container has recorded, and when the last
 *                     one happened: a repair is the self-heal working, so the row reports it
 *                     rather than failing on it — the standing fault, if there is one now,
 *                     is `pool probe answer`'s, which asks the pooler rather than reading its
 *                     history. Read from the same state file and the same derivation
 *                     `db:pgcat-flip --status` prints, so the two surfaces cannot count one
 *                     container's repairs differently
 *   replica metadata  replicas carry no weight metadata, so the resolver picks at
 *                     random while the table looks weighted — or a replica's weight,
 *                     cores or memory is a value the resolver cannot read, which
 *                     drops it from the pool or weights it as something else while
 *                     the pool still looks whole
 *   read caps         a read endpoint is capped — by its own `statement_timeout` — far below
 *                     the writer's, so a read this package routes there is cut where the
 *                     writer was configured not to cut it. The caps live in a parameter
 *                     group rather than in any file this package reads, so the row asks
 *                     each endpoint, one connection each, and only while
 *                     `swrr.read_path.enabled` arms it. An endpoint that did not answer is
 *                     named with what it said and is never a failure
 *   standby feedback  a standby in the read list has `hot_standby_feedback` off, so it does
 *                     not ask the primary to hold the row versions a query's snapshot needs
 *                     — and a read chosen for it can be cancelled by a conflict with
 *                     recovery (SQLSTATE 40001) at any moment, which is the failure the
 *                     caller has to answer. Read by the same sweep as the caps above
 *   read/write pair   what a statement pinned to the write connection reaches: the connection
 *                     declares a `read` list and a `write` block, one of the two, or neither,
 *                     and Laravel honours the pin only where the two halves are distinct. A
 *                     reading rather than a verdict — a single-host connection is a
 *                     documented installation, and whether an application pins reads is its
 *                     own setting
 *   switch values     swrr.pgcat.enabled, swrr.pgcat.use_reload or
 *                     swrr.allow_local_fallback is written as something that is not
 *                     on or off, which is refused: a cast reads 'false', 'off' and
 *                     'no' as on — and on the pgcat switch that arms a file swap
 *   reader windows    swrr.reader_windows holds entries that are not windows, which
 *                     are refused: the natural flat string '10:00-14:20' is read as
 *                     no window at all, leaving reads on the pool at every hour
 *   flip schedule     nothing moves the pooler at the boundaries the configuration is
 *                     written for: the reader-window tasks are on and no boundary is
 *                     scheduled — every window is unusable, or too short to hold the
 *                     lead and the grace — or nothing at all is scheduled while the
 *                     flipper is armed, which leaves pgcat.toml where the last write
 *                     put it while the resolver's mode moves on. The row also says
 *                     which mechanism answers a boundary when the switches read
 *                     cleanly, and refuses either of them written as something that
 *                     is not on or off — the two switches `switch values` does not
 *                     cover
 *   schedule beat     the mechanism that moves the pooler is configured and has
 *                     stopped running: the entry is registered and armed while the cron
 *                     line that fires it is gone, so every other reading describes a
 *                     world that stopped moving. The row reads the beat the entry
 *                     leaves rather than the schedule it is registered in, and names
 *                     the three causes the state file cannot show — the store the
 *                     runs' mutexes live in, how many entries run the flip, and the
 *                     boot stamp with the mode and owner it was found with
 *   store probe       the store reachability check can never run: probing is
 *                     switched off, or the record that throttles it cannot be
 *                     written, so the PING is skipped on every boot in silence
 *   probe stamp       the audit record is not being written at all: its probe stamp is
 *                     older than the runs that should move it, so the audit has stopped
 *                     running where the record lives. The row beside it asks whether a
 *                     probe *could* run; this one asks whether one has, which is the only
 *                     reading that tells a record nothing writes from a quiet one
 *   store             the primary store (Redis) is not answering, so workers fall
 *                     back to an in-process rotation that is not shared
 *   real query        the connection this run follows could not answer one real
 *                     `select 1` — or the run was not asked to try. It is the one reading
 *                     here that leaves the process, so it is asked only where `--real-test`
 *                     asks for it and says so when it was not asked, rather than being left
 *                     out of the run: a gate that reads a row by name can then tell "the
 *                     query passed" from "no query was attempted", which is the difference
 *                     an image that has no database behind it turns on
 *
 * The store is checked with a live PING rather than trusted to the store's own
 * health flag: that flag is only raised after an operation has already failed, so
 * on its own it reports "nothing has failed yet", not "the store is reachable".
 *
 * SUGGESTIONS
 * -----------
 * A row prints a `suggestion` line under itself only when it can name the replacement
 * exactly — the value the offending setting reduces to — and never when naming one would
 * mean guessing at what the operator meant. Three rows can, each from the class that owns
 * the value rather than from a second copy of the rule: `reader windows` prints the setting
 * line for the two spellings that reduce to one (`ReaderWindows`, `ReaderDays`), `pgcat gate`
 * prints `swrr.pgcat.enabled = false` when the switch is armed where pgcat cannot act, and
 * `pgcat supervisor` prints the command a flip would run when a program name needs quoting or
 * the command was left empty (`PgcatConfigFlipper`).
 *
 * Every other failure is repaired somewhere a config line cannot reach — a PATH, a permission,
 * a `[program:]` section on the supervisor side, a path only the installation knows — and
 * those rows print no line rather than a plausible-looking one. That is the same line the
 * reader-window refusal draws for a value it will not re-spell, applied to a whole row. The
 * nearest case is a program supervisord does not know: the row discovers what it *is* running
 * and its sentence names the names nearest the configured one, and it still prints no line,
 * because a near miss is a ranked answer for a reader and a line is a value a gate may apply
 * without reading it.
 *
 * CONNECTION-ONLY MODE
 * --------------------
 * `--connection-only` bounds the installation run to what a build container can answer. The six
 * rows whose subject is something the container may not have are left out — the store (`store
 * probe`, `store reachability`), the supervisor (`pgcat supervisor`), the pooler
 * (`pool probe answer`) and the read path's own endpoints (`read caps`, `standby feedback`) —
 * because a row that cannot
 * be answered does not print silence: it reports the container's own
 * missing prerequisite, and an image build fails over the absence of a thing the running container
 * was always going to provide. It is the same mistake the `--config-file` mode exists to avoid,
 * one step further in: a question that belongs to the running installation, asked of something that
 * is not one yet.
 *
 * What is left is every reading that is about this boot, this configuration and these files — the
 * environment the boot read from, the provider swap, the factory and the connector it would build,
 * the published config's key set,
 * the gate, the files a flip needs, the shape of what a flip would swap in, the replica metadata,
 * the read path's pair, the three switches,
 * the reader windows, the mode the pooler's file is on, and both schedule rows — so a container that arms pgcat over a
 * connection pgcat cannot front still exits 1, with no store, no supervisor and no reachable
 * database.
 *
 * Nothing about the rows it does ask changes: same names, same sentences, same verdicts, and the
 * same exit rule (`gateFailed()`), and the object is the same one with `checks` holding the rows
 * that were asked. That is deliberate rather than convenient — a gate written against a full run
 * reads a bounded one, and a row missing from `checks` is the only way the two differ. The rendered
 * run says which rows it left out, because a shorter report read as a complete one would be this
 * command telling an operator that six questions passed when they were never asked.
 *
 * REAL TEST
 * ---------
 * `--real-test` asks the connection this run follows for one real query —
 * `Support\PinnedQuery`'s `select 1`, the same statement `GET /health/db` runs — and the
 * `real query` row reports what came back and how long it took. It exists because the one question
 * a gate cannot read out of a configuration is whether a server is *there*: a deploy gate promotes
 * an image on exactly that proof, and no other row opens a connection at all.
 *
 * The flag is what keeps the mode's promise rather than what breaks it. `--connection-only` is
 * answerable with no reachable database, and that is kept by *not asking* — so the row is built
 * either way and reports that no query was attempted when the flag is absent, in the same words
 * the read-path rows use when their probe is not armed. A caller that asks for the query gets it in
 * either mode, and a caller that does not is never the reason an image build failed. The endpoint's
 * own `swrr.health.pinned_query` switch is not consulted: it turns the endpoint's query off where
 * the question is not worth asking, and a flag a gate passed on purpose is that gate's question.
 *
 * READ ONLY
 * ---------
 * Every check inspects configuration, resolved classes and the store. Nothing is
 * written and no flip is attempted, so the command is safe in a deploy pipeline and
 * against a live database — and nothing is *queried* either, unless `--real-test` asks
 * for exactly that: one `select 1` on the connection this run follows, which is the
 * reading a deploy gate passes the flag for. The checks that reach
 * over the network write nothing either: the store check's PING, where an
 * unreachable store costs one connect timeout; `pool probe answer`, which is one bounded
 * startup packet per pool; and the two read-path readings, which are one query per
 * endpoint on a connection that is purged on the way out — a settings query, not a
 * probe of what a replica answers. Each of the two is armed or not by its own
 * setting, and a probe that is not armed opens no socket at all. The one other
 * processes this command starts are the read-only `supervisorctl status` commands that
 * `pgcat supervisor` derives from the flip's own command — the same binary, the same
 * program name, `status` in the verb position, plus the bare `supervisorctl status` that
 * lists what supervisord runs when the program it was asked about is not one of them — so
 * nothing is restarted or signalled. For which
 * replica answers what, see db:probe-replicas. The pgcat file check judges
 * readability and writability from filesystem metadata (is_readable/is_writable);
 * it never creates a probe file to prove a directory is writable, so it leaves no
 * trace even on a read-only deploy.
 *
 * EXIT CODE
 * ---------
 * 0 when nothing failed, 1 when a check failed — or, with --strict, when any check
 * warned. That makes `php artisan db:doctor --strict` usable as a release gate: it
 * fails on "the app boots, but the configuration cannot work".
 *
 * The rule lives in gateFailed() and nowhere else, so the summary can derive why a run
 * exits 1 instead of restating it: with nothing failed and --strict on, the last line
 * says the flag is what turned the warnings into a failure, because an operator reading
 * exit 1 has only rows of PASS and WARN in front of them and nothing to tell them the
 * gate — not the installation — is what broke.
 *
 * JSON
 * ----
 * `--json` writes one object and nothing else — every row as `checks`, each with its
 * `verdict` and its `suggestions`, the run's `counts`, the `verdict` for the run, and the
 * `exit_code` the process exits with — so a deploy gate asserts on a field rather than
 * parsing the table. The flag, the envelope and the rules it keeps are the same ones
 * db:pgcat-flip's --json keeps, so the package has one machine-readable contract rather
 * than one per command.
 *
 * The key set is fixed and always present — never a key that is sometimes absent — so a
 * rule can be written as a comparison instead of a lookup that might be missing, which is
 * the rule the health payload's `counts` already follows. The verdicts are the three the
 * table prints — PASS, WARN, FAIL — rather than a second vocabulary for the same fact: the
 * report an operator read and the object a job parsed name the same row the same way, and
 * there is no mapping between the two to drift.
 *
 * Three of the keys name the subject rather than the run, and are the ones that vary: an
 * installation run publishes the connection it inspected, the connection it followed
 * (`default_connection`) and the value `db-manager.swrr.connection` was written with
 * (`swrr_connection`, null when the setting says nothing) — so *unset* and *set to the name of
 * the default* stay distinguishable in the object, which they stop being the moment the setting
 * has done its work and both read as the same followed connection. A config-file run publishes
 * the file it read instead. Beside `strict` sits `connection_only`, the other flag that changes
 * what a run *inspects* rather than how it is reported: a gate that requires a row has to be able
 * to see that this run never asked it.
 *
 * `exit_code` travels inside the object because the two halves of the one answer belong to
 * one artefact. A row's verdict says how the installation looks; the code says what the
 * gate decided about that; and `strict` explains the only case where they differ — with the
 * flag on, warnings exit 1 while every single row is PASS or WARN. That is the sentence the
 * rendered run prints beside the code, carried here as the three fields a job branches on.
 *
 * `--json` changes the report and nothing else: the same checks run, the code is the one
 * gateFailed() computed, and nothing is written either way. It composes with `--strict`,
 * which is how a pipeline will pass it, and there is no combination to refuse — a doctor is
 * not a daemon, so a report cannot be untrue of the run that produced it.
 *
 * CONFIG FILE MODE
 * ----------------
 * `--config-file=path` judges one config file instead of the installation: the values in it that
 * the package would *refuse*, reported with the same sentences, suggestions and exit rule, and
 * read out of the file alone. The switches and reader settings live in `config/db-manager.php`
 * and the replicas' metadata in `config/database.php`, so the file may be either shape — one
 * returning a `swrr` block is judged for its switches and reader windows, one returning a
 * `connections` block for the read list of the connection named on the command line, and a file
 * holding both is judged for both.
 *
 * It exists for the one moment the rows above cannot serve. A pipeline vetting a config
 * on a branch has the *old* configuration installed — the provider swap, the gate, the files,
 * the replica metadata and the store are all question about the running application, and every
 * one of them would be answered about the incumbent and printed next to the candidate's name.
 * This mode is therefore not a quieter doctor, it is a different question: no container binding,
 * no `db-manager` repository, no boot audit, no database and no Redis are touched, so it answers
 * even when the package cannot boot, and it answers about the file rather than about the host.
 *
 * What it reports is the refusals — a value the package will not read — and not the "reads as on
 * but can never act" warnings the installation rows also carry. Those need the resolver built
 * over the value, which is a fact about a running installation's routing; `db:doctor --strict`
 * on a real deployment is where they fail a pipeline. `replica metadata` is the row that would
 * look like the exception and is not: `Support\ReplicaMetadata` classifies a replica array on its
 * own — it is the classifier the installation row and the boot audit share — so a candidate read
 * list can be refused here without a resolver, which is exactly what this mode is for. The line
 * is drawn at the resolver rather than at "anything a file can be asked" so this mode stays
 * honest about what it has not looked at.
 *
 * The rows are `config file` (readable, an array, a `swrr` and/or a `connections` block, nothing
 * printed while it was read) and, for each block the file holds, the rows that judge it:
 * `replica metadata`, `switch values` and `reader windows`. Each of the last three is the same
 * rule the installation rows apply — `Support\ReplicaMetadata`, `Support\SwitchValue`,
 * `Support\ReaderWindows`/`ReaderDays`, and the flipper's `switchReadingsIn()` classifying a
 * block that has never been installed — so a value this mode passes is a value the next boot will
 * not refuse. Nothing is dated: a boot record holds findings about this installation, and the
 * candidate has never been booted.
 *
 * The exit rule, `--strict` and `--json` are the ones above, unchanged. The object's first keys
 * name the subject — `config_file` where an installation run names `connection` and
 * `default_connection` — and the rest of the key set is the same, so a gate written against one
 * mode reads the other.
 *
 * CONTRACT MODE
 * -------------
 * `--contract` asks the same installation run for a different subject: the requirements of
 * `docs/deployment-contract.md`, one row each, named by the key the record states them with
 * (`variants`, `prune`, `pooler`, `boot-stamp`, `mechanism`, `state-dir`, `supervisor`,
 * `health-check`, `store`, `read-caps`, `health-endpoint`). It exists because the rows are named
 * after the mechanism they read, not after the thing a deployment has to have, so a pipeline that
 * wants to fail a deployment was reading a list of row names out of a shell script — a copy of the
 * contract rather than the contract. The mapping is `Support\DeploymentContract`'s, so the record
 * and the guard are held to one list.
 *
 * No reading is added and no reading changes: a requirement's verdict is the loudest verdict among
 * the rows behind it — `FAIL` over `WARN` over `PASS`, the same fold a row uses over its problems —
 * and the detail names each row and its verdict, then the sentences of the ones that did not pass,
 * and the repairs those rows named are the requirement's `suggestions`. So a deploy fails on
 * `variants` and is told which of `variant shape` and `pgcat files` refused it, rather than on a
 * row a reader has to know belongs to the contract.
 *
 * A requirement no row can answer — the monitor that reads `/health/db` is the deployment's, and no
 * reading here sees it — passes and says it was not judged. That is the same
 * reading the `connector` row gives when `db.factory` cannot be asked: the package cannot fail a
 * deployment over a fact it never read. `--connection-only` composes: a requirement whose readings
 * were all left out by that mode says which ones, and one whose readings were asked and passed
 * says which ones it did not ask, so a bounded contract run is short *and* says why.
 *
 * The exit rule is unchanged — the counts are the requirements', and `gateFailed()` still decides —
 * and so is the object: `checks` holds the requirement rows, under the keys, with the same
 * `verdict`/`detail`/`suggestions` shape all three modes publish, and `connection_only` still says
 * whether the run was bounded. `--config-file` is refused beside it rather than half-honoured: a
 * file is not a deployment, and the contract asks what one has.
 */
class DbDoctor extends Command
{
    /** @var string */
    private const PASS = 'PASS';

    /** @var string */
    private const WARN = 'WARN';

    /** @var string */
    private const FAIL = 'FAIL';

    /**
     * Every on/off setting this package has, and the finding key its refusal is filed under.
     *
     * The keys come from the provider rather than being spelled again, because they are the
     * same keys the boot audit writes: a row that invented its own name for a refusal could
     * not date it from the boot that reported it.
     *
     * @var array<string, string>
     */
    private const SWITCH_KEYS = [
        'swrr.pgcat.enabled' => WeightedDatabaseServiceProvider::KEY_PGCAT_ENABLED_REFUSED,
        'swrr.pgcat.use_reload' => WeightedDatabaseServiceProvider::KEY_PGCAT_RELOAD_REFUSED,
        'swrr.allow_local_fallback' => WeightedDatabaseServiceProvider::KEY_FALLBACK_REFUSED,
    ];

    /**
     * What each of those settings is left as when its value cannot be read, said in the row's
     * own words so the sentence names the consequence instead of only the default.
     *
     * @var array<string, string>
     */
    private const SWITCH_CONSEQUENCES = [
        'swrr.pgcat.enabled' => 'the switch falls back to off — the value this setting documents — so the flipper is inert and no pgcat file is swapped',
        'swrr.pgcat.use_reload' => 'the switch falls back to on — the value this setting documents — so a flip signals a reload rather than running the restart command',
        'swrr.allow_local_fallback' => 'the switch falls back to on — the value this setting documents — so a read that cannot reach a replica may still be served from the in-process fallback',
    ];

    /**
     * The rows `--connection-only` leaves out, in the order they sit in a full run.
     *
     * WHY A TABLE AND NOT A FLAG PER CALL
     *   A row that cannot be answered does not print silence: it reports the prerequisite it is
     *   missing as a fault of the installation, so dropping one is a decision that has to be
     *   made once and read once. Declaring the six here — and asking `asks()` rather than
     *   remembering to skip a call — is what stops the bounded mode drifting into a *quieter*
     *   doctor, which is the shape of this mistake: a mode that judged a store it cannot reach
     *   would fail image builds for the container that was always going to run it.
     *
     *   Each one's subject is outside the process, and that is the rule:
     *
     *     `pgcat supervisor`  a supervisor — it runs supervisorctl, which needs the binary on
     *                         PATH and a control socket to answer
 *     `pool probe answer` the pooler — it opens a PostgreSQL startup packet to the address
 *                         the probe is named for. The arming row beside it reads a setting
 *                         and one file and opens nothing, so that half is asked in every
 *                         mode
     *     `read caps`         the read endpoints — the row connects to each one and reads
     *                         its own `statement_timeout`, which is a server the container
     *                         being built has no route to
     *     `standby feedback`  the same sweep of the same endpoints, asked for
     *                         `hot_standby_feedback`: one reading answers both rows, so
     *                         they are left out together or not at all
     *     `store probe`       the store — the row exists to keep an eye on the throttled PING,
     *                         and a run that does not send one has nothing to keep an eye on.
     *                         The closest call of the four, and the reason the reasons are
     *                         written down: the row's own reading is a config value and a
     *                         file's mode, both of which a container has — but the file is a
     *                         state directory a container usually mounts at run time, so
     *                         judging it at build time would fail an image over a directory
     *                         the image does not own
     *     `store reachability` the store — it is the PING, over a network the container may not
     *                         have yet
     *
     * A full run asks every one of them, which is what every existing caller reads and what the
     * README's table lists.
     *
     * @var list<string>
     */
    private const REACHES_OUT = [
        'pgcat supervisor',
        'pool probe answer',
        'read caps',
        'standby feedback',
        'store probe',
        'store reachability',
    ];

    /**
     * The name of the one row that is built in every run and put only when asked.
     *
     * It is a constant because three places have to agree about it and a string in each would
     * drift: the row is named here, the bounded mode's table is deliberately *not* it (the mode
     * promises no reachable database, which is kept by leaving the flag off rather than by
     * leaving the row out), and the contract has to read whether the run put it before it folds
     * the row into a requirement.
     */
    private const REAL_QUERY = 'real query';

    protected $signature = 'db:doctor
                            {connection=pgsql : Connection whose weighted replicas are inspected}
                            {--strict : Treat warnings as failures, for a deploy gate}
                            {--json : Print one JSON object — every row, its verdict, the suggestions and the exit code — instead of the rendered table}
                            {--config-file= : Judge a config file instead of this installation: read one file — a config/db-manager.php for its switches and reader windows, or a config/database.php for its replica metadata — report the values the next boot would refuse, and read nothing from the running package, so a pipeline can vet a config before it is deployed}
                            {--connection-only : Bound the run to what a build container can answer: leave out the six rows whose subject is outside the process — the store, the supervisor and the running pooler — so the command runs with no store, no supervisor and no reachable database}
                            {--real-test : Ask the connection this run follows for one real query — the `select 1` the health endpoint runs — because whether a server answers is the one fact a configuration cannot show; the only reading here that leaves the process, and one no run takes unless it is asked for by name}
                            {--contract : Judge the deployment contract instead of the installation: one row per requirement of docs/deployment-contract.md, its verdict the loudest among the rows behind it, so a deploy fails on the requirement rather than on the reading}';

    protected $description = 'Check an installation end to end: provider swap, weighted factory, connector, published config, pgcat gate, files and supervisor step, replica metadata, switch values, reader windows, store probe, the probe stamp and reachability — vet one config file without the installation, with --config-file, ask only the rows a build container can answer, with --connection-only, ask the followed connection for one real query, with --real-test, or judge the requirements of the deployment contract, with --contract';

    public function handle(): int
    {
        $argument = $this->argument('connection');
        $connection = is_string($argument) ? $argument : 'pgsql';

        // The one flag that changes what is inspected rather than how it is reported: the
        // argument below is the connection of an installation, and a file being vetted is not
        // an installation at all. Nothing after this point is reached in that mode.
        $candidate = $this->option('config-file');

        if (is_string($candidate) && $candidate !== '') {
            // A file is not a deployment, and the contract asks what one has. Refused rather than
            // half-honoured, the way the flip refuses a rehearsal that would also watch: a run that
            // silently ignored one of the two flags would report the contract of the installation
            // it happens to be running in while the operator believes it judged the candidate.
            if ($this->contract()) {
                $this->error(
                    '--contract and --config-file are mutually exclusive: the contract is a list of what a '
                    .'deployment has, and a file being vetted is not one. Vet the file with --config-file '
                    .'alone, or run --contract against the installation that would deploy it.'
                );

                return self::FAILURE;
            }

            return $this->vetConfigFile($candidate, $connection);
        }

        [$manager, $resolutionError] = $this->weightedManager();

        // The rows that are about this boot, this configuration and these files — every one of
        // them reachable from a container with no store, no supervisor and no database, which is
        // the whole of what a candidate image has.
        $rows = [
            $this->bootEnvironment(),
            $this->providerSwap($manager, $resolutionError),
            $this->weightedFactory(),
            $this->connector(),
            $this->publishedConfig(),
            $this->pgcatGate(),
            $this->pgcatFiles(),
            $this->writePaths(),
            $this->variantShape(),
        ];

        // `asks()` rather than a conditional in each caller: the rows that need something outside
        // the process are declared in one table, so which ones a bounded run leaves out is one
        // decision rather than six that could disagree. See REACHES_OUT.
        if ($this->asks('pgcat supervisor')) {
            $rows[] = $this->pgcatSupervisor();
        }

        if ($manager instanceof WeightedDatabaseManager) {
            $rows[] = $this->replicaMetadata($manager, $connection);
            $rows = array_merge($rows, $this->readPathRows($manager, $connection));
            $rows[] = $this->switchValues();
            $rows[] = $this->readerWindows();
            $rows[] = $this->pgcatMode();

            // Two rows since the question was split: arming is a setting and a file, and the
            // answer is a socket. The first is asked in every mode — a build container can read
            // a switch and a port — and the second is one of the rows `--connection-only` leaves
            // out, which is exactly what the split bought. See REACHES_OUT.
            $rows[] = $this->poolProbeArmed();

            if ($this->asks('pool probe answer')) {
                $rows[] = $this->poolProbeAnswer();
            }

            // Beside the probe rather than in `--connection-only`'s left-out set: this row reads
            // the flip's state file and opens nothing, so a build container can answer it.
            $rows[] = $this->poolRepairs();

            $rows[] = $this->flipSchedule();
            $rows[] = $this->scheduleBeat();

            if ($this->asks('store probe')) {
                $rows[] = $this->storeProbe();
            }

            // Beside the row that reads whether a probe *could* run: this one reads whether the
            // record has been written since, which is the question a schedule that stopped leaves
            // every other row unable to answer. Asked in every mode, bounded runs included: it
            // opens nothing, and a record that is not there is a fresh installation rather than a
            // fault — see probeStamp().
            $rows[] = $this->probeStamp();

            if ($this->asks('store reachability')) {
                $rows[] = $this->storeReachability($manager, $connection);
            }

            // Built whether or not it is asked for, and asked only when the flag says so: the
            // row is the one reading here that leaves the process, so a run that did not ask
            // has to say that rather than leave a gap a gate cannot tell from a pass. It sits
            // last because it is the last thing a run does — everything above reads, and this
            // one connects.
            $rows[] = $this->realQuery();
        }

        $followed = $this->followedConnection();
        $configured = $this->configuredConnection();

        // The last thing that happens to the rows, so the mapping of requirement to reading is one
        // table (`Support\DeploymentContract`) applied to the run rather than a second way of
        // producing the readings: every row above was asked exactly as it is in a plain run.
        if ($this->contract()) {
            $rows = $this->contractRows($rows);
        }

        return $this->conclude(
            $rows,
            [
                'connection' => $connection,
                'swrr_connection' => $configured,
                'default_connection' => $followed,
            ],
            $this->subjectLine($connection, $followed, $configured),
        );
    }

    /**
     * The tally, the exit code and the two ways of printing them, from the rows a run produced.
     *
     * Shared by the installation run, its contract mode and the config-file vet because the rule a
     * pipeline depends on has to be one rule: the code is `gateFailed()`'s — a failure, or any
     * warning under `--strict` — and the object carries that same code, so a run cannot be read one
     * way and acted on another. What differs between them is the *subject*, and that is what the
     * caller passes: the keys that name what was inspected (`connection` with the setting it
     * was read from and the connection it followed, or `config_file`) and the banner line above
     * the table, which is the prose spelling of the same thing.
     *
     * The subject keys are the only ones that vary. Everything a gate selects on — `verdict`,
     * `exit_code`, `counts`, `checks` — is present, in that order, in both shapes.
     *
     * @param list<array{status: string, name: string, detail: string, suggestions: list<string>}> $rows
     * @param array<string, string|null> $subject
     */
    private function conclude(array $rows, array $subject, string $banner): int
    {
        $counts = $this->counts($rows);
        $strict = (bool) $this->option('strict');

        $exitCode = $this->gateFailed($counts['failed'], $counts['warnings'])
            ? self::FAILURE
            : self::SUCCESS;

        if ($this->option('json')) {
            return $this->report($rows, $subject, $strict, $counts, $exitCode);
        }

        $this->render($rows, $banner, $counts, $this->vetting(), $this->contract(), $subject);

        return $exitCode;
    }

    /**
     * Whether this run is judging a file rather than an installation, which decides the closing
     * sentence and nothing else — the rows, the tally and the code are the same either way.
     */
    private function vetting(): bool
    {
        $candidate = $this->option('config-file');

        return is_string($candidate) && $candidate !== '';
    }

    /**
     * A config file judged on its own: the values in it that the package would refuse, read out of
     * the file and nothing else. The switches and reader windows come from a `swrr` block, the
     * replicas' metadata from a `connections` block, so the caller names the connection whose read
     * list to judge — the one the vet was asked about, which in an installation run is the argument
     * the command was given.
     *
     * This is the whole of `--config-file`, and it is deliberately not a second doctor. The rows
     * above — the provider swap, the factory, the gate, the files, the supervisor step, the store
     * — are questions about a *running installation*, and every one of them would be answered
     * about the installation the command happens to be running in rather than the file being
     * considered. That is the confusion the mode exists to avoid: a pipeline vetting the config on
     * a branch has the old configuration installed, so a report that mixed the two would judge
     * the candidate with the incumbent's values and call it a review.
     *
     * `config file` is the file itself — readable, an array, a `swrr` and/or a `connections` block,
     * and nothing printed while it was read — and each row below it judges one block the file holds:
     *
     *   - `replica metadata` — the `read` list of the named connection;
     *   - `switch values` — `swrr.pgcat.enabled`, `swrr.pgcat.use_reload` and
     *     `swrr.allow_local_fallback`;
     *   - `reader windows` — `swrr.reader_windows` and `swrr.reader_days`.
     *
     * All three are the *same rule* the installation rows apply, not a copy of it:
     * `Support\ReplicaMetadata`, `Support\SwitchValue` and `Support\ReaderWindows`/`ReaderDays` own
     * the reading, the sentences come from `ReplicaMetadata::refusals()`, `switchProblems()` and
     * `readerRefusals()`, and the flipper's own `switchReadingsIn()` classifies its two switches
     * from a block rather than from `$this->config` — so a value this mode passes is a value the
     * next boot will not refuse. No container binding, no repository, no boot audit, no database and
     * no Redis are touched: the file is the subject and the only thing read.
     *
     * What it reports is the *refusals* — the values the package will not read — and not the
     * "reads as on but can never act" warnings the installation rows also carry. Those need the
     * resolver built over the value (a window whose start is not before its end, a day list with no
     * day in 1…7, or the pool arithmetic a read list's weights add up to): they are facts about a
     * running installation's routing, and `db:doctor --strict` on a real deployment is where they
     * fail a pipeline. `replica metadata` is no exception: `ReplicaMetadata::refusals()` is a pure
     * classifier over one replica array, so a candidate read list can be refused without the
     * resolver — which is the whole reason this row is here and the pool arithmetic is not. The
     * line is drawn at the resolver rather than at "everything a file can be asked" so that this
     * mode stays honest about what it has not looked at.
     */
    private function vetConfigFile(string $path, string $connection): int
    {
        $file = $this->readConfigFile($path, $connection);
        $rows = [$file['row']];

        if ($file['replicas'] !== null) {
            $rows[] = $this->replicaMetadataIn($connection, $file['replicas']);
        }

        if ($file['swrr'] !== null) {
            $rows[] = $this->switchValuesIn($file['swrr']);
            $rows[] = $this->readerWindowsIn($file['swrr']);
        }

        return $this->conclude(
            $rows,
            ['config_file' => $path],
            'Config file vetted: <comment>' . $path . '</comment> — the values it would be refused for, read without the installation',
        );
    }

    /**
     * The file a vet run was pointed at: the row that reports it, and the two blocks to judge —
     * each null when the file does not hold it, which is every way the file itself can be wrong.
     * The read list is the named connection's, because `config/db-manager.php` holds no replicas:
     * a candidate `config/database.php` carries them under `connections`, next to the `read` list
     * whose metadata is being weighed, and the connection whose list to judge is the one the run
     * was told to inspect.
     *
     * The file is `require`d in an isolated closure and its output is captured, so a config file
     * that echoes or prints cannot corrupt the report it is being judged in: what it printed is
     * reported as its own problem, because `config:cache` would write that output into the cached
     * file too. A file that throws is a result rather than a stack trace, the same way every other
     * check in this command treats a failure as a row.
     *
     * Every wrong shape names the mistake rather than only the symptom, including the two a reader
     * of these docs is most likely to make: pointing at the `swrr` block itself instead of the file
     * that returns it, and pointing at a `connections` file that does not hold the connection the
     * run named.
     *
     * What the file printed is a problem *beside* the shape rather than instead of it, whichever
     * state the file is in — a file whose shape is wrong can print as well, and the two are
     * repaired differently, so a row that named only the shape would send an operator back through
     * the pipeline to hear about the output. That is the whole reason this row is assembled out of
     * a list of problems rather than out of one sentence chosen from several, and it is the same
     * assembly the installation rows use (`configFileRow()`).
     *
     * Both blocks are returned rather than one shape being required, so a file holding only the one
     * the run did not come for is still named for what it does hold: a vet that failed a
     * `config/database.php` for lacking a `swrr` block would be refusing a file that is exactly what
     * it was asked to read.
     *
     * @return array{
     *     row: array{status: string, name: string, detail: string, suggestions: list<string>},
     *     swrr: array<string, mixed>|null,
     *     replicas: list<array<string, mixed>>|null,
     * }
     */
    private function readConfigFile(string $path, string $connection): array
    {
        if (!is_file($path)) {
            return [
                'row' => $this->configFileRow([
                    self::problem(self::FAIL, sprintf(
                        '%s is not a file that can be read — name the candidate config/db-manager.php this host can open',
                        $path,
                    )),
                ]),
                'swrr' => null,
                'replicas' => null,
            ];
        }

        $printed = '';
        $depth = ob_get_level();

        try {
            ob_start();
            $loaded = (static fn (string $file): mixed => require $file)($path);
            $printed = (string) ob_get_clean();
        } catch (Throwable $e) {
            // The buffer this command opened, read before the flush below drops it: a file that
            // printed and then threw is the same two problems as one that printed and returned a
            // shape nobody can judge. Read only while it is still the innermost buffer, because an
            // outer one belongs to whatever called this command and is not this file's output.
            $printed = ob_get_level() === $depth + 1 ? (string) ob_get_contents() : '';

            // Down to where this read started, and no further: the loop used to empty every buffer
            // to zero, which under a test runner is the buffer the runner itself is using — a
            // candidate file that throws is not a reason to close somebody else's output. The
            // buffers above `$depth` are this command's own, and the ones a throwing file left
            // open are above it too, so both are closed here rather than only the first.
            while (ob_get_level() > $depth) {
                ob_end_clean();
            }

            return [
                'row' => $this->configFileRow([
                    self::problem(self::FAIL, sprintf('%s could not be read: %s', $path, $e->getMessage())),
                    ...self::printedProblems($printed),
                ]),
                'swrr' => null,
                'replicas' => null,
            ];
        }

        if (!is_array($loaded)) {
            return [
                'row' => $this->configFileRow([
                    self::problem(self::FAIL, sprintf(
                        '%s returned %s, not an array — the vet reads the file the way config:cache does, as whatever it returns',
                        $path,
                        get_debug_type($loaded),
                    )),
                    ...self::printedProblems($printed),
                ]),
                'swrr' => null,
                'replicas' => null,
            ];
        }

        $hasSwrr = array_key_exists('swrr', $loaded);
        $hasConnections = array_key_exists('connections', $loaded);

        $problems = [];
        $swrr = null;
        $replicas = null;

        // A file that holds neither block is not a config this mode can judge, and the two ways it
        // can look like one are named apart: the `swrr` block itself passed instead of the file that
        // returns it, and a connection definition passed instead of the `config/database.php` that
        // returns it under `connections`.
        if (!$hasSwrr && !$hasConnections) {
            $blockKeys = array_values(array_intersect(['pgcat', 'reader_windows', 'reader_days', 'allow_local_fallback'], array_keys($loaded)));
            $connectionKeys = array_values(array_intersect(['host', 'driver', 'read', 'database', 'username'], array_keys($loaded)));

            $problems[] = self::problem(self::FAIL, self::noBlockSentence($path, $blockKeys, $connectionKeys));
        }

        if ($hasSwrr) {
            $swrr = is_array($loaded['swrr']) ? ConfigValue::assoc($loaded['swrr']) : null;

            if ($swrr === null) {
                $problems[] = self::problem(self::FAIL, sprintf(
                    '%s holds "swrr" as %s, not a block of settings',
                    $path,
                    get_debug_type($loaded['swrr']),
                ));
            }
        }

        if ($hasConnections) {
            $connections = $loaded['connections'];

            if (!is_array($connections)) {
                $problems[] = self::problem(self::FAIL, sprintf(
                    '%s holds "connections" as %s, not a block of connections',
                    $path,
                    get_debug_type($connections),
                ));
            } elseif (!array_key_exists($connection, $connections)) {
                $defined = array_map(static fn (mixed $key): string => (string) $key, array_keys($connections));

                $problems[] = self::problem(self::FAIL, $defined === []
                    ? sprintf('%s holds an empty "connections" block, so there is no read list for [%s] to judge', $path, $connection)
                    : sprintf(
                        '%s holds a "connections" block without [%s] — it defines: %s. Name the connection whose read list to judge, or a file that holds it',
                        $path,
                        $connection,
                        implode(', ', $defined),
                    ));
            } else {
                $replicas = ConfigValue::assocList(
                    ConfigValue::assoc($connections[$connection])['read'] ?? [],
                );
            }
        }

        if ($problems !== []) {
            return [
                'row' => $this->configFileRow([...$problems, ...self::printedProblems($printed)]),
                'swrr' => $swrr,
                'replicas' => $replicas,
            ];
        }

        return [
            'row' => $this->configFileRow([
                self::problem(self::PASS, self::configFileSentence($path, $connection, $swrr !== null, $replicas !== null)),
                ...self::printedProblems($printed),
            ]),
            'swrr' => $swrr,
            'replicas' => $replicas,
        ];
    }

    /**
     * A file holding neither block, in the words that send the reader to the file that returns it.
     *
     * Both mistakes are named rather than the more likely one alone, because the two files this mode
     * reads have two different blocks and a pipeline usually vets both: a reader who pointed at the
     * wrong one should not have to guess which of the two shapes was expected.
     *
     * @param list<string> $blockKeys top-level keys that belong under `swrr`
     * @param list<string> $connectionKeys top-level keys that belong under `connections.<name>`
     */
    private static function noBlockSentence(string $path, array $blockKeys, array $connectionKeys): string
    {
        if ($blockKeys !== []) {
            return sprintf(
                '%s holds %s at the top level, not under a "swrr" key — name the config file that returns it (the array is read under "swrr"), not the block inside it',
                $path,
                implode(', ', $blockKeys),
            );
        }

        if ($connectionKeys !== []) {
            return sprintf(
                '%s holds %s at the top level, not under a "connections" key — name the config/database.php that returns it (connections are read under "connections"), not one connection inside it',
                $path,
                implode(', ', $connectionKeys),
            );
        }

        return sprintf(
            '%s returns an array without a "swrr" or a "connections" block, so there is nothing to judge',
            $path,
        );
    }

    /**
     * What the vet read, in one sentence: which block(s) the file returned, and which rows judge
     * them. The spelling follows the file rather than the mode, so a run pointed at a
     * `config/database.php` says the read list was judged and a run pointed at a
     * `config/db-manager.php` says the switches were, and a file holding both says both.
     */
    private static function configFileSentence(string $path, string $connection, bool $swrr, bool $replicas): string
    {
        if ($replicas) {
            return $swrr
                ? sprintf('%s read as an array holding a "swrr" block and a "connections" block — its switches and reader settings, and the read list of connection [%s], are judged below', $path, $connection)
                : sprintf('%s read as an array holding a "connections" block — the read list of connection [%s] is judged below', $path, $connection);
        }

        return sprintf('%s read as an array holding a "swrr" block — its switches and reader settings are judged below', $path);
    }

    /**
     * One problem, in the shape a row's problems are assembled from: the verdict, the finding key a
     * boot record would date it by (null where nothing remembers the state), the sentence, and the
     * repair line if the value reduces to one.
     *
     * The four keys are spelled once here because an absent one is silent: a missing `key` reads as
     * a problem no boot ever saw, and a missing `suggestion` as a repair the row could not name.
     * Only the vet's `config file` row builds its problems through this — the installation rows
     * write theirs out, because their keys and repairs come from tables beside them — so nothing
     * else depends on the defaults here.
     *
     * @return array{status: string, key: string|null, sentence: string, suggestion: string|null}
     */
    private static function problem(string $status, string $sentence): array
    {
        return ['status' => $status, 'key' => null, 'sentence' => $sentence, 'suggestion' => null];
    }

    /**
     * What a file printed while it was being read, as a problem — or nothing at all, which is what
     * an ordinary config file produces.
     *
     * A warning rather than a failure: the file is usable, and what it printed is a fact about it
     * that `config:cache` would write into the cached config. It is a problem of the row's own
     * rather than a clause of another one, so it is named whether or not the values could be read.
     *
     * @return list<array{status: string, key: string|null, sentence: string, suggestion: string|null}>
     */
    private static function printedProblems(string $printed): array
    {
        if ($printed === '') {
            return [];
        }

        return [self::problem(self::WARN, sprintf(
            'the file printed %d byte(s) while being read, which config:cache writes into the cached file',
            strlen($printed),
        ))];
    }

    /**
     * `config file`, assembled out of every problem the file has.
     *
     * The vet's row is a list rather than one sentence chosen from several, because a file can be
     * wrong in two ways at once: the shape it needs to be judged at all, and the bytes it printed
     * while it was being read. An operator has to clear both, and clearing one of them should not
     * cost a second run of the pipeline to hear about the other.
     *
     * Nothing here is dated and nothing carries a repair, and both are deliberate. The subject is a
     * file that has never been booted, so there is no finding to date a problem from — the same
     * reason `switch values` reports a candidate's refusals without dates — and neither a wrong
     * shape nor a stray byte reduces to a value the package can name exactly. So the record passed
     * to the shared assembly is empty, which is the whole of what "the vet has no record" means.
     *
     * @param list<array{status: string, key: string|null, sentence: string, suggestion: string|null}> $problems
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function configFileRow(array $problems): array
    {
        return $this->datedRow('config file', $problems, []);
    }

    /**
     * The switches a `swrr` block holds, as the package would read them: what each resolves to,
     * and which of them would be refused.
     *
     * The block is all it takes, which is the point — the flipper classifies its two from a block
     * (`switchReadingsIn()`) and the provider's own switch reader takes the value, so the same
     * three readings are available for a file that has never been installed.
     *
     * @param array<string, mixed> $swrr
     * @return array{
     *     on: array{enabled: bool, use_reload: bool, fallback: bool},
     *     refused: array<string, string>,
     * }
     */
    private static function switchesIn(array $swrr): array
    {
        $pgcat = PgcatConfigFlipper::switchReadingsIn(ConfigValue::assoc($swrr['pgcat'] ?? null));
        $fallback = WeightedDatabaseServiceProvider::allowLocalFallback($swrr['allow_local_fallback'] ?? null);

        $refused = $pgcat['refused'];

        if ($fallback['refused'] !== null) {
            $refused['swrr.allow_local_fallback'] = $fallback['refused'];
        }

        return [
            'on' => [
                'enabled' => $pgcat['on']['enabled'],
                'use_reload' => $pgcat['on']['use_reload'],
                'fallback' => $fallback['on'],
            ],
            'refused' => $refused,
        ];
    }

    /**
     * The three switches as one line: what each reads as, and that nothing is refused.
     *
     * One sentence for both modes, because "this switch reads as off" is the same fact about a
     * value whether the value is installed or proposed — and a file that passes the vet and a boot
     * that obeys the switch must not be able to say it differently.
     *
     * @param array{enabled: bool, use_reload: bool, fallback: bool} $on
     */
    private static function switchSentence(array $on): string
    {
        return implode(', ', [
            'swrr.pgcat.enabled '.self::switchWord($on['enabled']),
            'swrr.pgcat.use_reload '.self::switchWord($on['use_reload']),
            'swrr.allow_local_fallback '.self::switchWord($on['fallback']),
        ]).' — each reads as on or off, so nothing is refused';
    }

    /**
     * `switch values` for a config file: the same problems as the installation row, from the
     * file's block, with nothing to date them from.
     *
     * No boot record is consulted, and that is not an omission: a record holds findings written by
     * boots of *this installation*, and the file has never been booted — dating a candidate value
     * from the incumbent's history would be a claim about when the wrong value started, made about
     * a value that has never been read.
     *
     * @param array<string, mixed> $swrr
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function switchValuesIn(array $swrr): array
    {
        $switches = self::switchesIn($swrr);

        if ($switches['refused'] === []) {
            return $this->row('switch values', self::PASS, self::switchSentence($switches['on']));
        }

        return $this->datedRow('switch values', self::switchProblems($switches['refused']), []);
    }

    /**
     * `reader windows` for a config file: the two refusals, from the file's values.
     *
     * A pass says what it read, because "nothing refused" and "nothing configured" are different
     * facts and only one of them means the fallback is deliberately off — the same distinction the
     * installation row draws when it passes with the reason stated.
     *
     * @param array<string, mixed> $swrr
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function readerWindowsIn(array $swrr): array
    {
        $refusals = self::readerRefusals($swrr['reader_windows'] ?? null, $swrr['reader_days'] ?? null);

        if ($refusals['problems'] !== []) {
            return $this->datedRow('reader windows', $refusals['problems'], []);
        }

        $detail = $refusals['windows'] === []
            ? 'no swrr.reader_windows set — the documented opt-out, so every read uses the replica pool'
            : sprintf(
                '%d window(s) and %d day(s) read as written — nothing is refused',
                count($refusals['windows']),
                count($refusals['days']),
            );

        return $this->row('reader windows', self::PASS, $detail);
    }

    /**
     * `replica metadata` for a config file: the values in the candidate read list the next boot
     * would refuse, from the classifier the installation row and the boot audit already share.
     *
     * There is no pool here and this row does not pretend otherwise. The installation row reads the
     * pool the resolver built — `poolExclusions()` for what left it and why, `replicaStatus()` for
     * what stayed — and none of that exists for a file nobody has booted. What does exist is
     * `ReplicaMetadata::refusals()`, a pure function over one replica array: the same reading the
     * boot refuses a value with, so a candidate this row passes is a candidate the next boot will
     * not refuse. The row therefore reports the refusals and, on a pass, names the replicas written
     * as `weight: 0` — the one value the classifier calls a decision rather than a fault — exactly
     * as the installation row names them in its count. It reports no totals and no pool size,
     * because those are the resolver's arithmetic and not a fact about the file.
     *
     * @param list<array<string, mixed>> $replicas
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function replicaMetadataIn(string $connection, array $replicas): array
    {
        if ($replicas === []) {
            return $this->row(
                'replica metadata',
                self::PASS,
                sprintf('no read list on [%s] — the connection reads from its writer, so there is no replica metadata to refuse', $connection),
            );
        }

        $problems = [];
        $disabled = [];

        foreach ($replicas as $replica) {
            $named = ReplicaMetadata::key($replica);

            if (ReplicaMetadata::disables($replica)) {
                $disabled[] = $named;
            }

            foreach (ReplicaMetadata::refusals($replica) as $refusal) {
                $problems[] = '['.$named.'] '.$refusal['sentence'];
            }
        }

        // The classifier's own words for a drain, shared with the installation row, so a replica
        // named here and a replica named there carry the same sentence.
        $drained = $disabled === [] ? '' : implode(', ', $disabled).' — '.ReplicaMetadata::DISABLED;

        if ($problems !== []) {
            return $this->row(
                'replica metadata',
                self::FAIL,
                sprintf(
                    'replica metadata the resolver cannot read on [%s]: %s — the value the next boot reads is not the one written here.%s',
                    $connection,
                    implode('; ', $problems),
                    $drained === '' ? '' : ' Also drained on purpose: '.$drained.'.',
                ),
            );
        }

        if ($disabled !== []) {
            return $this->row(
                'replica metadata',
                self::PASS,
                sprintf(
                    'the read list on [%s] holds %d replicas and %d of them are drained: %s — the rest are read as written, so nothing is refused',
                    $connection,
                    count($replicas),
                    count($disabled),
                    $drained,
                ),
            );
        }

        return $this->row(
            'replica metadata',
            self::PASS,
            sprintf(
                '%d replicas on [%s], and every value the file writes is the value the resolver reads — nothing is refused',
                count($replicas),
                $connection,
            ),
        );
    }

    /**
     * The rule the whole command exists to enforce, in one place: a run fails when a check
     * failed, or — under `--strict` — when any check warned.
     *
     * Both the exit code and the summary read it, so the sentence an operator gets next to
     * the code cannot describe a different rule from the one that produced it.
     */
    private function gateFailed(int $failed, int $warned): bool
    {
        return $failed > 0 || ($warned > 0 && (bool) $this->option('strict'));
    }

    /**
     * Resolve `db` once, so every later check reports against the same manager and
     * a failure to resolve it is a result rather than a stack trace.
     *
     * @return array{0: WeightedDatabaseManager|null, 1: string|null}
     */
    private function weightedManager(): array
    {
        try {
            $manager = DB::getFacadeRoot();
        } catch (Throwable $e) {
            return [null, $e->getMessage()];
        }

        return [$manager instanceof WeightedDatabaseManager ? $manager : null, null];
    }

    /**
     * The two facts about a boot that no other row can state: which file it read its settings from,
     * and whether the values it read came from the config files or from a cache built earlier.
     *
     * WHY THIS ROW EXISTS
     *   Every row below reads the *running* configuration, and the running configuration is not
     *   always the one an operator is editing. `php artisan config:cache` freezes the arrays the
     *   config files returned — `env()` calls included — into `bootstrap/cache/config.php`, and from
     *   then on a change to `.env` reaches nothing: the loader still reads that file for the
     *   environment, and reads the cached arrays for everything else. Container images make it
     *   routine — cache at build time, mount a real `.env` at run time — and no other row can see
     *   it, because a cached config is a perfectly readable config. This row is about the *pair*: the
     *   file this boot read, and whether that cache is older than it.
     *
     * WHAT EACH VERDICT MEANS
     *   - not cached — a fact, with the file named (`no .env file at all` is a shape, not a fault:
     *     an installation can keep every setting in the process environment);
     *   - cached, and the settings file is not newer than it — a fact again, and the order
     *     `config:cache` documents: the deploy cached after it wrote its settings, so the values the
     *     rows below read are the ones the files spell;
     *   - cached, and the settings file *is* newer — a **warning**, and the one state this row can
     *     fail a release on. The rows below read the cached values, so the setting the operator
     *     changed has not arrived, and `--strict` is how a deploy refuses to ship that.
     *
     *   A cache file that cannot be stat'ed compares with nothing, so it falls to the middle state
     *   rather than to the warning: this row reports what it read and invents no ordering it could
     *   not measure.
     *
     * WHERE THE TWO FACTS COME FROM
     *   The framework's own methods, not a convention re-derived here. `environmentFilePath()` is the
     *   file this boot's environment loader was pointed at — `.env`, or whichever file
     *   `loadEnvironmentFrom()` named, which is the case a hardcoded `.env` would report wrongly — and
     *   `configurationIsCached()` is the same answer the framework's own `config:cache` and
     *   `config:clear` consult. Both live on `Foundation\Application` rather than on the contract
     *   `$this->laravel` is typed as, which is why this row — and only this row — asks whether it is
     *   looking at a Laravel application before it asks them; an application that is not one says so
     *   instead of failing, and the two facts are simply not readable there.
     *
     * No finding key: the audit remembers nothing about a boot's environment, so there is nothing a
     * cached configuration could be dated from. The row states this run's two facts as they are.
     *
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function bootEnvironment(): array
    {
        $name = 'boot environment';
        $app = $this->laravel;

        if (!$app instanceof Application) {
            return $this->row($name, self::PASS, sprintf(
                'this application (%s) is not a Laravel application, so the file this boot read settings '
                . 'from and whether its configuration is cached cannot be named here',
                get_debug_type($app),
            ));
        }

        $file = $app->environmentFilePath();

        $reads = is_file($file)
            ? sprintf('this boot read settings from %s', $file)
            : sprintf(
                '%s is not there, so this boot read no settings file at all: every value came from the process environment',
                $file,
            );

        if (!$app->configurationIsCached()) {
            return $this->row($name, self::PASS, sprintf(
                '%s, and the configuration is not cached, so every value the rows below read is the one '
                . 'the files in config/ spell at this boot',
                $reads,
            ));
        }

        $cache = $app->getCachedConfigPath();
        $cachedAt = @filemtime($cache);
        $writtenAt = @filemtime($file);

        if ($cachedAt === false || $writtenAt === false || $writtenAt <= $cachedAt) {
            return $this->row($name, self::PASS, sprintf(
                '%s, and the configuration is cached at %s — not older than that file, so the values the '
                . 'rows below read are the ones these files spell',
                $reads,
                $cache,
            ));
        }

        return $this->row($name, self::WARN, sprintf(
            '%s, and the configuration cached at %s was built before it: the rows below read the cached '
            . 'values, so a setting this file changed since has not arrived. `php artisan config:clear` '
            . '(or a fresh `config:cache`) re-reads the files, and until it runs this installation does '
            . 'not obey them',
            $reads,
            $cache,
        ));
    }

    /**
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function providerSwap(?WeightedDatabaseManager $manager, ?string $resolutionError): array
    {
        if ($resolutionError !== null) {
            return $this->row('provider swap', self::FAIL, 'resolving db failed: ' . $resolutionError);
        }

        if (!$this->providerRegistered()) {
            return $this->row(
                'provider swap',
                self::FAIL,
                'WeightedDatabaseServiceProvider is not registered — see the README\'s provider step',
            );
        }

        if (!$manager instanceof WeightedDatabaseManager) {
            return $this->row(
                'provider swap',
                self::FAIL,
                'db is not the weighted manager, so reads are served unweighted',
            );
        }

        return $this->row('provider swap', self::PASS, 'WeightedDatabaseServiceProvider is in charge, weighted manager bound');
    }

    /**
     * The replica is chosen inside the factory, so this binding is what makes
     * weighting run at all — a host app can register the provider and still lose
     * weighting by replacing `db.factory`.
     *
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function weightedFactory(): array
    {
        try {
            $factory = $this->laravel->make('db.factory');
        } catch (Throwable $e) {
            return $this->row('weighted factory', self::FAIL, 'resolving db.factory failed: ' . $e->getMessage());
        }

        if (!$factory instanceof WeightedConnectionFactory) {
            return $this->row(
                'weighted factory',
                self::FAIL,
                'db.factory is ' . get_debug_type($factory) . ' — replica selection happens there, so reads stay unweighted',
            );
        }

        return $this->row('weighted factory', self::PASS, 'db.factory is ' . WeightedConnectionFactory::class);
    }

    /**
     * Which connector class the connection is built through, and where that answer came from.
     *
     * WHY THIS IS A ROW
     *   The factory above decides how a connection is built; this decides what builds it. A host
     *   application replaces the framework's PostgreSQL connector by binding
     *   `db.connector.pgsql` — the first branch the framework's own `createConnector()` takes —
     *   and the replacement is often load-bearing: it is where an application adds the option
     *   path its own connection config is written with. None of that is visible in configuration,
     *   and none of it is visible from `weighted factory` either, which stops at the class of the
     *   binding that builds connections rather than the class that builds *this* one.
     *
     *   A replaced connector is a pass, deliberately: it is a documented extension point and the
     *   ordinary way an application adds to the framework, so a row that warned on it would warn
     *   on the installations that are doing the right thing. What the row is for is being able to
     *   *see* it — the class, whether it is the framework's own or the installation's, and the
     *   binding that answered — which is what makes an option path that has gone missing a
     *   five-second question rather than a two-repository one.
     *
     *   It fails where nothing can be built at all: the connection `ActiveConnection` follows is
     *   not declared (no driver), or declares a driver no connector is registered for. Both are
     *   a mistyped name or driver rather than a fault of this package, and neither is reported by
     *   any other row — the gate only asks what pgcat can front, and only while it is armed. A
     *   connection whose connector exists but whose build throws fails here too, with the
     *   framework's own sentence: the installation cannot build the connection it follows.
     *
     *   The connector is only half of what builds a connection. The other half is the class the
     *   framework constructs around the PDO, and that class has its own replacement point —
     *   `Connection::resolverFor($driver, …)`, a global registry `createConnection()` consults
     *   before its own map. A resolver is invisible to the connector and to configuration, so the
     *   row also names the class the connection resolves to and says whether the installation's
     *   resolver is registered and whether the connection in use was built through it. A resolver
     *   registered after the connection was built leaves the app using the framework's class,
     *   which is a state only the resolved object can show; it is reported rather than failed for
     *   the same reason a replaced connector is a pass — the replacement is the extension point,
     *   and what an operator needs is to see which one is in effect.
     *
     *   Where `db.factory` cannot be asked — resolving it threw, or what it resolved to is not a
     *   `ConnectionFactory` — the row passes and says the reading was not taken, the way every
     *   other row that cannot be asked does. That binding's own fault is `weighted factory`'s to
     *   report, and it is reported there: a second failure for one cause would make the tally read
     *   as two problems.
     *
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function connector(): array
    {
        $reading = ConnectorInUse::resolve($this->laravel->make(Repository::class), $this->laravel);

        if ($reading['factory_error'] !== null) {
            return $this->row('connector', self::PASS, sprintf(
                '%s, so which connector builds [%s] is not read here — the weighted factory row is where '
                .'that binding is judged',
                $reading['factory'] === null
                    ? 'db.factory could not be resolved: '.$reading['factory_error']
                    : 'db.factory '.$reading['factory_error'],
                $reading['connection'],
            ));
        }

        if ($reading['error'] !== null) {
            return $this->row('connector', self::FAIL, sprintf(
                'connection [%s] (%s) resolves no connector: %s',
                $reading['connection'],
                $reading['source'],
                $reading['error'],
            ));
        }

        if ($reading['connection_error'] !== null) {
            return $this->row('connector', self::FAIL, sprintf(
                'connection [%s] (driver %s) is built by %s, but the connection itself could not be built: %s',
                $reading['connection'],
                $reading['driver'],
                (string) $reading['connector'],
                $reading['connection_error'],
            ));
        }

        return $this->row('connector', self::PASS, sprintf(
            'connection [%s] (driver %s) is built by %s — %s; %s',
            $reading['connection'],
            $reading['driver'],
            (string) $reading['connector'],
            $reading['binding'] === null
                ? 'the framework\'s own connector for that driver: nothing is bound at '
                    .ConnectorInUse::BINDING_PREFIX.$reading['driver']
                : 'resolved from the '.$reading['binding'].' binding, an installation\'s connector in place '
                    .'of the framework\'s own',
            $this->connectionClass($reading),
        ));
    }

    /**
     * The half of the `connector` row the factory cannot answer: the class the connection is.
     *
     * Three states, and each names a fact rather than a judgement. With no resolver registered,
     * the class is the framework's own for the driver and that is all that is true. With one
     * registered and the connection built through it, the class is the installation's. With one
     * registered and the connection in use older than it, the class is still the framework's own
     * and the sentence says the replacement has not reached the connection the application holds —
     * which is the one state in which "a replacement is registered" and "a replacement is in
     * effect" are different answers.
     *
     * @param array<string, mixed> $reading `ConnectorInUse::resolve()`'s answer
     */
    private function connectionClass(array $reading): string
    {
        $class = is_string($reading['connection_class'] ?? null) ? $reading['connection_class'] : '';
        $driver = is_string($reading['driver'] ?? null) ? $reading['driver'] : '';

        if (($reading['resolver'] ?? null) === null) {
            return 'the connection resolves to '.$class.' — no connection resolver is registered for '
                .$driver.', so the class is the framework\'s own';
        }

        if (($reading['resolver_applied'] ?? false) === true) {
            return 'the connection resolves to '.$class.' — an installation\'s connection resolver for '
                .$driver.' is registered, and this connection was built through it';
        }

        return 'the connection in use resolves to '.$class.' — an installation\'s connection resolver for '
            .$driver.' is registered, but this connection was not built through it, so the class is the '
            .'framework\'s own';
    }

    /**
     * The application owns config/db-manager.php; the package ships a sample. A
     * missing copy means the sample is in use, and a byte-identical copy means
     * nobody has reviewed the values yet. Neither is an error — both are worth
     * saying before anyone trusts a window or a path that was never chosen.
     *
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function publishedConfig(): array
    {
        $published = $this->laravel->configPath('db-manager.php');
        $sample = dirname(__DIR__, 3) . '/config/db-manager.php';

        if (!is_file($published)) {
            return $this->row(
                'published config',
                self::WARN,
                'no config/db-manager.php — the package sample is in use: '
                .'php artisan vendor:publish --tag=db-manager-config',
            );
        }

        if (is_file($sample) && file_get_contents($published) === file_get_contents($sample)) {
            return $this->row(
                'published config',
                self::WARN,
                'config/db-manager.php is still byte-identical to the sample — no value has been reviewed',
            );
        }

        // The values in the file are the installer's; the *keys* in it are this package's, and they
        // are the half a published file cannot keep in step on its own. The file is read rather than
        // the repository asked, because the repository is the merge of the sample and this file: a
        // key the published copy is missing falls back to the sample's value and would read as
        // current. See ConfigSchema.
        $reading = $this->publishedGeneration($published);

        if ($reading['error'] !== null) {
            return $this->row('published config', self::WARN, sprintf(
                'config/db-manager.php is published and differs from the sample, but it could not be read as '
                .'a config file, so the key set it holds cannot be placed against this package\'s (%s = %d): %s',
                ConfigSchema::KEY,
                ConfigSchema::GENERATION,
                $reading['error'],
            ));
        }

        $stale = ConfigSchema::sentence($reading);

        if ($stale !== null) {
            return $this->row(
                'published config',
                self::WARN,
                'config/db-manager.php is published and differs from the sample, but '.$stale,
            );
        }

        return $this->row('published config', self::PASS, sprintf(
            'config/db-manager.php is published, differs from the sample, and declares the current key set '
            .'(%s = %d)',
            ConfigSchema::KEY,
            ConfigSchema::GENERATION,
        ));
    }

    /**
     * The generation a published `config/db-manager.php` declares, read out of the file itself.
     *
     * WHY THE FILE AND NOT THE REPOSITORY
     *   `config('db-manager.generation')` is the *merge* of this package's sample and the published
     *   copy, with the copy winning key by key — so a published file that predates the key reads as
     *   the package's own generation, which is exactly the silence this reading exists to break. The
     *   file is therefore read the way every other question about an application's own config is
     *   asked: the array the file returns.
     *
     *   Read inside a buffer and with the buffers this read opened closed again, because a config
     *   file may print — nothing forbids it — and because a file that throws must be reported as a
     *   row rather than as a doctor that died. The buffers above the one this opened belong to
     *   whatever called the command and are left alone, which is the same rule the `--config-file`
     *   vet keeps for the same reason.
     *
     * The keys are {@see ConfigSchema::of()}'s three and a fourth: `error` is the reason a file could
     * not be read at all — a syntax error, a missing include — and is null when it was read, in which
     * case the other three are the reading of the array it returned.
     *
     * @return array{present: bool, declared: int|null, raw: mixed, error: string|null}
     */
    private function publishedGeneration(string $path): array
    {
        $depth = ob_get_level();

        try {
            ob_start();
            $loaded = (static fn (string $file): mixed => require $file)($path);
        } catch (Throwable $e) {
            while (ob_get_level() > $depth) {
                ob_end_clean();
            }

            return ['present' => false, 'declared' => null, 'raw' => null, 'error' => $e->getMessage()];
        }

        while (ob_get_level() > $depth) {
            ob_end_clean();
        }

        return [...ConfigSchema::of($loaded), 'error' => null];
    }

    /**
     * The gate as this process sees it, and the mismatch the boot record still holds.
     *
     * The two can disagree, and the disagreement is the point: the record is written
     * by whichever boot last saw a mismatch and is cleared by a boot that does not, so
     * a record that still stands means an installation has been in that state across
     * boots. A preflight that only reports its own verdict would call a release clean
     * over a mismatch first seen days ago — or, when the record names a different
     * connection than the one being inspected, over one it never looked at.
     *
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function pgcatGate(): array
    {
        if (!$this->laravel->bound(PgcatConfigFlipper::class)) {
            return $this->row('pgcat gate', self::FAIL, 'PgcatConfigFlipper is not bound — is the provider registered?');
        }

        try {
            $flipper = $this->laravel->make(PgcatConfigFlipper::class);
        } catch (Throwable $e) {
            return $this->row('pgcat gate', self::FAIL, 'the flipper could not be built: ' . $e->getMessage());
        }

        $recorded = $this->recordedFinding(WeightedDatabaseServiceProvider::KEY_PGCAT_GATE);
        $connection = $flipper->healthSummary()['connection'];

        // Switched on and unable to act: the driver mismatch the boot warning logs,
        // or an armed flipper missing a path a flip needs. Both fail a preflight,
        // because the setting promises something that cannot happen.
        $warning = ($flipper->isEnabled() || $flipper->isMismatched()) ? $flipper->armingWarning() : null;

        if ($warning !== null) {
            $detail = $warning;

            // When the record agrees there is a mismatch, the only thing it adds is
            // age: whether this is the state the deploy created or one it inherited.
            if ($recorded !== null) {
                $detail .= ' — ' . $this->recordClause($recorded, $connection);
            }

            // The sentence names the repair in prose — "Set swrr.pgcat.enabled = false, or
            // point database.default at the pgcat-fronted connection" — because the flip's
            // refusal and --dry-run print that same sentence and have no suggestion column.
            // The line is the half of it a report can be acted on without being read as
            // English, and the flipper names it: the row prints what the flip reads, in the
            // spelling the config file uses. Null when the fault is the other one this branch
            // covers — paths that are unset, whose values are this installation's to choose.
            return $this->row(
                'pgcat gate',
                self::FAIL,
                $detail,
                self::suggestionLines($flipper->suggestionForGate()),
            );
        }

        $detail = $flipper->isEnabled()
            ? 'armed and ready — ' . ($flipper->armedReason() ?? '')
            : 'off — ' . ($flipper->disabledReason() ?? 'disabled');

        // This boot has no mismatch, and a mismatch is still on record for the
        // installation: either the record names a connection this run does not
        // inspect, or the boot that would have closed it out never ran. A warning
        // rather than a failure, because the gate being judged here is fine — but it
        // is on record, so it is not silent either.
        if ($recorded !== null) {
            return $this->row(
                'pgcat gate',
                self::WARN,
                $detail . '; the boot record still holds a mismatch ' . $this->recordClause($recorded, $connection),
            );
        }

        return $this->row('pgcat gate', self::PASS, $detail);
    }

    /**
     * One finding a previous boot recorded and no boot has closed out, or null.
     *
     * Read from the same file the audit writes, so the doctor reports what the
     * installation has been saying about itself rather than a second opinion. Every
     * unreadable state — no record, no audit binding, a hand-edited file — is "nothing
     * recorded": the doctor falls back to its own verdict instead of failing over a
     * file it could not parse.
     *
     * The caller names the one finding it is reporting. There is no list of keys here to
     * fall back through: a row that is about one problem and quotes another's finding is
     * dating something it is not saying.
     *
     * @return array{resolution: string, context: array<string, mixed>, first_reported_at: string}|null
     */
    private function recordedFinding(string $key): ?array
    {
        return $this->recordedFindings()[$key] ?? null;
    }

    /**
     * Every finding a previous boot recorded, keyed by the finding's own name.
     *
     * The reader fallback reports several findings under several keys, and a row that
     * names all of them has to date each from its own — see `readerRow()`. Reading the
     * whole record once is what makes that possible without a lookup order to get wrong.
     *
     * @return array<string, array{resolution: string, context: array<string, mixed>, first_reported_at: string}>
     */
    private function recordedFindings(): array
    {
        try {
            return $this->laravel->make(BootAudit::class)->read()['findings'];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The prefix a row adds when a finding is still on record: empty when nothing is, so
     * a caller can append it unconditionally. The clause itself is shared with `pgcat
     * gate`, which is the row that has to name a connection.
     *
     * @param array{resolution: string, context: array<string, mixed>, first_reported_at: string}|null $recorded
     */
    private function recordSuffix(?array $recorded): string
    {
        return $recorded === null ? '' : ' — '.$this->recordClause($recorded, '');
    }

    /**
     * The recorded mismatch as one clause: when it was first reported, and on which
     * connection when that is not the connection this row is about.
     *
     * @param array{resolution: string, context: array<string, mixed>, first_reported_at: string} $finding
     */
    private function recordClause(array $finding, string $connection): string
    {
        $since = sprintf('recorded unresolved since %s', $finding['first_reported_at']);
        $seenOn = ConfigValue::string($finding['context']['connection'] ?? null, '');

        if ($seenOn === '' || $seenOn === $connection) {
            return $since;
        }

        return sprintf(
            '%s, on connection "%s" (driver "%s")',
            $since,
            $seenOn,
            ConfigValue::string($finding['context']['driver'] ?? null, 'unknown'),
        );
    }

    /**
     * The filesystem preconditions a flip cannot survive without. `pgcat gate` only
     * asks whether the flipper is switched on and whether the three paths are set;
     * this asks whether the files those paths name can actually be read and written.
     * The difference is the failure an armed, correctly-gated installation discovers
     * at the moment traffic is supposed to switch: swap() throws then, and the window
     * it was meant to open never opens.
     *
     * The problems come from `PgcatConfigFlipper::fileProblems()` — the class that owns the paths
     * — and the boot audit records the same list (for a path that is set; an empty key is this
     * row's alone), so the row and the record cannot disagree about which file this installation
     * is missing. Each problem is its own sentence, its own finding
     * key and its own repair line where the package can state one exactly, assembled by the same
     * machinery the reader rows use: a permission is a mode to paste (`chmod +r` on a source that
     * cannot be read, `chmod +w` on a file or directory a flip has to write, `+wx` on a directory a
     * rename also has to traverse) and an *empty* key whose value the published config ships is
     * that setting line, drawn from `PgcatConfigFlipper::documentedPaths()` so a repair cannot
     * drift from the default it is telling the operator to write. A path that is simply not there,
     * and an empty `readers_path`/`no_readers_path`, get no line: those values belong to the
     * installation, and a plausible-looking path pasted into configuration replaces the operator's
     * intent with this tool's guess.
     *
     * Paths come from the flipper rather than being parsed a second time here, so the doctor
     * judges exactly what a flip would touch.
     *
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function pgcatFiles(): array
    {
        if (!$this->laravel->bound(PgcatConfigFlipper::class)) {
            return $this->row('pgcat files', self::FAIL, 'PgcatConfigFlipper is not bound — is the provider registered?');
        }

        try {
            $flipper = $this->laravel->make(PgcatConfigFlipper::class);
        } catch (Throwable $e) {
            return $this->row('pgcat files', self::FAIL, 'the flipper could not be built: ' . $e->getMessage());
        }

        // Nothing reads or writes pgcat files while the flipper is inert, so there is
        // no precondition to judge and no reason to require the paths to exist.
        if (!$flipper->isEnabled()) {
            return $this->row('pgcat files', self::PASS, 'flipper is not armed — no pgcat file is read or written');
        }

        $problems = [];

        foreach ($flipper->fileProblems() as $problem) {
            $problems[] = [
                'status' => self::FAIL,
                // The finding this problem is remembered by, so each one is dated from its own
                // entry rather than from the first key the record happens to hold — and a problem
                // the boot record has nothing about (an empty key, which the audit leaves to this
                // row) prints no date at all rather than one belonging to another setting.
                'key' => WeightedDatabaseServiceProvider::PGCAT_FILE_KEYS[$problem['setting']],
                'sentence' => $problem['sentence'],
                'suggestion' => $problem['suggestion'],
            ];
        }

        if ($problems !== []) {
            return $this->datedRow('pgcat files', $problems, $this->recordedFindings());
        }

        return $this->row(
            'pgcat files',
            self::PASS,
            'a flip could run: both sources are readable and '
            . ConfigValue::string($flipper->status()['config_path'])
            . ' is writable',
        );
    }

    /**
     * Every path this package must be able to write while the flipper is armed, in one row.
     *
     * WHY A ROW OF ITS OWN
     *   `pgcat files` answers a fault: a source cannot be read, a target is missing, a directory
     *   will not take the copy. It is silent about the rest — the paths that are writable today
     *   are the ones nothing describes — so an operator cannot see what the package writes at all
     *   until one of the writes fails, and a deployment that moves its state directory has no
     *   reading that confirms the new path is the one being judged. This row is that inventory:
     *   the flip target and the directory beside it, the two variants the deployment renders, the
     *   state and lock a flip records itself in, the boot stamp the window is measured from, and
     *   the audit record and its lock. The list is `PgcatConfigFlipper::writePaths()`'s, so the
     *   doctor judges exactly the paths the class that writes them judges.
     *
     * WHAT IT SAYS, AND WHAT IT DOES NOT
     *   Each entry carries the mode and owner the path was found with, and the sentence that says
     *   what an unwritable one costs — a flip that cannot get its copy into place, a window that
     *   never closes, two boots that write one record at once. That is the fact a bare "not
     *   writable" sentence leaves out: the check already asked the filesystem, and an operator is
     *   one question — *by whom* — away from the repair. The repairs themselves stay in
     *   `pgcat files`: that row owns the `chmod` lines because it owns the per-setting findings
     *   they are dated from, and printing them twice would offer one fault two lines a gate could
     *   apply. No entry is dated for the same reason — the finding is the other row's.
     *
     *   A writable installation passes naming every path it judged, so the row states what it read
     *   in both directions rather than only when something is wrong. An inert flipper writes
     *   nothing, so the row names no path: that is `pgcat files`' line too, and a list of files a
     *   disabled feature would have touched is a reading of a world that cannot happen.
     *
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function writePaths(): array
    {
        if (!$this->laravel->bound(PgcatConfigFlipper::class)) {
            return $this->row('write paths', self::FAIL, 'PgcatConfigFlipper is not bound — is the provider registered?');
        }

        try {
            $flipper = $this->laravel->make(PgcatConfigFlipper::class);
        } catch (Throwable $e) {
            return $this->row('write paths', self::FAIL, 'the flipper could not be built: ' . $e->getMessage());
        }

        if (!$flipper->isEnabled()) {
            return $this->row('write paths', self::PASS, 'flipper is not armed — the package writes no path, so none is judged');
        }

        $paths = $flipper->writePaths();
        $blocked = 0;
        $entries = [];

        foreach ($paths as $path) {
            // Every path is named, writable or not: the reading is the inventory, and a row that
            // listed only the blocked ones would leave an operator unable to confirm that the
            // directory they just moved is the directory being judged.
            $entry = sprintf(
                '%s [%s]%s',
                $path['label'],
                $path['path'],
                $path['facts'] === null ? '' : ' ('.$path['facts'].')',
            );

            if ($path['writable']) {
                $entries[] = $entry;

                continue;
            }

            // The cost is printed only where it applies, because it is the consequence of this
            // path being unwritable rather than a description of the path.
            $entries[] = $entry.', so '.$path['cost'];
            $blocked++;
        }

        return $this->row(
            'write paths',
            $blocked === 0 ? self::PASS : self::FAIL,
            implode('; ', $entries),
        );
    }

    /**
     * The shape of what a flip would swap in — the row above judges whether a flip can *read and
     * write* the files, and this one judges whether the file it would write is one this installation
     * can serve.
     *
     * WHY A ROW OF ITS OWN
     *   Every pgcat row before this one is about an ability — the paths resolve, the files are
     *   readable, the command runs, the pooler answers — and none of them is about *content*. A
     *   variant can be readable, writable and swapped in cleanly and still be the wrong shape: the
     *   writer-only file still declaring the readers it exists to leave out, a variant with no pool
     *   for the database its own connection writes through, or a target whose pools are half the
     *   readers variant's because a template was rendered twice or a pool was commented out. Every
     *   one of those ends the same way — pgcat validates its servers when it starts, so a flip to a
     *   file like that is a pooler that does not come back — and until now the package found out by
     *   watching the pooler fail to answer, if it found out at all.
     *
     *   It is also the half a flip has never rehearsed. `db:pgcat-flip --dry-run` performs the steps
     *   that leave nothing behind and reports the ones that do not, and the file's *shape* is
     *   neither: it is a read of three files and a resolver, so both surfaces ask for it and this row
     *   is the preflight half. The reading itself is `PgcatConfigFlipper::variantShape()` — one
     *   reading, two surfaces, the same sentence in both — so a rehearsal and a release gate cannot
     *   disagree about a variant.
     *
     * WHAT IT READS, AND WHAT IT DELIBERATELY DOES NOT
     *   The three configured files, the pool names the pinned connection is written with, and the
     *   host's own resolver for the one reading about addresses. It does not ask the running pooler
     *   (that is `pool probe answer`, and that row is the one left out of a bounded run), does not write, and
     *   does not care whether the pooler is up: the question is what the file *is*, which is a fact the
     *   pooler's own state cannot change. An inert flipper is not judged — nothing swaps a variant
     *   there — which is the line `pgcat files` and `pgcat mode` draw as well.
     *
     *   The one warning is a pool whose addresses do not resolve *from here*. It is reported rather
     *   than failed because resolution is a fact about the host that asks: a build container, a
     *   runner or a release engineer's laptop is not the network the pooler will start in, and a
     *   preflight that failed on it would refuse a deploy over its own DNS. The sentence says what the
     *   boot would prune and why that matters, which is the fact an operator can act on.
     *
     * A failure here carries no `suggestion`: the repair is a pool name, a renderer or an address
     * resolver, and none of them is a value this package can state for somebody else's variant.
     *
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function variantShape(): array
    {
        $name = 'variant shape';

        if (!$this->laravel->bound(PgcatConfigFlipper::class)) {
            return $this->row($name, self::FAIL, 'PgcatConfigFlipper is not bound — is the provider registered?');
        }

        try {
            $flipper = $this->laravel->make(PgcatConfigFlipper::class);
        } catch (Throwable $e) {
            return $this->row($name, self::FAIL, 'the flipper could not be built: '.$e->getMessage());
        }

        // Moot while the flipper is inert, exactly like `pgcat files`: nothing swaps a variant, so no
        // variant's shape is anything to an installation that will not put one in front of pgcat.
        if (!$flipper->isEnabled()) {
            return $this->row($name, self::PASS, 'flipper is not armed — no variant is swapped in, so none is asked what it declares');
        }

        $shape = $flipper->variantShape();
        $failures = $shape->failures();

        if ($failures !== []) {
            return $this->row($name, self::FAIL, implode('; ', array_column($failures, 'sentence')));
        }

        $warnings = $shape->warnings();

        if ($warnings !== []) {
            return $this->row($name, self::WARN, implode('; ', array_column($warnings, 'sentence')));
        }

        // Neither a pass nor a silence beyond the two: `summary()` says which of the two states it is
        // — a shape that was held against the connection and holds, or a connection naming no pool a
        // variant declares, which is nothing to compare rather than something that agreed.
        return $this->row($name, self::PASS, $shape->summary());
    }

    /**
     * The flip's last step, judged without taking it: does the command supervisor would
     * receive resolve, and does supervisor know the program it names?
     *
     * swap() copies the variant over pgcat.toml and then runs a *shell* command line —
     * `supervisorctl signal HUP "pgcat:*"` by default. Everything before that point can
     * be perfect and the flip still fails, because this step depends on three things no
     * file check can see: the executable resolving for the user the flipper runs as, the
     * program name surviving the shell, and supervisord knowing that name. `pgcat files`
     * covers the files; this covers the command, and it is the last row that can — a
     * flip that gets this far has already replaced the file, so a failure here leaves
     * the new config in place with nothing having read it.
     *
     * The command is the flipper's own — `PgcatConfigFlipper::supervisorCommand()`, the
     * one swap() runs — so the row cannot describe a different command than the flip
     * performs. The processes this row starts are both derived from that command with
     * `status` in the verb position: no restart, no signal, no stop. The second one is
     * conditional — a program supervisor does not know is the one fault whose repair is a
     * *name*, so the row asks what supervisord is running and the sentence names the programs
     * nearest the configured one, which is what the operator has to see to repair it.
     *
     * Moot while the flipper is inert, exactly like `pgcat files`: nothing swaps a file,
     * so nothing restarts supervisor either.
     *
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function pgcatSupervisor(): array
    {
        if (!$this->laravel->bound(PgcatConfigFlipper::class)) {
            return $this->row('pgcat supervisor', self::FAIL, 'PgcatConfigFlipper is not bound — is the provider registered?');
        }

        try {
            $flipper = $this->laravel->make(PgcatConfigFlipper::class);
        } catch (Throwable $e) {
            return $this->row('pgcat supervisor', self::FAIL, 'the flipper could not be built: '.$e->getMessage());
        }

        if (!$flipper->isEnabled()) {
            return $this->row('pgcat supervisor', self::PASS, 'flipper is not armed — no supervisor command is ever run');
        }

        $command = $flipper->supervisorCommand();

        try {
            $step = $this->laravel->make(SupervisorStep::class);
        } catch (Throwable $e) {
            return $this->row('pgcat supervisor', self::FAIL, 'the supervisor step could not be built: '.$e->getMessage());
        }

        // The verdict is the flipper's own: the same call decides whether a flip may swap
        // the file, so a row that passes and a flip that refuses are impossible. The
        // sentence is shared too — the row appends what the refusal means for this report,
        // and adds nothing that could describe a different fault.
        $verdict = $step->inspect($command);

        if (!$verdict['usable']) {
            // Two of the step's faults reduce to an exact command, and the flipper names the
            // key a flip reads for each — the unquoted program name (where the repair is the
            // command the step itself built, with the name quoted) and a command left empty
            // (where it is the command the setting documents). The other faults — an executable
            // that does not resolve, a socket that is not there, a supervisor that does not know
            // the program — are repaired in a PATH, a running supervisord or a `[program:]`
            // section, so the row prints the fault and no line.
            return $this->row(
                'pgcat supervisor',
                self::FAIL,
                $verdict['detail'].' — a flip refuses before it swaps the file, so nothing is replaced and no mode is recorded',
                self::suggestionLines($flipper->suggestionForSupervisor($verdict)),
            );
        }

        // Nothing to ask supervisor about, and which of the two reasons it is decides
        // where the operator looks: a command that is not supervisorctl, or one that
        // drives supervisorctl without naming a program.
        if ($verdict['fault'] === SupervisorStep::FAULT_NOT_SUPERVISORCTL || $verdict['fault'] === SupervisorStep::FAULT_NO_PROGRAM) {
            return $this->row('pgcat supervisor', self::PASS, $verdict['detail']);
        }

        return $this->row(
            'pgcat supervisor',
            self::PASS,
            sprintf(
                '%s — a flip would run %s, and would refuse to swap the file if this answer changed',
                $verdict['detail'],
                $verdict['flip_command'],
            ),
        );
    }

    /**
     * Whether the replicas' own metadata is what the resolver reads.
     *
     * The row's question is not "does this installation look weighted" — a replica with
     * `weight => 'heavy'` looks weighted and is not — but "is the pool the read list describes".
     * So it reads the configured replicas rather than the resolved pool: the pool is what the
     * resolver kept, and a value it could not read is exactly what it dropped. A replica whose
     * metadata is unreadable is a **failure** that names it, because the alternative is what used
     * to happen — the replica left the pool and the row reported the smaller pool as the
     * installation, with the count already short and nothing to say which replica went.
     *
     * `weight: 0` is the one difference the package means: it is how a replica is disabled, so the
     * row passes, and names the replica so that "2 replicas" is never mistaken for the read list.
     * It names it in every branch rather than only the passing one, because the other two are about
     * *values*: a refusal returns before the pool is described at all, so a drain beside one is a
     * replica that is invisible until the run after the refusal is repaired, and a pool in which
     * every replica is disabled used to be reported as a fact about the pool rather than as the
     * list of replicas somebody switched off.
     *
     * Which replicas are missing and why comes from `poolExclusions()` — the resolver's own report
     * of what it did not put in the pool — rather than from this row looking at the read list and
     * working it out. The refused *values* are still classified here, because a refused core count
     * or memory figure does not remove a replica and never appears in an exclusion list; what
     * changed is that the consequence is read rather than predicted. See `docs/pool-exclusions.md`.
     *
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function replicaMetadata(WeightedDatabaseManager $manager, string $connection): array
    {
        try {
            $config = $manager->connectionConfig($connection);
            $rows = $manager->replicaStatus($connection);
        } catch (Throwable $e) {
            return $this->row('replica metadata', self::FAIL, "connection [{$connection}]: " . $e->getMessage());
        }

        $replicas = ConfigValue::assocList($config['read'] ?? []);

        if ($replicas === []) {
            return $this->row(
                'replica metadata',
                self::WARN,
                "no read replicas on [{$connection}] — every read uses the writer",
            );
        }

        // Read the *configured* replicas, not the resolved pool: a value the resolver cannot read
        // is exactly the case where the two disagree, and the pool is the half that has already
        // lost the evidence — a replica whose weight it cannot read is one it drops, so counting
        // the pool would report a smaller installation as the installation. This runs before the
        // "no metadata at all" warning, because metadata that is present and unreadable is a
        // different fault from metadata that is absent.
        //
        // The reading is `ReplicaMetadata`'s, which is also what the boot audit refuses on, so
        // this row and the refusal logged at boot cannot call the same value by two different
        // names — see docs/replica-metadata-refusal.md.
        // What the pool does not hold, from the resolver: `replicaStatus()` above is the pool,
        // `poolExclusions()` is everything missing from it, and the two partition the read list.
        // So the clause below states what happened to a refused weight rather than predicting it —
        // a replica the resolver dropped is one this row cannot mis-describe as still in play, and
        // a replica it kept is one this row cannot blame for a pool that shrank.
        $excluded = $manager->poolExclusions($connection);

        $dropped = [];
        $disabled = [];

        foreach ($excluded as $exclusion) {
            if ($exclusion['reason'] === WeightResolver::EXCLUDED_DISABLED) {
                $disabled[] = $exclusion['key'];

                continue;
            }

            if ($exclusion['reason'] === WeightResolver::EXCLUDED_REFUSED) {
                $dropped[] = $exclusion['key'];
            }
        }

        $problems = [];

        foreach ($replicas as $replica) {
            $named = $manager->replicaKey($replica);

            foreach (ReplicaMetadata::refusals($replica) as $refusal) {
                $problems[] = '['.$named.'] '.$refusal['sentence'];
            }
        }

        $drops = $dropped !== [];

        // Every replica the resolver left out of the pool on purpose, in the classifier's words,
        // named wherever this row has a verdict to give rather than only where the pool is healthy.
        // A drain is not a value the resolver refused: it is a replica that is not answering reads,
        // and the branches below are about values — so a refusal used to hide a drain until the run
        // after the refusal was repaired, and a pool with nothing left in it named nobody at all.
        // One spelling of the clause, because three branches state it.
        $drained = $disabled === [] ? '' : implode(', ', $disabled).' — '.ReplicaMetadata::DISABLED;

        if ($problems !== []) {
            return $this->row(
                'replica metadata',
                self::FAIL,
                sprintf(
                    'replica metadata the resolver cannot read on [%s]: %s — %s.%s',
                    $connection,
                    implode('; ', $problems),
                    $drops
                        ? 'a replica weighted 0 leaves the pool, so reads are routed over a smaller pool than the read list describes and nothing else reports that it happened'
                        : 'the replica stays in the pool, weighted as something other than what the read list describes',
                    $drained === '' ? '' : ' Also drained on purpose: '.$drained.'.',
                ),
            );
        }

        if (!$this->hasWeightMetadata($replicas)) {
            return $this->row(
                'replica metadata',
                self::WARN,
                sprintf(
                    'the %d replicas on [%s] carry no weight metadata (weight/cpu_cores/ram_gb) — '
                    .'the resolver falls back to random picks',
                    count($replicas),
                    $connection,
                ),
            );
        }

        $total = (int) array_sum(array_column($rows, 'weight'));

        // Nothing left in the pool, and nothing the resolver refused — every exclusion that got
        // here is a disable, because a refused weight is reported above. The sentence named no
        // replica, and "every replica is drained" is a fact an operator cannot act on without
        // knowing which replicas they switched off.
        if ($total <= 0) {
            return $this->row(
                'replica metadata',
                self::FAIL,
                sprintf(
                    'every replica on [%s] resolves to weight 0 — reads cannot be routed%s',
                    $connection,
                    $drained === '' ? '' : ': '.$drained,
                ),
            );
        }

        // `weight: 0` is the documented way to disable a replica, so one that is missing from the
        // pool is a decision rather than a fault — but the pool is then smaller than the read
        // list, and naming the replica that left is the difference between a count an operator can
        // trust and one that quietly shrank. The names are the resolver's, so this row cannot name
        // a replica the pool actually holds.
        if ($disabled !== []) {
            return $this->row(
                'replica metadata',
                self::PASS,
                sprintf(
                    'the pool on [%s] holds %d of the %d configured replicas (total weight %d): %s',
                    $connection,
                    count($rows),
                    count($replicas),
                    $total,
                    $drained,
                ),
            );
        }

        return $this->row(
            'replica metadata',
            self::PASS,
            sprintf('%d replicas on [%s], total weight %d', count($rows), $connection, $total),
        );
    }

    /**
     * The read path's three readings: what the connection's endpoints are capped at, whether the
     * standbys among them keep `hot_standby_feedback` on, and what a statement pinned to the writer
     * reaches.
     *
     * WHY THESE ROWS EXIST
     *   This package chooses the endpoint a read lands on, and two of the things that endpoint can do
     *   to the read are its own settings: it can cut a statement at a `statement_timeout` the writer
     *   was configured not to cut at, and a standby that does not keep `hot_standby_feedback` on can
     *   have a read cancelled by a conflict with recovery at any moment. Neither is a value in any
     *   file this package reads — both live in a parameter group — so a preflight that judged only
     *   the configuration described a read path it had never asked about. `docs/read-path-cancellations.md`
     *   is the recording: the two SQLSTATEs, why a re-run is the conflict's answer and the writer's
     *   longer cap is the timeout's, where the package stops (it chooses the endpoint and does not
     *   re-run a statement the application may have wrapped in a transaction), and the log-alarm
     *   filter names an installation can install for itself.
     *
     * ONE SWEEP, TWO ROWS, AND ONE READING THAT ASKS NOTHING
     *   {@see EndpointReadings} is built once and answers all three, because the first two are read
     *   off one connection per endpoint and three rows each opening their own connection would be
     *   three sweeps of the same read list. The third — the `read`/`write` pair — reads the
     *   connection's config, so it is answered whatever the switch says, and it is deliberately a
     *   reading rather than a verdict: a single-host connection is a documented installation and
     *   whether an application pins reads to the writer is that application's own setting, which this
     *   package cannot read.
     *
     * ARMED BY ITS OWN SWITCH, AND WHY IT DEFAULTS OFF
     *   The two probing rows open one connection per endpoint — the writer included — so they are
     *   behind `db-manager.swrr.read_path.enabled`, read as a switch and off in the shipped config.
     *   An installation opts into that, which is the same rule the pool probe follows for a socket on
     *   the poll path, and the rows say the probe is not armed rather than reporting silence as if the
     *   endpoints had been asked: "nothing to report" and "not asked" must not be the same sentence.
     *   A value that is not a switch is refused — with the spellings, the way every other switch row
     *   refuses one — and resolves to the documented default, so a typo cannot arm a connection per
     *   endpoint; no suggestion is printed for it, because a value that is not a switch cannot be
     *   re-spelled without guessing at what was meant.
     *
     * WHAT AN ENDPOINT THAT DID NOT ANSWER IS
     *   A reading, not a failure. An endpoint that refused, timed out or answered without the
     *   settings is named with what it said, beside the endpoints that did answer, and the row stays
     *   `PASS`: reachability is the health monitor's question and `store reachability`'s, and a second
     *   row failing over the same moment would be one fact reported twice in two vocabularies.
     *
     * `--connection-only` leaves the two probing rows out entirely (see {@see REACHES_OUT}): a build
     * container has no endpoint to ask, and a row that judged one would fail an image over the
     * network the image was always going to have at run time. The pair reading is not left out — it
     * opens nothing.
     *
     * @return list<array{status: string, name: string, detail: string, suggestions: list<string>}>
     */
    private function readPathRows(WeightedDatabaseManager $manager, string $connection): array
    {
        $config = $this->laravel->make(Repository::class);

        // The documented default is off: the reading is one connection per endpoint, and an
        // installation opts into that rather than discovering it in a preflight. Read through
        // `SwitchValue` for the reason every other switch is — `(bool) 'false'` is true, and this
        // setting decides whether sockets are opened.
        $switch = SwitchValue::read($config->get('db-manager.swrr.read_path.enabled'), false);
        $timeout = ConfigValue::int($config->get('db-manager.swrr.read_path.timeout_ms'), EndpointReadings::DEFAULT_TIMEOUT_MS);

        $readings = EndpointReadings::of($manager, $connection, $switch['on'], $timeout);

        // One spelling of the refusal, on the rows it decides. The pair reading reads a config and
        // is asked either way, so a refusal does not change what it says and the clause would be a
        // sentence about a setting that did not decide anything here.
        $refused = $switch['refused'] === null ? '' : ' — '.SwitchValue::describeRefused([
            'db-manager.swrr.read_path.enabled' => $switch['refused'],
        ]).' — '.SwitchValue::ACCEPTED.'. Refused: neither row asks an endpoint, which is the value this '
            .'setting documents, so a typo here cannot open a connection per endpoint.';

        $rows = [];

        if ($this->asks('read caps')) {
            $rows[] = $this->readingRow('read caps', $readings->caps(), $refused);
        }

        if ($this->asks('standby feedback')) {
            $rows[] = $this->readingRow('standby feedback', $readings->feedback(), $refused);
        }

        $rows[] = $this->readingRow('read/write pair', $readings->pair(), '');

        return $rows;
    }

    /**
     * A reading as a row: the level it reached decides the verdict, and the sentence is the reading's
     * own — so the row and `/health/db` cannot describe one endpoint two ways.
     *
     * The mapping is the whole of what this command adds to a reading, which is why a reading is not
     * a row: what is *wrong* is a judgement that belongs where the evidence is (a cap far below the
     * writer's, a standby without the feedback setting), and what a verdict is called is this
     * command's vocabulary (`PASS`/`WARN`/`FAIL`, and `--strict` failing the warnings).
     *
     * @param array{level: string, sentence: string} $reading
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function readingRow(string $name, array $reading, string $suffix): array
    {
        return $this->row(
            $name,
            $reading['level'] === EndpointReadings::LEVEL_WARN ? self::WARN : self::PASS,
            $reading['sentence'].$suffix,
        );
    }

    /**
     * The three on/off settings, read as the values they were written as rather than cast.
     *
     * A switch is the one setting a cast cannot be trusted with. `ConfigValue::bool()` narrows
     * with PHP's rules and its docblock says what that means — `(bool) 'false'` is true — so
     * `swrr.pgcat.enabled`, `swrr.pgcat.use_reload` and `swrr.allow_local_fallback` all used to
     * read `'false'`, `'off'` and `'no'` as *on*, and `'maybe'` as on too, without a word. On
     * the pgcat switch that is not a cosmetic mistake: it arms a file swap the operator asked
     * for the opposite of. Every value that is not on or off is now refused — here and at boot,
     * at error level — and the setting falls back to the value its own documentation prints.
     *
     * The three are one row because they are one mistake repeated. A row each would make a run
     * of them look like three unrelated faults, and the fix is the same gesture in all three
     * cases. Each problem still carries its own finding key, so each is dated from the boot that
     * reported *it*: the record holds one entry per key, and one date on all three would claim
     * the installation started being wrong about all of them at once.
     *
     * The pgcat pair is read from the flipper, exactly as `pgcat gate` reads it, and the third
     * from the provider's own reader. That is the whole point of the split: the flip and this
     * row have to agree that `'off'` is off, so neither classifies a switch the other acts on.
     * Nothing is guessed: a value that is not a switch is reported and left refused, so no
     * suggestion line is printed — re-spelling `'flase'` would be inventing the operator's
     * intent, which is the mistake the refusal exists to prevent.
     *
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function switchValues(): array
    {
        $swrr = ConfigValue::assoc($this->laravel->make(Repository::class)->get('db-manager.swrr'));

        try {
            $flipper = $this->laravel->make(PgcatConfigFlipper::class);
        } catch (Throwable $e) {
            return $this->row(
                'switch values',
                self::FAIL,
                'the flipper could not be built, so the pgcat switches are not inspected: '.$e->getMessage(),
            );
        }

        $recorded = $this->recordedFindings();
        $status = $flipper->status();
        $fallback = WeightedDatabaseServiceProvider::allowLocalFallback($swrr['allow_local_fallback'] ?? null);

        $refused = $flipper->refusedSwitches();

        if ($fallback['refused'] !== null) {
            $refused['swrr.allow_local_fallback'] = $fallback['refused'];
        }

        $problems = self::switchProblems($refused);

        if ($problems === []) {
            return $this->row('switch values', self::PASS, self::switchSentence([
                'enabled' => $status['configured_enabled'],
                'use_reload' => $status['use_reload'],
                'fallback' => $fallback['on'],
            ]));
        }

        return $this->datedRow('switch values', $problems, $recorded);
    }

    /**
     * The problems a set of refused switches makes: one per setting, in the order the table
     * lists them, each in the sentence the boot audit and this command share.
     *
     * Extracted from the row so the *file* vet can make the same claim about the same rule
     * without duplicating it. Which settings there are, what each is called and what a refusal
     * leaves behind is one table (`SWITCH_KEYS`, `SWITCH_CONSEQUENCES`); a value refused on the
     * way to a deploy and a value refused at boot are the same value, and would otherwise be
     * two descriptions of one refusal with nothing checking that they agree.
     *
     * Nothing here dates a problem or assembles a row: the installation row has a boot record
     * to date findings from and the file vet does not, so that difference stays where it is —
     * in the caller that has a record.
     *
     * @param array<string, string> $refused setting => the value as written
     * @return list<array{status: string, key: string|null, sentence: string, suggestion: string|null}>
     */
    private static function switchProblems(array $refused): array
    {
        $problems = [];

        foreach (self::SWITCH_KEYS as $setting => $key) {
            if (!isset($refused[$setting])) {
                continue;
            }

            $problems[] = [
                'status' => self::FAIL,
                'key' => $key,
                'sentence' => SwitchValue::describeRefused([$setting => $refused[$setting]])
                    .' — '.SwitchValue::ACCEPTED.'. Refused: '.self::SWITCH_CONSEQUENCES[$setting].'.',
                // Deliberately none: a value that is not a switch cannot be re-spelled without
                // guessing at what was meant, and a guess pasted in is a decision made by the
                // report instead of by the operator.
                'suggestion' => null,
            ];
        }

        return $problems;
    }

    /**
     * A reader setting the package will not read at all, and what is left of the other one.
     *
     * The two refusals `reader windows` reports, extracted so the config-file vet can report the
     * same two about a file — same sentences, same shapes, same suggestions — while the row goes
     * on to the resolver's warnings about values that *can* be read. That split is the whole
     * reason this is a method: a refusal is a fact about the value alone (`ReaderWindows` and
     * `ReaderDays` decide it and know nothing else), while "read, but can never act" needs the
     * resolver built over the value — and a vet that must not touch the running package has the
     * first and not the second.
     *
     * The usable windows and days come back with the problems because the caller that reports
     * one setting's refusal has to say what the refusal leaves behind, and both sentences are
     * written from both lists: a refused window list beside usable days still applies on those
     * days, and a refused day list beside no windows cannot put a read anywhere.
     *
     * `days_refused` is false for a day list that was never configured: the `[1, 2, 3, 4, 5]`
     * default is the package's own, and only a value somebody wrote can be their mistake.
     *
     * @param mixed $configuredWindows the raw `swrr.reader_windows` value
     * @param mixed $configuredDays the raw `swrr.reader_days` value, null when it is not set
     * @return array{
     *     problems: list<array{status: string, key: string|null, sentence: string, suggestion: string|null}>,
     *     windows: list<array{start?: string, end?: string}>,
     *     days: list<int>,
     *     windows_refused: bool,
     *     days_refused: bool,
     * }
     */
    private static function readerRefusals(mixed $configuredWindows, mixed $configuredDays): array
    {
        $split = ReaderWindows::split($configuredWindows);
        $splitDays = ReaderDays::split($configuredDays ?? [1, 2, 3, 4, 5]);

        $windows = $split['usable'];
        $days = $splitDays['usable'];

        $windowsRefused = $split['shape'] !== null || $split['rejected'] !== [];
        $daysRefused = $configuredDays !== null && ($splitDays['shape'] !== null || $splitDays['rejected'] !== []);

        /** @var list<array{status: string, key: string|null, sentence: string, suggestion: string|null}> $problems */
        $problems = [];

        // A value the package will not read as windows. Refusing it is the point: the flat
        // string '10:00-14:20' is how a person naturally writes a window, so interpreting it
        // (or dropping it) turns a typo into the opposite behaviour without a word. This one is
        // a failure rather than a warning — the value is rejected, not merely unable to act —
        // and it names the entry and the shape the setting reads.
        if ($windowsRefused) {
            $offending = $split['shape'] !== null
                ? 'swrr.reader_windows is '.$split['shape'].', not a list of windows'
                : ReaderWindows::describeRejected($split['rejected']);

            $consequence = $windows === []
                ? 'nothing is left to apply, so reads use the replica pool at every hour'
                : sprintf('%d well-formed window(s) still apply', count($windows));

            $suggestion = ReaderWindows::suggestion($configuredWindows);

            $problems[] = [
                'status' => self::FAIL,
                'key' => WeightedDatabaseServiceProvider::KEY_READER_WINDOWS_REFUSED,
                'sentence' => $offending.' — '.ReaderWindows::ACCEPTED.'. Refused: '.$consequence.'.',
                // The repair, when the refused value re-spells into one. The sentence above
                // already names what is wrong; this is the half an operator can paste.
                'suggestion' => $suggestion === null ? null : 'swrr.reader_windows = '.$suggestion,
            ];
        }

        // The days setting, one key over and the same rule. A list written as a string is the
        // `.env` spelling of a list and the one thing this setting cannot read, and dropping it
        // silently is worse than refusing it: the resolver is permissive with no day left, so a
        // restrictive day list reads as its opposite. Only a configured value is refused — the
        // `[1, 2, 3, 4, 5]` default is the package's own.
        if ($daysRefused) {
            $offending = $splitDays['shape'] !== null
                ? 'swrr.reader_days is '.$splitDays['shape'].', not a list of days'
                : ReaderDays::describeRejected($splitDays['rejected']);

            // The same order the audit applies, because the two share a setting: nothing
            // usable left is the permissive case whatever the windows say, then the days that
            // survive, then the two ways there is no window to go with them — refused (named
            // above, in this row) or never configured.
            $consequence = match (true) {
                $days === [] => 'nothing is left to apply, so reads use the replica pool on every day',
                $windows !== [] => sprintf('%d well-formed day(s) still apply', count($days)),
                $windowsRefused => 'no window can be used either — see the reader_windows problem above — so neither list can put a read on the replica pool',
                default => 'no reader_windows are configured, so the fallback is off whatever the day list says',
            };

            $suggestion = ReaderDays::suggestion($configuredDays);

            $problems[] = [
                'status' => self::FAIL,
                'key' => WeightedDatabaseServiceProvider::KEY_READER_DAYS_REFUSED,
                'sentence' => $offending.' — '.ReaderDays::ACCEPTED.'. Refused: '.$consequence.'.',
                // `'1,2,3'` is the one spelling that reduces to an exact replacement; an entry
                // that is not a day number and not a list of them gets no line, because the
                // package does not guess at what a name meant.
                'suggestion' => $suggestion === null ? null : 'swrr.reader_days = '.$suggestion,
            ];
        }

        return [
            'problems' => $problems,
            'windows' => $windows,
            'days' => $days,
            'windows_refused' => $windowsRefused,
            'days_refused' => $daysRefused,
        ];
    }

    /**
     * A row's repair lines: the ones it could name, in the order it built them, with the
     * ones it could not left out entirely.
     *
     * Written once because three rows now hand over a repair that may or may not exist, and
     * the difference between "this row has no repair" and "this row's list is empty" is not
     * one a caller should have to spell: the JSON contract's `suggestions` is a list either
     * way, and a row that passed `null` where the contract wants `[]` would break a job that
     * counted on `[]`.
     *
     * @return list<string>
     */
    private static function suggestionLines(?string ...$lines): array
    {
        return array_values(array_filter($lines, static fn (?string $line): bool => $line !== null));
    }

    /**
     * A resolved switch as the word the row prints for it, so the pass line writes `on` and
     * `off` the way the sentences that refuse them spell the same two values.
     */
    private static function switchWord(bool $on): string
    {
        return $on ? 'on' : 'off';
    }

    /**
     * `swrr.reader_windows` as the resolver reads it, and as a preflight should.
     *
     * The accepted shape is one array per window. A flat `'10:00-14:20'` is the way a
     * person naturally writes a window, and it is not one: read as "no window at all",
     * it leaves the resolver in its permissive mode, so reads use the replica pool at
     * every hour — the opposite of the fallback the setting describes. That is input the
     * package refuses rather than interprets, so a preflight fails on it instead of
     * leaving the mistake to be discovered from read routing.
     *
     * The other three ways the setting can fail to act are warnings, matching the boot
     * audit: an unusable day list (permissive), days outside 1…7 (the pool is never
     * used), and windows whose start is not before their end (never entered).
     *
     * Every judgement comes from the code that acts on the value — `ReaderWindows` for
     * the shape, `ReaderDays` for the days, `TimeWindowResolver` for the mode and the
     * windows that can never be entered — so the row cannot disagree with routing.
     *
     * It names every problem it finds, in the order the audit reports them, rather than
     * stopping at the first: the audit already says all of them, and an operator who fixes
     * the windows only to hear about the days on the next preflight has paid a second
     * deploy for a sentence that would have fitted on this run. The verdict is the loudest
     * problem in the row — a refusal beside a warning is still a refusal — and a refused
     * value that names its own replacement contributes a `suggestion` line, so two refused
     * settings print two repairs.
     *
     * Each problem is dated from *its own* finding. The record holds one entry per finding
     * key and the reader fallback has several, so quoting the first key the record happens
     * to hold would date a problem the row is not reporting — a claim about when this
     * installation started being wrong, made about the wrong thing.
     *
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function readerWindows(): array
    {
        $swrr = ConfigValue::assoc($this->laravel->make(Repository::class)->get('db-manager.swrr'));
        $configured = $swrr['reader_windows'] ?? null;
        $configuredDays = $swrr['reader_days'] ?? null;

        $refusals = self::readerRefusals($configured, $configuredDays);
        $recorded = $this->recordedFindings();

        /** @var list<array{status: string, key: string|null, sentence: string, suggestion: string|null}> $problems */
        $problems = $refusals['problems'];

        $windows = $refusals['windows'];
        $days = $refusals['days'];

        $windowsRefused = $refusals['windows_refused'];
        $daysRefused = $refusals['days_refused'];

        try {
            $resolver = $this->laravel->make(TimeWindowResolver::class);
        } catch (Throwable $e) {
            $problems[] = [
                'status' => self::FAIL,
                'key' => null,
                'sentence' => 'the resolver could not be built: '.$e->getMessage().'.',
                'suggestion' => null,
            ];

            return $this->readerRow($problems, $recorded);
        }

        // Permissive mode. The documented opt-out — no windows at all — is this state reached
        // on purpose, so it passes with the reason stated, whatever reader_days says: with no
        // window to enter, a day list cannot stop a read. Nothing usable left after a refusal is
        // the same state, and the refusal above has already said as much.
        if ($windows === []) {
            if ($problems === []) {
                $problems[] = [
                    'status' => self::PASS,
                    'key' => null,
                    'sentence' => 'no reader_windows set — every read uses the replica pool, the documented opt-out.',
                    'suggestion' => null,
                ];
            }

            return $this->readerRow($problems, $recorded);
        }

        // The day list holds no usable day, so permissive mode answers every read before the
        // days or the windows are consulted — the other way to reach "always readers". A day
        // list whose entries were unusable arrives here as well and is refused above; that
        // refusal has already said what it leaves behind, so this sentence is not added beside
        // it.
        if ($configuredDays !== null && $days === []) {
            if (!$daysRefused) {
                $problems[] = [
                    'status' => self::WARN,
                    'key' => WeightedDatabaseServiceProvider::KEY_READER_ALWAYS_READERS,
                    'sentence' => 'swrr.reader_days holds no usable day — a day is an ISO-8601 number, '
                        .'1 = Monday … 7 = Sunday — so the fallback cannot apply: the resolver is '
                        .'permissive and reads use the replica pool on every day, at every hour.',
                    'suggestion' => null,
                ];
            }

            // Nothing below applies: permissive mode means the resolver never reaches a window,
            // so a window that can never be entered is not a fact about how reads are routed —
            // it is a fact about a window nothing consults.
            return $this->readerRow($problems, $recorded);
        }

        // Days outside ISO-8601 can never be a reader day, and a window whose start is not
        // before its end can never be entered. One finding in the audit, under one key, so one
        // sentence here: the pool is never used, and whichever of the two causes hold are named
        // in it.
        $noReaderDay = ReaderDays::inIsoRange($days) === [];

        $unreachable = $resolver->unreachableWindows();

        $described = implode(', ', array_map(
            static fn (int $index): string => 'window '.($index + 1),
            $unreachable,
        ));

        $neverEntered = $unreachable !== [] && count($unreachable) === count($windows);

        if ($noReaderDay || $neverEntered) {
            $problems[] = [
                'status' => self::WARN,
                'key' => WeightedDatabaseServiceProvider::KEY_READER_ALWAYS_WRITER,
                'sentence' => match (true) {
                    $noReaderDay && $neverEntered => sprintf(
                        'swrr.reader_days has no day in 1…7 (read as %s) and every window (%s) has a start that is not before its end, so no day is ever a reader day and none can be entered: the replica pool is never used.',
                        implode(', ', $days),
                        $described,
                    ),
                    $noReaderDay => sprintf(
                        'no day in 1…7 (read as %s), so no day is ever a reader day and the replica pool is never used.',
                        implode(', ', $days),
                    ),
                    default => sprintf(
                        'every window (%s) has a start that is not before its end, so none can be entered and the replica pool is never used.',
                        $described,
                    ),
                },
                'suggestion' => null,
            ];
        } elseif ($unreachable !== []) {
            $problems[] = [
                'status' => self::WARN,
                'key' => WeightedDatabaseServiceProvider::KEY_READER_UNMATCHABLE_WINDOWS,
                'sentence' => sprintf(
                    '%s has a start that is not before its end, so %s can never be entered and never falls back to the writer.',
                    $described,
                    count($unreachable) === 1 ? 'it' : 'they',
                ),
                'suggestion' => null,
            ];
        }

        // Everything above is about a fallback that cannot apply or cannot be entered; with none
        // of it, the setting is doing what it says and the row says so.
        if ($problems === []) {
            $problems[] = [
                'status' => self::PASS,
                'key' => null,
                'sentence' => sprintf(
                    '%d window(s) on %s reader day(s) — reads fall back to the writer outside them.',
                    count($windows),
                    count(ReaderDays::inIsoRange($days)),
                ),
                'suggestion' => null,
            ];
        }

        return $this->readerRow($problems, $recorded);
    }

    /**
     * One row out of every problem the reader fallback has.
     *
     * @param list<array{status: string, key: string|null, sentence: string, suggestion: string|null}> $problems
     * @param array<string, array{resolution: string, context: array<string, mixed>, first_reported_at: string}> $recorded
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function readerRow(array $problems, array $recorded): array
    {
        return $this->datedRow('reader windows', $problems, $recorded);
    }

    /**
     * Where the pooler is against the windows, at the moment of the run — the one question the boot
     * window cannot answer, because a task that boots outside a reader window converges to the
     * writer-only config and looks perfect until a window opens and nothing moves pgcat with it.
     *
     * Read through the flipper rather than from a second parse of the block: `appliedMode()` reads
     * pgcat's *file*, so a run that never got as far as recording a mode still shows here — that
     * shape, a `last_mode`-based check answering "unknown" while the pooler is provably writer-only,
     * is the incident this row exists for.
     *
     * Both directions fail, and the sentence leads with which one the run found, because the
     * operator's next question — did the flip at the window's start not land, or the one at its
     * end? — is answered by the words rather than by reading the flipper's sentence for its
     * opening clause. An armed flipper whose file cannot be read is *not judged* rather than
     * passed, and `pgcat files` judges the file itself; an inert one has no file to be wrong about,
     * which is the conclusion `flip schedule` reaches for its own subject.
     *
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function pgcatMode(): array
    {
        $name = 'pgcat mode';

        if (!$this->laravel->bound(PgcatConfigFlipper::class)) {
            return $this->row($name, self::FAIL, 'PgcatConfigFlipper is not bound — is the provider registered?');
        }

        try {
            $flipper = $this->laravel->make(PgcatConfigFlipper::class);
        } catch (Throwable $e) {
            return $this->row(
                $name,
                self::FAIL,
                'the flipper could not be built, so pgcat\'s configuration cannot be compared with the reader windows: '.$e->getMessage(),
            );
        }

        // An inert flipper has no file of its own to be on the wrong variant of, so there is
        // nothing to compare and nothing to disagree about — the same line `pgcat files` and
        // `flip schedule` draw for themselves.
        if (!$flipper->isEnabled()) {
            return $this->row($name, self::PASS, 'flipper is not armed — nothing compares pgcat\'s configuration with the reader windows, so the two cannot disagree');
        }

        $verdict = $flipper->readerWindowVerdict();
        $expected = $verdict['expected'];
        $applied = $verdict['applied'];

        // Not judged rather than passed: permissive mode has no window to be inside of, and a file
        // that could not be read (or holds neither variant) is `pgcat files`' question. The reading
        // itself is still reported by `--status`, which is where a reader goes to see it.
        if (!is_string($applied)) {
            return $this->row($name, self::PASS, 'nothing was compared: no reader window is configured, or pgcat\'s configuration could not be read');
        }

        if ($verdict['failed'] !== true) {
            return $this->row($name, self::PASS, $expected === 'readers'
                ? 'in step — a reader window is open and pgcat is on the readers config'
                : 'in step — no reader window is open and pgcat is on the writer-only config');
        }

        // The flipper's own sentence is printed rather than a second copy of it: it carries the file
        // in place, why the reads are going the wrong way, and the command that moves the file — and
        // it is the same sentence `/health/db` reports and logs, so the row, the endpoint and the log
        // cannot drift apart.
        return $this->row($name, self::FAIL, sprintf(
            '%s — %s',
            $verdict['direction'] === 'outside_window'
                ? 'outside the reader window'
                : 'inside the reader window',
            is_string($verdict['reason']) && $verdict['reason'] !== ''
                ? $verdict['reason']
                : 'pgcat is on a configuration the reader windows are not asking for',
        ));
    }

    /**
     * Whether anything can ask the running pooler about its pools at all.
     *
     * WHY THIS IS A ROW OF ITS OWN
     *   This used to be the first branch of the answer's row, and the two questions have different
     *   costs and different readers: arming is a configuration state — the switch, and a dialable
     *   `[general] port` in the file the pooler runs on — that costs one file read, while the answer
     *   is a socket to the pooler that a build container has no route to. One row that opens the
     *   socket to find out whether it was armed could only ever be asked where the pooler is
     *   reachable, so `--connection-only` had to leave the whole question out; split, the arming
     *   half is asked in every mode and the preflight can say "the pooler is taken at supervisord's
     *   word" on an image build.
     *
     *   It reads the flipper's own declaration — `PgcatConfigFlipper::poolProbeArming()`, over the
     *   same variant `poolProbeVerdict()` uses — and asks nothing, so a probe that is off costs a
     *   setting and a file rather than a connect timeout. Every state that is not a missing
     *   prerequisite is a pass: an inert flipper, a probe switched off, and a variant that declares
     *   no pool all mean there is nothing to ask, and none of them is a fault. The row that reports a
     *   fault is `pool probe answer`.
     *
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function poolProbeArmed(): array
    {
        $name = 'pool probe armed';

        if (!$this->laravel->bound(PgcatConfigFlipper::class)) {
            return $this->row($name, self::FAIL, 'PgcatConfigFlipper is not bound — is the provider registered?');
        }

        try {
            $flipper = $this->laravel->make(PgcatConfigFlipper::class);
        } catch (Throwable $e) {
            return $this->row(
                $name,
                self::FAIL,
                'the flipper could not be built, so nothing can say whether a probe is armed: '.$e->getMessage(),
            );
        }

        // The line `pgcat mode` and `flip schedule` draw: an inert flipper has no pooler to ask
        // about, because nothing swaps the file and nothing restarts the program.
        if (!$flipper->isEnabled()) {
            return $this->row($name, self::PASS, 'flipper is not armed — nothing asks the running pooler about its pools');
        }

        $arming = $flipper->poolProbeArming();

        if (!$arming['armed']) {
            return $this->row($name, self::PASS, 'the probe is not armed (swrr.pgcat.pool_probe.enabled, and a dialable `[general] port` in the file the pooler runs on) — the running pooler is taken at supervisord\'s word, which cannot tell a pool it is not serving from one it is');
        }

        if (!$arming['applicable']) {
            return $this->row($name, self::PASS, 'no pool is declared in the configuration, so there is nothing to ask the running pooler about');
        }

        // Armed and with something to ask: the pools are named here rather than in the answer's row,
        // so an operator reading only the preflight can see what the pooler will be asked for.
        return $this->row($name, self::PASS, sprintf(
            'armed — the probe asks the running pooler for the pool(s) the configuration declares (%s), and the row beside this one reports what it said',
            implode(', ', $arming['pools']),
        ));
    }

    /**
     * Whether the running pooler is answerable for the pools the configuration declares.
     *
     * WHY THIS ROW EXISTS
     *   `pgcat supervisor` asks whether the program is RUNNING, and a program can be RUNNING and
     *   still be the wrong thing: a pooler holding a configuration with no pool for a database the
     *   application is connected to answers every read to it with `FATAL: No pool configured for
     *   database: …` while `supervisorctl status` reports a healthy program. supervisord cannot see
     *   the difference — a program that is up is up, whatever it is serving — and this row is the
     *   only preflight that asks the pooler itself. It is the read-only half of the self-heal the
     *   per-minute flip performs, over the same probe and the same vocabulary, so the row and the
     *   repair cannot disagree about what the pooler holds.
     *
     *   It is a real exchange, on the pooler's own address and bounded by `pool_probe.timeout_ms`,
     *   and the first thing it asks is the pooler's own inventory: `SHOW POOLS` on pgcat's admin
     *   console, with the `[general] admin_username` / `admin_password` of the file the process is
     *   running on. That list is the process stating which pools it serves, so a declared pool it
     *   does not carry is the fault this row exists for — and the reading rests on that list rather
     *   than on how the pooler answers an anonymous startup packet, which is a property of the
     *   pooler's ordering rather than of its pools. Only a console that cannot be read — no
     *   credentials in that file, a refusal, a SCRAM challenge, silence — falls back to one
     *   PostgreSQL v3 startup packet per declared pool, where a refusal is the pooler's own
     *   sentence and a control name no configuration declares says whether those answers are proof.
     *   Nothing is written, swapped or restarted, so the row is safe in a deploy pipeline. An
     *   installation that has not armed the probe pays nothing: the row reports that the pooler is
     *   taken at supervisord's word and returns without opening a socket.
     *
     *   Three answers are told apart, and they are the pooler's rather than this package's. A pool
     *   that exists answers — and a pool that exists behind a database that is down still answers,
     *   because the question is what the pooler *has*, not what it can reach. A pool the pooler
     *   refuses with its own "No pool configured for database" sentence is the fault the flip
     *   repairs, and this row **fails** on it. A pooler that answered nothing at all — refused, timed
     *   out, or said nothing — is **warned** about rather than failed: a probe pointed at the wrong
     *   address must not be able to fail a healthy installation, and the same line is what keeps a
     *   misconfigured probe from restarting a healthy pooler every minute.
     *
     *   Every row that asked something names the endpoint the packets went to, `tcp://host:port`,
     *   read off the probe's own answer rather than off `swrr.pgcat.pool_probe.*`. A probe aimed at
     *   the wrong port reports the same silence as a pooler that is down, so the pool names alone
     *   leave an operator nothing to check; the address is what lets that installation diagnose
     *   itself, and it is the one fact both this row and `/health/db`'s `pool_probe` block carry.
     *
     * WHY IT IS NOT JUDGED WHILE THE BOOT WINDOW IS OPEN
     *   A container is allowed to converge, and the repair that re-applies the configuration runs
     *   inside that window: `applyCurrentState()` re-applies the variant and restarts the pooler for
     *   a pool that is missing, and it stops at the end of the window. So the answers are always
     *   reported and the verdict waits — the same line `pgcat mode` draws for a file that a flip has
     *   not landed yet, and for the same reason. Past the window the verdict stands and nothing will     * re-apply the file again, which is why a failure here is worth blocking a deploy on.
     *
     * WHY IT IS THE SECOND OF TWO ROWS
     *   The arming question — is anything able to ask at all — used to be this row's first branch,
     *   and it is a setting and a file where this is a socket. `pool probe armed` answers it in
     *   every mode, so a build container can say the pooler is taken at supervisord's word; this
     *   row is one of the six `--connection-only` leaves out, because it opens the connection. When
     *   the other row found nothing to ask, this one says `not asked` and passes rather than going
     *   missing from a report a gate selects it by name in.
     *
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function poolProbeAnswer(): array
    {
        $name = 'pool probe answer';

        if (!$this->laravel->bound(PgcatConfigFlipper::class)) {
            return $this->row($name, self::FAIL, 'PgcatConfigFlipper is not bound — is the provider registered?');
        }

        try {
            $flipper = $this->laravel->make(PgcatConfigFlipper::class);
        } catch (Throwable $e) {
            return $this->row(
                $name,
                self::FAIL,
                'the flipper could not be built, so nothing can say whether the running pooler is answerable for a pool: '.$e->getMessage(),
            );
        }

        // The three states the row above reports as *nothing to arm* reach here as *nothing was
        // asked*, and say so rather than vanishing: a gate that selects this row by name must find
        // it in a run where the probe is off, and "not asked" is the honest answer rather than a
        // green claim that the pooler holds its pools.
        if (!$flipper->isEnabled()) {
            return $this->row($name, self::PASS, 'not asked: flipper is not armed — nothing asks the running pooler about its pools');
        }

        $verdict = $flipper->poolProbeVerdict();

        if ($verdict['enabled'] !== true) {
            return $this->row($name, self::PASS, 'not asked: the probe is not armed (swrr.pgcat.pool_probe.enabled, and a dialable `[general] port` in the file the pooler runs on) — the running pooler is taken at supervisord\'s word');
        }

        if ($verdict['applicable'] !== true) {
            return $this->row($name, self::PASS, 'not asked: no pool is declared in the configuration, so nothing could have asked the running pooler about one');
        }

        // The flipper's own sentence rather than a second copy of it — the same words `/health/db`
        // reports and logs — and it carries what the pooler said and what it means either side of
        // the boot window, which is why the row adds nothing to it. It is non-null whenever a pool
        // did not answer or could not be told apart from a name no configuration declares, which is
        // every branch below the answered one; the fallback is the answered sentence, so a `null`
        // can only reach a row that would not print it.
        $reason = $verdict['reason'] ?? 'the running pooler answered for every pool the configuration declares';

        // Where the packets actually went. A probe aimed at the wrong port reports the same silence
        // as a pooler that is down, and the pool names alone cannot tell those apart — so every row
        // that asked something names its endpoint, and a row that asked nothing appends nothing.
        // The address comes from the probe's own answer rather than from the settings, which is what
        // makes it describe the exchange instead of the intention.
        $address = $verdict['address'] ?? null;
        $probed = is_string($address) && $address !== '' ? ' — probed '.$address : '';

        if ($verdict['failed'] === true) {
            return $this->row($name, self::FAIL, $reason.$probed);
        }

        if ($verdict['warned'] === true) {
            return $this->row($name, self::WARN, $reason.$probed);
        }

        $declared = implode(', ', array_column($verdict['pools'], 'database'));
        $discriminates = $verdict['discriminates'];
        $verified = $verdict['verified'];
        $admin = $verdict['admin'];
        $judged = $verdict['judged'];

        // A green row is a claim that the pooler is answerable for its pools, and a sweep that
        // could not establish one must not make it. This is the difference the fallback exists
        // for: an auth-first pooler is read by its own admin console, and a console that could not
        // be read — or was never configured — leaves the same doubt a missing pool would settle.
        // Once the boot window has closed, that doubt is a warning rather than a pass.
        if ($verdict['answered'] === true && $verified !== true) {
            // Reached when the sentinel itself said nothing: the pools answered, but the one name
            // that would have made those answers proof could not be read either way. A sentinel
            // that *was* answered does not arrive here — the flipper publishes every pool as
            // `indeterminate` and `warned`, which the branch above has already reported.
            $why = match (true) {
                $admin !== null => sprintf('its own admin console could not list them (%s)', $admin['evidence']),
                default => 'the sentinel name it cannot hold did not answer either',
            };

            return $this->row(
                $name,
                $judged ? self::WARN : self::PASS,
                sprintf(
                    'the running pooler answered for every pool the configuration declares (%s), but %s — so this reading cannot tell a pool it holds from one it does not',
                    $declared,
                    $why,
                ).$probed,
            );
        }

        if ($verdict['answered'] === true) {
            // Two readings can establish it, and the row says which. The console listed every pool,
            // which is the pooler's own statement about what it serves — the primary reading, and
            // the one an operator can check against the credential in the pooler's file. Or the
            // front door refused a control name, which is the fallback admitting it reads pools
            // before it authenticates.
            $detail = $admin !== null && $admin['status'] === PoolProbe::ADMIN_STATUS_READ
                ? sprintf(
                    'the pooler\'s own admin console lists every pool the configuration declares (%s) — %s — so each pool is one the pooler holds',
                    $declared,
                    $admin['evidence'],
                )
                : sprintf(
                    'the running pooler answered for every pool the configuration declares (%s), and refused a control name it cannot hold — so each answer is proof the pool exists',
                    $declared,
                );

            return $this->row($name, self::PASS, $detail.$probed);
        }

        return $this->row($name, self::PASS, $reason.$probed);
    }

    /**
     * How many pool repairs this container has recorded, and when the last one happened.
     *
     * WHY THIS ROW EXISTS
     *   The count was built for `db:pgcat-flip --status`, which is a table an operator runs when
     *   they already suspect the pooler. The preflight is the surface that is read whether or not
     *   anything is suspect, and the one a deploy gates on, so the same two facts belong in front
     *   of it: a one-off repair inside the boot window is the self-heal doing its job, while a
     *   count that keeps climbing is a pooler that needs looking at — and `last_kind` reads the
     *   same `repaired` however often it happens, so the count is the only place that difference
     *   is visible.
     *
     * WHY IT IS A READING AND NOT A VERDICT
     *   A repair is not a fault: it is a run that found the pooler refusing a pool the applied
     *   file declares and re-applied the file, and the fact that it happened is the answer the
     *   self-heal gave. Failing on it would fail an installation for the flip having done its
     *   job. The standing fault, when there is one now, is `pool probe answer`'s row — it asks the
     *   pooler at this moment, where this row reports what happened — so the row passes in every state
     *   it can find and says what it read, the line `read/write pair` draws for its own reading.
     *
     * THE SAME STATE FILE, AND THE SAME DERIVATION
     *   The two facts come from `PgcatConfigFlipper::poolRepairStatus()`, which is what
     *   `windowStatus()` builds its `pool_repairs` and `last_pool_repair_at` from as well, so
     *   `--status` and this row cannot count one container's repairs differently. A second reader
     *   of the file here — `json_decode` this row's own, a `?? 0` this row's own — would be the
     *   way the two surfaces begin to disagree, and the disagreement would be silent in both.
     *
     * WHAT THE COUNT IS, AND IS NOT
     *   It is this container's history rather than a live reading: a repair is only possible
     *   inside the boot window, because `applyCurrentState()` returns before it takes the lock
     *   once the window has closed. The row says so, so a count that is not moving is not read as
     *   a pooler that has stopped needing repair.
     *
     * No finding key: a repair is a run's outcome rather than a state a boot closes out, and the
     * absence of one is not a finding at all. No `suggestion`: the repair for a pooler that keeps
     * refusing pools is the pooler — a restart, a file, an address — and none of them is a value
     * this line could name.
     *
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function poolRepairs(): array
    {
        $name = 'pool repairs';

        if (!$this->laravel->bound(PgcatConfigFlipper::class)) {
            return $this->row(
                $name,
                self::PASS,
                'the flipper is not bound, so no run repairs a pool and nothing is counted — the '
                .'`pool probe answer` row names that fault',
            );
        }

        try {
            $flipper = $this->laravel->make(PgcatConfigFlipper::class);
        } catch (Throwable $e) {
            return $this->row(
                $name,
                self::FAIL,
                'the flipper could not be built, so the pool repairs it recorded cannot be read: '.$e->getMessage(),
            );
        }

        $repairs = $flipper->poolRepairStatus();
        $armed = $flipper->isEnabled();

        if ($repairs['count'] === 0) {
            return $this->row($name, self::PASS, sprintf(
                'no pool repair is recorded in this container: no run has found the running pooler refusing a '
                .'pool the applied file declares%s',
                $armed ? '' : ', and the flipper is not armed, so none will be',
            ));
        }

        return $this->row($name, self::PASS, sprintf(
            'the pooler has been repaired %d time(s), %s — %s; the count is what `last_kind` cannot show, '
            .'because a one-off and a pooler that keeps needing repair both leave the last run reading '
            .'`repaired`',
            $repairs['count'],
            $repairs['last_at'] === null
                ? 'with no time recorded for the last one'
                : 'the last at '.$repairs['last_at'],
            $armed
                ? 'a repair is a run that found it refusing a pool and re-applied the file, and nothing '
                    .'repairs anything once the boot window has closed, so this is the container\'s convergence '
                    .'history rather than a live reading'
                : 'the flipper is not armed now, so no further repair is made',
        ));
    }

    /**
     * Which mechanism moves the pooler, and what it is written to move it at.
     *
     * WHY THIS ROW EXISTS
     *   The reader windows are a fact about routing and the flip is a fact about a file, and
     *   between them is the question an operator actually asks before a release: *will anything
     *   move pgcat when a window opens?* Nothing answered it. `reader windows` judges the setting
     *   the resolver reads, `pgcat files` and `pgcat supervisor` judge a flip that runs, and
     *   `switch values` covers pgcat's own three switches — not the two that decide whether
     *   anything is *scheduled* to run one at all.
     *
     *   The state this is here for is the one that is silent by construction. A container booted
     *   outside a reader window converges to writer-only in its first minutes and then, by design,
     *   stops: the default bounds converging *at boot*, so the per-minute entry is a no-op for the
     *   rest of the container's life. 10:00 arrives, the resolver moves to readers, nothing moves
     *   pgcat with it, and every surface reads well — the flipper is armed, the files are
     *   writable, the windows are well formed. `swrr.pgcat.schedule.windows.enabled` is the setting
     *   that closes the gap, and this row is where a preflight says whether it is on.
     *
     * WHY NEITHER ON IS A WARNING AND ONLY ONE ON IS NOT
     *   Neither entry scheduled is not a preference: the flipper is armed and no run will ever
     *   apply a mode, so the file keeps whatever the last write left and the resolver's mode moves
     *   away from it — the disagreement `reader_window.failed` exists to report, with nothing left
     *   that could repair it. One of the two on is a configuration, both of them are documented:
     *   the per-minute entry alone is *converged at boot, and no further*, which the sentence says
     *   rather than implying a fault; the window tasks alone are *the pooler follows the day*.
     *
     *   Both on is not a warning, and there are two reasons rather than one. No boundary is
     *   contended: the per-minute entry checks the boot window before it takes its lock
     *   (`PgcatConfigFlipper::applyCurrentState()`), so after a container has converged it does not
     *   act at all — it returns `window_closed` and exits without reading or writing a file — and
     *   inside the boot window the two read the same resolver and agree, with the flipper's own
     *   `flock` serialising the two runs anyway. And they share the record that used to be the
     *   problem: either one's application writes the mode, the moment and the mechanism
     *   (`PgcatConfigFlipper::VIA_SCHEDULE`, `VIA_WINDOW`, `VIA_MANUAL`), so `pgcat mode` and
     *   `/health/db` say which mechanism last moved the pooler rather than describing the one that
     *   has stopped acting — see `PgcatConfigFlipper::windowStatus()`. A row used to *warn* about the
     *   pair while only one of the two had an account of itself; the warning is gone rather than
     *   reworded, because what it warned about is no longer true.
     *
     * A REFUSED SWITCH IS THE THIRD STATE, AND IT HAS NO OTHER READER
     *   Both switches are read by the class that owns them (`FlipSchedule::options()`,
     *   `WindowFlipSchedule::settings()`), so a value that is not on or off is refused here exactly
     *   as it is refused there — and this row is the only surface that reports it. The two resolve
     *   to the value their setting documents rather than to garbage, which is what the sentence
     *   says: a typo is named and does not disarm the repair.
     *
     * Nothing is suggested. Which mechanism answers a boundary is a choice between two settings and
     * both arrangements are documented, and a `suggestion` is a value a gate may apply without
     * reading it — so pointing at one of the two is the operator's to do, and the row names the
     * mechanism and its boundaries instead.
     *
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */

    private function flipSchedule(): array
    {
        if (!$this->laravel->bound(PgcatConfigFlipper::class)) {
            return $this->row('flip schedule', self::FAIL, 'PgcatConfigFlipper is not bound — is the provider registered?');
        }

        try {
            $flipper = $this->laravel->make(PgcatConfigFlipper::class);
        } catch (Throwable $e) {
            return $this->row(
                'flip schedule',
                self::FAIL,
                'the flipper could not be built, so nothing can move the pooler at a boundary: '.$e->getMessage(),
            );
        }

        // An inert flipper has no file to move and no mode to move it to, so there is no
        // schedule to keep in step and nothing for one to be wrong about — the same conclusion
        // `pgcat files` and `pgcat supervisor` reach, said the same way.
        if (!$flipper->isEnabled()) {
            return $this->row('flip schedule', self::PASS, 'flipper is not armed — nothing moves the pooler, and nothing is waiting for it to');
        }

        $config = $this->laravel->make(Repository::class);

        // Both readings come from the classes that act on them rather than from a second parse of
        // the block, so this row cannot disagree with the entry that runs and the task that
        // registers: `FlipSchedule` decides the cadence and the switch's fallback, and
        // `WindowFlipSchedule` decides the plan.
        $perMinute = FlipSchedule::options($config);
        $windows = WindowFlipSchedule::settings($config);

        /** @var list<array{status: string, key: string|null, sentence: string, suggestion: string|null}> $problems */
        $problems = [];

        // A switch written as something that is not on or off. No finding key: nothing in the boot
        // audit remembers either of these two, so the sentence prints undated rather than dated
        // from a finding about another setting.
        if ($perMinute['refused'] !== null) {
            $problems[] = [
                'status' => self::FAIL,
                'key' => null,
                'sentence' => SwitchValue::describeRefused([$perMinute['setting'] => $perMinute['refused']])
                    .' — '.SwitchValue::ACCEPTED.'. Refused: the per-minute entry is registered as '
                    .self::switchWord($perMinute['enabled']).', the value the setting documents, because '
                    .'a typo must not be what disarms a flip.',
                'suggestion' => null,
            ];
        }

        if ($windows['refused'] !== null) {
            $problems[] = [
                'status' => self::FAIL,
                'key' => null,
                'sentence' => SwitchValue::describeRefused([$windows['setting'] => $windows['refused']])
                    .' — '.SwitchValue::ACCEPTED.'. Refused: the reader-window tasks are not registered, '
                    .'the value the setting documents — the pooler moves at no window boundary.',
                'suggestion' => null,
            ];
        }

        // A refused switch is the whole answer: which mechanism answers a boundary is a question
        // about the value the switch resolves to, and that is already in the sentence above — a
        // second sentence about a value nobody wrote would be the row talking past itself.
        if ($perMinute['refused'] !== null || $windows['refused'] !== null) {
            return $this->datedRow('flip schedule', $problems, $this->recordedFindings());
        }

        try {
            $plan = WindowFlipSchedule::plan($windows);
        } catch (Throwable $e) {
            return $this->row(
                'flip schedule',
                self::FAIL,
                'the reader-window plan could not be built, so no boundary can be scheduled: '.$e->getMessage(),
            );
        }

        if ($windows['enabled'] && $plan['entries'] === []) {
            // On, and nothing scheduled: every window was skipped, and the reasons come from the
            // planner rather than from a second reading of the same windows here. The registration
            // logs each of these as a warning too — this is the row that makes one of them fail a
            // preflight instead of waiting in a log file.
            $problems[] = [
                'status' => self::WARN,
                'key' => null,
                'sentence' => 'swrr.pgcat.schedule.windows.enabled is on and no boundary is scheduled: '
                    .implode('; ', array_map(
                        static fn (array $skip): string => $skip['window'].' — '.$skip['reason'],
                        $plan['skipped'],
                    )).'.',
                'suggestion' => null,
            ];
        } elseif ($windows['enabled']) {
            // The tasks own the day, and the per-minute entry may or may not be armed beside them.
            // Both arrangements are supported: the entry converges a container in its first
            // minutes and stops at the end of the boot window, the tasks follow the day for as
            // long as it lives, and no boundary is contended because the two read the same
            // resolver. The pair is a *pass* rather than a warning because the two now share the
            // record that used to be the problem — either one's application writes the mode, the
            // moment and the mechanism — so neither surface describes a mechanism that has stopped
            // acting. `windowStatus()` is where that is read.
            $problems[] = [
                'status' => self::PASS,
                'key' => null,
                'sentence' => sprintf(
                    'the pooler follows the day: %d boundary task(s) — %s — each probing from %ds before '
                    .'its boundary to %ds after it, and %s',
                    count($plan['entries']),
                    implode(', ', array_column($plan['entries'], 'name')),
                    $windows['lead_seconds'],
                    $windows['grace_seconds'],
                    $perMinute['enabled']
                        ? 'the per-minute entry is armed beside them, so a container also converges in its first minutes; either one\'s application records the mode, the moment and the mechanism that applied it.'
                        : 'the per-minute entry is off, so one mechanism decides the mode.',
                ),
                'suggestion' => null,
            ];
        } elseif ($perMinute['enabled']) {
            $problems[] = [
                'status' => self::PASS,
                'key' => null,
                'sentence' => sprintf(
                    'converged at boot only: the per-minute entry is on and the reader-window tasks are '
                    .'off, so the pooler follows the resolver for the %ds after a container starts and is '
                    .'a no-op for the rest of its life — nothing moves it as a reader window opens or '
                    .'closes. swrr.pgcat.schedule.windows.enabled is the setting that does.',
                    // The flipper's own reading of the bound — the class that stops the runs is the
                    // one that answers how long they are allowed to keep trying.
                    $flipper->window()->seconds(),
                ),
                'suggestion' => null,
            ];
        } else {
            $problems[] = [
                'status' => self::WARN,
                'key' => null,
                'sentence' => 'nothing is scheduled to move the pooler: the per-minute entry is off '
                    .'(swrr.pgcat.schedule.enabled) and the reader-window tasks are off '
                    .'(swrr.pgcat.schedule.windows.enabled), and the flipper is armed — so pgcat\'s file '
                    .'stays where the last write left it while the resolver\'s mode moves on, with nothing '
                    .'left that could repair it.',
                'suggestion' => null,
            ];
        }

        return $this->datedRow('flip schedule', $problems, $this->recordedFindings());
    }

    /**
     * Whether the schedule that runs the per-minute flip is still producing runs.
     *
     * WHY THIS ROW EXISTS, AND WHY IT IS NOT THE ONE ABOVE IT
     *   `flip schedule` asks what is *configured* to move the pooler — an operator reads it before a
     *   release and learns which mechanism answers a boundary. This one asks whether that mechanism
     *   has been *running*, which is a different failure at a different moment: the entry can be
     *   registered by the provider, listed by `schedule:list`, armed exactly as documented, and not
     *   firing, because the cron line (or `schedule:work`) that runs the container's schedule is
     *   gone. Nothing in the configuration changed, no row above it is wrong, and pgcat stays where
     *   the last write left it while every reading says the installation is well.
     *
     *   That is not a hypothetical: it is how a deployment sat for 85 minutes with a dead cron, and
     *   it is why the row exists rather than being folded into the one above. The boot window does
     *   not cover it either — the window judges a container's first eight minutes and then, by
     *   design, stops, so afterwards a container nobody is scheduling looks like one that is.
     *
     * THE VERDICT IS THE FLIPPER'S, AND NOTHING IS MEASURED HERE
     *   The age, the tolerance and both sentences come from
     *   {@see PgcatConfigFlipper::scheduleStatus()}, which is the same block `/health/db` publishes,
     *   so the row, the endpoint and `db:scheduler-watchdog` cannot describe one state three ways.
     *   The three states that are not a failure are not a pass by omission either — each says why it
     *   is not one:
     *
     *   - **Not scheduled**: the per-minute entry is off, so nothing stamps a beat. Nothing here
     *     judges which mechanism answers a boundary — `flip schedule` is the row that names it —
     *     and two rows arguing one setting is how a reader learns to skim them.
     *   - **Switched off**: the tolerance is zero, which is the documented way to keep this verdict
     *     off. It *warns* rather than failing: an installation that has switched off the only check
     *     that notices a dead cron is a fact a preflight should put in front of somebody, and
     *     `--strict` is how a release gate acts on it.
     *   - **Not judged yet**: the container is still inside its boot window, or has no boot stamp to
     *     measure one from. A container that has not finished starting is not a stopped schedule, and
     *     one nobody stamped must not be failed for the absence of a file the package did not write.
     *
     * THE THREE CAUSES THE VERDICT CANNOT SEE
     *   A stopped schedule has three other causes that leave the same state file behind — a store the
     *   runs' mutexes cannot be taken in, two entries running the same command, a boot stamp nothing
     *   can read — and each is reported beside the verdict rather than folded into it: they are
     *   readings, not verdicts, so the row's status and the exit code stay the beat's. They are read
     *   from the entry's own settings and the window the flipper was built with, and an installation
     *   whose entry is not scheduled reads nothing at all — see {@see ScheduleCauses}, which is also
     *   where the sentences come from, so this row and `db:doctor`'s sibling surfaces describe one
     *   reading the same way.
     *
     * No finding key: the boot record is one file per *installation* and this verdict is about one
     * container's schedule, so there is no key for a date to be remembered under — the age is in the
     * row's own sentence, which is the fact an operator acts on. The causes are not filed either,
     * for a second reason: they are this moment's readings and none of them is a state a boot could
     * close out.
     *
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function scheduleBeat(): array
    {
        if (!$this->laravel->bound(PgcatConfigFlipper::class)) {
            return $this->row(
                'schedule beat',
                self::PASS,
                'the flipper is not bound, so no entry is scheduled and there is no beat to age — the '
                .'`flip schedule` row names that fault',
            );
        }

        try {
            $flipper = $this->laravel->make(PgcatConfigFlipper::class);
        } catch (Throwable $e) {
            return $this->row(
                'schedule beat',
                self::FAIL,
                'the flipper could not be built, so the schedule cannot be watched: '.$e->getMessage(),
            );
        }

        $config = $this->laravel->make(Repository::class);
        $entry = FlipSchedule::options($config);
        $missed = ScheduleBeat::missedRuns($config->get('db-manager.swrr.pgcat.schedule'));
        $verdict = $flipper->scheduleStatus();

        // The three causes a stopped schedule has that the verdict cannot see — the store the runs'
        // mutexes live in, the entries that would run the command, the file the window is measured
        // from — read off the *flipper's* window rather than off this file's own reading of the
        // configuration, so the row reports the boot stamp the verdict was actually judged against.
        // Empty, and nothing read, when the entry is not scheduled.
        $causes = ScheduleCauses::fromContainer(
            $this->laravel,
            $entry['enabled'],
            $entry['name'],
            $flipper->window()->bootFile(),
            // The bounded run is the one mode that may not ask it: being answerable where the store
            // is not there is what the mode is for, so the reading says it did not ask rather than
            // leaving the store's reachability looking unremarkable.
            $this->connectionOnly()
                ? 'this run is bounded to what a build container can answer, and reaching the store is '
                    .'one of the things it leaves out'
                : null,
        );

        // Written once as an appendix: every sentence below is the flipper's and none of them ends in
        // a full stop, so a string that starts with a space is what keeps the two readable together.
        $readings = $causes->sentence();
        $appendix = $readings === '' ? '' : ' '.$readings;

        if ($verdict['stale'] === true) {
            return $this->row('schedule beat', self::FAIL, (string) $verdict['reason'].$appendix);
        }

        // The switch as a warning, and only where it decides something: an installation whose entry
        // is not scheduled has no beat to age whatever this setting says, and is reported by the
        // state above rather than twice.
        if ($entry['enabled'] && $missed <= 0) {
            return $this->row(
                'schedule beat',
                self::WARN,
                'nothing would notice a schedule that stopped: the watch is switched off '
                .'(swrr.pgcat.schedule.stale_after_missed_runs = 0), and the beat is still written, so '
                .'switching it back on reports the age it would have had'.$appendix,
            );
        }

        if ($verdict['stale'] === null) {
            return $this->row('schedule beat', self::PASS, (string) $verdict['not_judged'].$appendix);
        }

        return $this->row('schedule beat', self::PASS, sprintf(
            'the per-minute entry is running: its last beat was %s ago, and the tolerance is %d missed '
            .'run(s) at a cadence of %s',
            ScheduleBeat::ageText((int) ($verdict['age_seconds'] ?? 0)),
            $missed,
            $entry['interval_minutes'] === 1 ? '1 minute' : $entry['interval_minutes'].' minutes',
        ).$appendix);
    }

    /**
     * A row assembled out of every problem a setting has, each dated from its own finding.
     *
     * Shared rather than written once per row because `reader windows`, `switch values`,
     * `pgcat files` and `store probe` all make the same claim about their problems, and a row that
     * made it for itself could make it differently: that the verdict is the loudest problem in the
     * row (`verdict()` is where that rule lives), that the detail is each problem's own sentence
     * separated so an operator can tell where one ends and the next begins, that a problem naming a
     * replacement adds a `suggestion` line — a list of them, since two refused settings have two
     * repairs and printing one would leave the other to be re-spelled by hand — and that a problem
     * is dated from *its own* key. The record holds one entry per finding key and either row can
     * have several, so quoting the first key the record happens to hold would date a problem the
     * row is not reporting: a claim about when this installation started being wrong, made about
     * the wrong thing.
     *
     * The vet's `config file` row is the fifth caller and the one that passes an *empty* record,
     * which is not a shortcut: its subject is a file that has never been booted, so no finding of
     * this installation is about what it read, and every problem arrives undated. The rule the row
     * shares — a list of problems, joined rather than chosen between — is the part that travels.
     *
     * Every sentence ends in a full stop before it arrives here — that is what the age is
     * inserted in front of, so a dated problem reads as one sentence rather than a sentence with
     * an appendix.
     *
     * @param list<array{status: string, key: string|null, sentence: string, suggestion: string|null}> $problems
     * @param array<string, array{resolution: string, context: array<string, mixed>, first_reported_at: string}> $recorded
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function datedRow(string $name, array $problems, array $recorded): array
    {
        $sentences = [];
        $suggestions = [];

        foreach ($problems as $problem) {
            $finding = $problem['key'] === null ? null : ($recorded[$problem['key']] ?? null);

            // A sentence is finished here rather than at its own full stop: the row's sentences
            // mostly end with one, and the store probe's two never have (they end where the row
            // appends its own `--strict` aside, or with the file a record cannot be written to).
            // Trimming whatever full stops are there and writing one back is the same string for
            // every sentence that already ended properly.
            $sentences[] = $finding === null
                ? $problem['sentence']
                : rtrim($problem['sentence'], '.').$this->recordSuffix($finding).'.';

            if ($problem['suggestion'] !== null) {
                $suggestions[] = $problem['suggestion'];
            }
        }

        return $this->row($name, $this->verdict($problems), implode('; ', $sentences), $suggestions);
    }

    /**
     * Whether the store reachability check can run at all, which the row below it
     * cannot say for itself.
     *
     * A probe is skipped in two states, and both are silent by design: probing is
     * switched off (`store_probe_seconds = 0`, the documented way to keep the audit to
     * configuration checks), or the audit record cannot be written — and the record is
     * the throttle, so without it the audit skips the PING rather than run one per
     * request. Either way an unreachable store is reported by nothing, on every boot,
     * forever, and the reachability row's PASS means "this boot did not probe" rather
     * than "the store answered". This row is where that distinction is visible.
     *
     * Each of the two is a boot finding as well, so the row dates each from its own — and the
     * sentences come from `BootAudit`, which owns the probe and writes the same text into the log,
     * rather than being written a second time here. The third state is this row's own: a probe that
     * is on with an in-process store has nothing to reach, which is not a state the audit can
     * report, because the setting that makes it so is the store selection and the reachability row
     * already warns about it.
     *
     * The two are independent, so an installation can be in both at once, and the row names
     * every one of them rather than the first it reaches: an operator who switches the probe
     * on to clear the warning should not have to deploy again to be told that the record it
     * writes can never be written. The verdict is the loudest of them, so a switched-off
     * probe beside an unwritable record is the failure the record is. A third state is named
     * the same way — the in-process store that leaves a running probe nothing to reach — and
     * only while the probe is switched on, because with it off "nothing is probed" is that
     * same warning rather than a second problem.
     *
     * Nothing here is decided in the doctor: the interval and the writability come from
     * `BootAudit::storeProbeStatus()`, which is the same source `storeProbeDue()` reads,
     * so the row cannot disagree with the runtime about whether a probe happens.
     *
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function storeProbe(): array
    {
        try {
            $status = $this->laravel->make(BootAudit::class)->storeProbeStatus();
        } catch (Throwable $e) {
            return $this->row(
                'store probe',
                self::FAIL,
                'the boot audit could not be built, so nothing probes the store: ' . $e->getMessage(),
            );
        }

        $interval = $status['seconds'] === 1 ? '1 second' : $status['seconds'] . ' seconds';

        /** @var list<array{status: string, key: string|null, sentence: string, suggestion: string|null}> $problems */
        $problems = [];

        if (!$status['enabled']) {
            $problems[] = [
                'status' => self::WARN,
                'key' => WeightedDatabaseServiceProvider::KEY_STORE_PROBE_OFF,
                'sentence' => BootAudit::probeOffSentence($status['seconds'])
                    // Only a warning while the record can be written: beside an unwritable
                    // record the row is a failure anyway, and --strict has nothing left to add.
                    .($status['writable'] ? ', and --strict would fail this warning' : ''),
                'suggestion' => null,
            ];
        }

        if (!$status['writable']) {
            $problems[] = [
                'status' => self::FAIL,
                'key' => WeightedDatabaseServiceProvider::KEY_STORE_PROBE_FILE_UNWRITABLE,
                'sentence' => BootAudit::probeUnwritableSentence($status['file']),
                'suggestion' => null,
            ];
        }

        // A configured interval with an in-process store has nothing to reach. The
        // probe is not missing here, it is moot — and the reachability row already
        // warns about the store that makes it so. The sentence says "switched on", so it
        // describes a probe that is on and is not added beside the warning above.
        $store = WeightedDatabaseServiceProvider::storeSelection(
            ConfigValue::assoc($this->laravel->make(Repository::class)->get('db-manager.swrr')),
        );

        if ($status['enabled'] && $store['effective'] !== 'redis') {
            $problems[] = [
                'status' => self::WARN,
                // No key: nothing about this state is remembered, because nothing about it is a
                // finding. The row names it and the reachability row warns about the store.
                'key' => null,
                'sentence' => sprintf(
                    'switched on every %s but nothing is probed: swrr.primary_store is %s, so reads are served in-process and the check has nothing to reach',
                    $interval,
                    $store['configured'] === '' ? 'the in-process store' : "\"{$store['configured']}\"",
                ),
                'suggestion' => null,
            ];
        }

        if ($problems === []) {
            return $this->row(
                'store probe',
                self::PASS,
                "at most one PING every {$interval}, throttled by the record at {$status['file']}, which is writable",
            );
        }

        return $this->datedRow('store probe', $problems, $this->recordedFindings());
    }

    /**
     * Whether the audit record is still being written at all — the one reading that says the audit
     * is alive rather than only able to write.
     *
     * WHY THIS ROW EXISTS BESIDE `store probe`
     *   That row asks whether a probe *could* run: the interval, the record's mode, the store. Every
     *   one of those can be right while nothing writes the record. The schedule stops, or the runs
     *   write a state directory this installation does not read, and the silence is complete: the
     *   findings that stand are read out of the same file, `/health/db` reports what its own boot
     *   found, and a record nothing writes goes on describing an installation that no longer exists.
     *   The probe stamp is the one field a healthy installation moves on its own, so its age is the
     *   evidence, and this is the only reading here that asks for it.
     *
     * THE AGE IS THE ONE BEFORE THIS RUN WROTE ANYTHING
     *   A boot that finds the probe due sends it and stamps the record, so this command's own boot
     *   refreshes the stamp before any row is asked. Judging what the file says *now* would report
     *   every installation as freshly written and, worse, would clear the very fault it exists to
     *   report. The age comes from `BootAudit::storeProbeStamp()`, which keeps what this process
     *   found, and the sentence says so — otherwise an operator who opens the record after the run
     *   finds a timestamp minutes old next to a row saying it had not been written for a week.
     *
     * WHAT IT IS NOT JUDGED ON, AND WHY EACH IS A PASS
     *   A stamp is only expected to move where something is expected to move it, so the five states
     *   that remove the expectation are named instead of being counted as a healthy record — probing
     *   switched off, a record that cannot be written, an in-process store, a schedule that is not
     *   armed, and a record with no stamp at all. The states are `ProbeStamp::read()`'s, and so is
     *   the tolerance (the wider of the probe interval and the schedule's cadence, five misses
     *   over), because `/health/db` publishes the same age as `audit.store_probe`: one rule, two
     *   readers, and neither can widen its window without the other. This row owns the sentences —
     *   they name the rows beside it, which a payload must not do.
     *
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function probeStamp(): array
    {
        try {
            $audit = $this->laravel->make(BootAudit::class);
            $status = $audit->storeProbeStatus();
        } catch (Throwable $e) {
            return $this->row(
                'probe stamp',
                self::FAIL,
                'the boot audit could not be built, so the record it writes cannot be read: ' . $e->getMessage(),
            );
        }

        $store = WeightedDatabaseServiceProvider::storeSelection(
            ConfigValue::assoc($this->laravel->make(Repository::class)->get('db-manager.swrr')),
        );

        $entry = FlipSchedule::options($this->laravel->make(Repository::class));

        $reading = ProbeStamp::read(
            $audit->storeProbeStamp(),
            $status,
            $store['effective'] === 'redis',
            $entry,
            // The container's own life: the beat is not judged without a boot stamp, and the age of
            // the record cannot be placed against a life nobody measured — so this row and the
            // payload answer the same state the same way.
            $this->laravel->make(PgcatConfigFlipper::class)->window()->bootedAt() !== null,
        );

        $minutes = $entry['interval_minutes'] === 1 ? 'minute' : $entry['interval_minutes'] . ' minutes';

        switch ($reading['not_judged']) {
            case ProbeStamp::OFF:
                return $this->row('probe stamp', self::PASS, sprintf(
                    'not judged: probing is switched off (swrr.audit.store_probe_seconds = %d), so no stamp is ever '
                    .'written — the `store probe` row warns about the setting',
                    $reading['probe_seconds'],
                ));

            case ProbeStamp::UNWRITABLE:
                return $this->row('probe stamp', self::PASS, sprintf(
                    'not judged: the record at %s cannot be written, so its stamp is frozen rather than late — '
                    .'the `store probe` row fails on that',
                    $status['file'],
                ));

            case ProbeStamp::IN_PROCESS:
                return $this->row('probe stamp', self::PASS, sprintf(
                    'not judged: swrr.primary_store is %s, so reads are served in-process and nothing probes the '
                    .'store the stamp would date',
                    $store['configured'] === '' ? 'the in-process store' : "\"{$store['configured']}\"",
                ));

            case ProbeStamp::UNARMED:
                return $this->row(
                    'probe stamp',
                    self::PASS,
                    'not judged: the flip schedule is not armed, so no run is guaranteed to boot this installation '
                    .'and the age of the record is nobody\'s to judge — a web installation with no schedule is '
                    .'written when it serves a request, which is the traffic\'s pace rather than a fault',
                );

            case ProbeStamp::NO_STAMP:
                return $this->row('probe stamp', self::PASS, sprintf(
                    'no stamp in %s before this run, so there is no age to judge — a record that has never been '
                    .'probed is a fresh installation rather than a stale one',
                    $status['file'],
                ));
        }

        $age = (int) $reading['age_seconds'];
        $staleAfter = $reading['stale_after_seconds'];

        if ($reading['stale'] !== true) {
            return $this->row('probe stamp', self::PASS, sprintf(
                'the record had gone %s without a write when this run started, within the %s it may go between '
                .'the writes a probe interval of %d seconds and a run every %s produce',
                ScheduleBeat::ageText($age),
                ScheduleBeat::ageText($staleAfter),
                $reading['probe_seconds'],
                $minutes,
            ));
        }

        // The fault this row exists for, and the two things it can be. Both look the same from the
        // file: the record is not being written, so nothing is booting it with the probe due.
        return $this->row('probe stamp', self::FAIL, sprintf(
            'the record at %s had gone %s without a write when this run started, and the tolerance is %d '
            .'missed cadences (%s at a probe interval of %d seconds and a run every %s): nothing wrote the '
            .'audit record in that time, so the schedule that should boot it has stopped or the runs write a '
            .'state directory this installation does not read',
            $status['file'],
            ScheduleBeat::ageText($age),
            $reading['missed_cadences'],
            ScheduleBeat::ageText($staleAfter),
            $reading['probe_seconds'],
            $minutes,
        ));
    }

    /**
     * The verdict of a row built out of several problems: the loudest one wins, because a
     * refusal beside a warning is still a refusal.
     *
     * The fold itself lives in loudest() and this is one of its two callers, for the same
     * reason `gateFailed()` is a method rather than a copy of itself at the exit code — a row
     * that names more than one problem cannot then disagree with the run's summary about how
     * bad the installation is.
     *
     * @param list<array{status: string}> $problems
     */
    private function verdict(array $problems): string
    {
        $statuses = [];

        foreach ($problems as $problem) {
            $statuses[] = $problem['status'];
        }

        return $this->loudest($statuses);
    }

    /**
     * The loudest of a set of verdicts — the one fold behind both scopes: how bad a row is,
     * from the problems it names, and how bad the run is, from its rows.
     *
     * One fold rather than two because the two answers are read together: the closing line
     * of the table summarises the run while the rows above it state their own verdicts, and
     * the JSON report carries both. A rule written twice is a rule that can say a run is
     * healthy above a row that failed.
     *
     * @param list<string> $statuses
     */
    private function loudest(array $statuses): string
    {
        $status = self::PASS;

        foreach ($statuses as $one) {
            if ($one === self::FAIL) {
                $status = self::FAIL;
            } elseif ($one === self::WARN && $status !== self::FAIL) {
                $status = self::WARN;
            }
        }

        return $status;
    }

    /**
     * The run's tally, computed once so the three readers of it cannot count different things:
     * the summary line an operator reads, the `checks` the exit matrix asserts, and the JSON
     * report's `counts`.
     *
     * @param list<array{status: string, name: string, detail: string, suggestions: list<string>}> $rows
     * @return array{checks: int, passed: int, warnings: int, failed: int}
     */
    private function counts(array $rows): array
    {
        $counts = ['checks' => count($rows), 'passed' => 0, 'warnings' => 0, 'failed' => 0];

        foreach ($rows as $row) {
            if ($row['status'] === self::FAIL) {
                $counts['failed']++;
            } elseif ($row['status'] === self::WARN) {
                $counts['warnings']++;
            } else {
                $counts['passed']++;
            }
        }

        return $counts;
    }

    /**
     * How bad the run is, from its rows — the same fold as a row's verdict, one scope up, so
     * the object's `verdict` is the row vocabulary rather than a second one for the same
     * question.
     *
     * This is deliberately *not* derived from the exit code. Under `--strict` the code is `1`
     * with nothing failed, so a verdict read back out of the code would have to call an
     * installation that answered every check broken — which is the confusion the closing
     * sentence exists to prevent. The row verdicts are the installation's; the code is the
     * gate's; `strict` is what joins them.
     *
     * @param list<array{status: string, name: string, detail: string, suggestions: list<string>}> $rows
     */
    private function runVerdict(array $rows): string
    {
        $statuses = [];

        foreach ($rows as $row) {
            $statuses[] = $row['status'];
        }

        return $this->loudest($statuses);
    }

    /**
     * What this run inspected, as the configuration key the connection was read from and the
     * connection itself — one line, and which key it carries is the answer to "was it set, or
     * inherited?".
     *
     * WHY A CONFIG KEY RATHER THAN A LABEL OF THIS COMMAND'S OWN
     * ----------------------------------------------------------
     *   The banner above names the same fact in prose, and prose is what a shell gate cannot read:
     *   the deployment's image gates run the bounded mode in a container and log which connection
     *   the image resolved, grepping the log for the key the setting is *written* with —
     *   `db-manager.swrr.connection` is this package's spelling of it, the same string the health
     *   payload reports as its `source` and the same one `ActiveConnection` resolves. So the line is
     *   spelled as the configuration spells it, and the value is the connection that was followed:
     *   a name the setting gave prints under the setting's key, and one the package inherited prints
     *   under `database.default` — the distinction the object publishes as `swrr_connection` beside
     *   `default_connection`, in the spelling a reader can act on.
     *
     *   One line and not two: there is one connection, and which key answered for it is the whole of
     *   the second fact. Nothing is printed where no connection is named at all — a key with no
     *   value is not a reading, and the row that judges it says so in its own words.
     *
     * @param array<string, string|null> $subject
     * @return list<array{0: string, 1: string}>
     */
    private function subjectFacts(array $subject): array
    {
        $followed = $subject['default_connection'] ?? null;

        if (!array_key_exists('connection', $subject) || !is_string($followed) || $followed === '') {
            return [];
        }

        $configured = $subject['swrr_connection'] ?? null;

        return [[$configured === null ? 'database.default' : ActiveConnection::CONFIGURED_SOURCE, $followed]];
    }

    /**
     * The connection the package follows — `db-manager.swrr.connection` when the installation
     * names one, `database.default` otherwise — as the banner and the JSON report both name it
     * (the key stays `default_connection` so a gate that already reads it keeps working). Read
     * through ActiveConnection, the same place the gate and the flipper read it, so the three
     * cannot describe different applications.
     */
    private function followedConnection(): string
    {
        return ActiveConnection::resolve($this->laravel->make(Repository::class))['connection'];
    }

    /**
     * What `db-manager.swrr.connection` was written with, or null when the setting says nothing.
     *
     * The followed connection alone cannot tell an operator which of two installations they are
     * looking at: left unset, the package follows `database.default`, and set to that same name it
     * follows a connection the operator chose — the same routing, and two different things to
     * change. That is the pair this publishes, one key beside the other, and it is the reason the
     * value is read through the class that resolves it rather than parsed a second time here:
     * `ActiveConnection` says whether the name came from the setting at all, so this cannot
     * disagree with the gate and the flipper about which key is in play.
     *
     * Null rather than an empty string for a setting that is not there: a name that is written but
     * empty is treated as unset by the same class (its own `!== ''` test), so reporting the two
     * differently would be this key inventing a distinction the package does not make.
     */
    private function configuredConnection(): ?string
    {
        $resolved = ActiveConnection::resolve($this->laravel->make(Repository::class));

        return $resolved['source'] === ActiveConnection::CONFIGURED_SOURCE
            ? $resolved['connection']
            : null;
    }

    /**
     * The line above the table: what was inspected, what the package followed, and — in a bounded
     * run — which rows were not asked at all.
     *
     * The setting is named only when the installation wrote one, so the common shape (nothing set,
     * the default followed) is unchanged and a reader who *did* set it sees where the followed
     * connection came from without opening the config file.
     *
     * The bounded clause is not decoration. `--connection-only` prints short by design, and a
     * report that is short without saying why reads as a report in which six checks passed —
     * which is the one thing a bounded run must never be mistaken for. So the rows left out are
     * named, in the shape the object states them as absent names.
     *
     * Rows, and only rows: a *reading* a bounded run leaves untaken — the store the schedule's runs'
     * mutexes live in, which the `schedule beat` row would otherwise ask — says so in that row's own
     * sentence, because the row is there and this line is about the ones that are not. A banner that
     * also enumerated readings would be a second place to keep in step with the rows.
     */
    private function subjectLine(string $connection, string $followed, ?string $configured): string
    {
        $line = 'Connection inspected: <comment>' . $connection . '</comment>, followed connection is <comment>'
            . $followed . '</comment>';

        if ($configured !== null) {
            $line .= ' (named by <comment>' . ActiveConnection::CONFIGURED_SOURCE . '</comment>)';
        }

        // Said above the table rather than left to the row names, because a contract run's rows are
        // the keys of a record rather than the doctor's own — a reader who expected `pgcat files`
        // would otherwise read eleven unfamiliar names as this command having changed its mind.
        if ($this->contract()) {
            $line .= ' — <comment>--contract</comment>: ' . count(DeploymentContract::keys())
                . ' requirements, each answered by the rows behind it';
        }

        if ($this->connectionOnly()) {
            $line .= ' — <comment>--connection-only</comment>: <comment>'
                . implode('</comment>, <comment>', self::REACHES_OUT)
                . '</comment> were not asked, because each one reaches something a build container may not have';
        }

        // Named for the same reason the bounded clause is: the `real query` row is built in every
        // run, so the sentence an operator reads about the mode cannot be what tells them whether a
        // database was reached — and this is the one reading in the run that reaches one.
        if ($this->realTest()) {
            $line .= ' — <comment>--real-test</comment>: the connection this run follows was asked for one '
                .'real `' . PinnedQuery::QUERY . '`, which is the reading here that leaves the process';
        }

        return $line;
    }

    /**
     * Whether this run asks a row at all — the one door the bounded mode goes through.
     *
     * `--connection-only` leaves the rows in {@see REACHES_OUT} out of the run entirely: they are
     * not built, not printed as not-judged, and not in the object. A row that was *built* and then
     * filtered would have already done the thing it was left out for — a store read, a socket, a
     * `proc_open` — which is why the decision is a predicate at the call site rather than a filter
     * over the finished list.
     */
    private function asks(string $row): bool
    {
        return !$this->connectionOnly() || !in_array($row, self::REACHES_OUT, true);
    }

    /**
     * Whether this run is bounded to what a build container can answer. Read from the flag where
     * it is needed rather than carried as state, so the row list and the report cannot disagree
     * about which mode they are describing.
     */
    private function connectionOnly(): bool
    {
        return (bool) $this->option('connection-only');
    }

    /**
     * Whether this run is judging the deployment contract rather than the installation's own list
     * of rows. Like `connectionOnly()`, read from the flag where it is needed rather than carried
     * as state, so the subject line, the row list and the closing sentence cannot disagree about
     * which mode they describe.
     */
    private function contract(): bool
    {
        return (bool) $this->option('contract');
    }

    /**
     * The contract as rows: one per requirement, in the order the record states them, each answered
     * by the rows this run already built.
     *
     * The map is `Support\DeploymentContract`'s and nothing is re-read here — the same run's rows
     * are folded by name, so a contract run asks exactly the readings a plain run would, in exactly
     * the same order and with the same sentences. That is the difference between this mode and a
     * second preflight: there is one set of readings, and the contract is a second way of counting
     * them.
     *
     * @param list<array{status: string, name: string, detail: string, suggestions: list<string>}> $rows
     * @return list<array{status: string, name: string, detail: string, suggestions: list<string>}>
     */
    private function contractRows(array $rows): array
    {
        $built = [];

        foreach ($rows as $row) {
            $built[$row['name']] = $row;
        }

        $contract = [];

        foreach (DeploymentContract::requirements() as $key => $requirement) {
            $contract[] = $this->contractRow($key, $requirement, $built);
        }

        return $contract;
    }

    /**
     * One requirement, assembled from the rows behind it.
     *
     * The verdict is the loudest of the readings' — the same fold a row uses over its problems, one
     * scope up, so a requirement cannot pass above a reading that failed. The detail names every
     * reading and its verdict first, because the requirement's own status is a claim about a set
     * and an operator checking it needs to see the members; the readings that did not pass then
     * bring their own sentences, and their repairs become the requirement's `suggestions`, so the
     * fix a row could name is not lost one level up. Nothing is re-worded: the sentences are the
     * rows'.
     *
     * Three states ask for a sentence of this method's own, and each one exists so that silence is
     * never read as a pass:
     *
     *   - a requirement no row can answer (`unread`) passes saying it was not judged, and names
     *     what would have to be read instead — the package cannot fail a deployment over a fact it
     *     never read, and calling it a failure would be counting the package's own bounded
     *     surface as the deployment's fault;
     *   - a requirement whose readings were all left out by `--connection-only` passes saying so,
     *     naming them: a bounded run is short by design, and a short contract must say which
     *     requirements it never asked;
     *   - a requirement that was partly asked names the rows it did not ask beside the ones it did,
     *     in the same sentence.
     *
     * A reading the run built and did not put — `real query` without `--real-test` — is counted
     * with the ones it did not ask rather than with the ones it did: the row is present and says it
     * was not asked, and a requirement that listed it among the readings asked would be reporting a
     * silence as `none of the readings asked failed`, which is the one thing a contract row must
     * never do.
     *
     * @param array{statement: string, readings: list<string>, unread: string|null, note: string|null} $requirement
     * @param array<string, array{status: string, name: string, detail: string, suggestions: list<string>}> $built
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function contractRow(string $key, array $requirement, array $built): array
    {
        $statement = $requirement['statement'];
        $asked = [];
        $statuses = [];
        $failing = [];
        $suggestions = [];
        $unasked = [];
        $unput = [];

        foreach ($requirement['readings'] as $reading) {
            if (!isset($built[$reading])) {
                $unasked[] = $reading;

                continue;
            }

            if (!$this->put($reading)) {
                $unput[] = $reading;

                continue;
            }

            $row = $built[$reading];
            $asked[] = sprintf("'%s' %s", $reading, $row['status']);
            $statuses[] = $row['status'];

            if ($row['status'] === self::PASS) {
                continue;
            }

            $failing[] = sprintf("'%s' — %s", $reading, $row['detail']);

            foreach ($row['suggestions'] as $suggestion) {
                if (!in_array($suggestion, $suggestions, true)) {
                    $suggestions[] = $suggestion;
                }
            }
        }

        if ($asked === [] && $requirement['unread'] !== null) {
            return $this->row($key, self::PASS, $statement . ' — not judged here: ' . $requirement['unread']);
        }

        if ($asked === []) {
            return $this->row($key, self::PASS, sprintf(
                '%s — not asked: %s, so this run does not judge it',
                $statement,
                $this->unaskedSentence($unasked, $unput),
            ));
        }

        $detail = $statement . '. Readings: ' . implode(', ', $asked);

        if ($failing !== []) {
            $detail .= ' — ' . implode(' | ', $failing);
        } elseif ($unasked === [] && $unput === []) {
            $detail .= ' — every reading behind this requirement was asked and none failed';
        } else {
            $detail .= ' — none of the readings asked failed';
        }

        if ($unasked !== [] || $unput !== []) {
            $detail .= '; ' . $this->unaskedSentence($unasked, $unput);
        }

        if ($requirement['note'] !== null) {
            $detail .= ' — ' . $requirement['note'];
        }

        return $this->row($key, $this->loudest($statuses), $detail, $suggestions);
    }

    /**
     * Why a reading was not in the run, said in the terms of the run that produced neither — without
     * guessing at the cause.
     *
     * Three shapes of "not asked" and none of them is the same fact. `--connection-only` declares
     * the rows it does not ask; an installation whose `db` is not the weighted manager builds only
     * the eight rows that do not need it, so the nine that do are absent for a reason no flag on
     * this run names; and `real query` is built in every run and says in its own sentence that it
     * was not put unless `--real-test` asked for one. A sentence that assumed the first cause would
     * report the bounded mode on a run that was never asked for it, so each shape is named only
     * where it is the cause and the last one is named by the flag that lifts it.
     *
     * @param list<string> $absent the readings the requirement names that this run did not build
     * @param list<string> $unput the readings the requirement names that this run built and did not put
     */
    private function unaskedSentence(array $absent, array $unput): string
    {
        $parts = [];

        if ($absent !== []) {
            $names = implode(', ', $absent);

            $parts[] = $this->connectionOnly()
                ? sprintf('%s left out by --connection-only', $names)
                : sprintf('no row was built for %s', $names);
        }

        if ($unput !== []) {
            $parts[] = sprintf(
                '%s was built and not put — the row says so, and `--real-test` is what asks it',
                implode(', ', $unput),
            );
        }

        return implode('; ', $parts);
    }

    /**
     * Whether the row behind a name was put to its subject at all.
     *
     * One row is built in every run and not asked in most of them: `real query` leaves the process,
     * so it is carried as *not asked* rather than left out unless `--real-test` asks for one. The
     * contract has to see that distinction — the row is in `checks`, and counting it among a
     * requirement's asked readings would turn its own "not asked" into a pass one scope up — so the
     * flag is read here, where the row read it, rather than inferred from a sentence.
     */
    private function put(string $row): bool
    {
        return $row !== self::REAL_QUERY || $this->realTest();
    }

    /**
     * The run as one JSON object on stdout, and nothing else — written raw, so a detail that
     * happens to contain angle brackets is not read as a console tag and dropped on the way
     * out of a machine-readable channel.
     *
     * The key set is fixed and always present, empty where a run has nothing for a key, so a
     * gate can write `jq -e '.counts.failed == 0'` without first asking whether the field
     * exists. `exit_code` is inside the object because it is the other half of the answer: the
     * number a deploy step branches on, in the same artefact as the rows that explain it, so a
     * run cannot be read one way and acted on another.
     *
     * The one exception is the keys that name the *subject*, and it is the exception rather than a
     * loose end: an installation run names the `connection` it inspected, the `swrr_connection` the
     * setting was written with (null when it says nothing) and the `default_connection` it followed,
     * while a config-file run names the `config_file` it read. Both spellings sit in the same slot,
     * second in the object, in front of the flags and everything a gate selects on — see
     * `conclude()`, which is where the two runs meet.
     *
     * `connection_only` sits beside `strict` because it is the other flag that changes the *run*,
     * and its claims are the ones a gate cannot make from the rows: a bounded run leaves six rows
     * out of `checks` entirely, so a job that selects a row by name has to be able to see that this
     * report was never going to carry it. The rows absent are the ones named in the doctor's own
     * record, and the rendered run prints them; here the flag is what says they are missing.
     *
     * `real_test` is the third of those flags and the one that changes the *installation*: a run
     * that asked for it opened a connection, and the `real query` row in `checks` is the answer it
     * got. Published for the reason the two above are — a report that reached a database and one
     * that only read configuration must not be the same artefact to a job selecting on rows.
     *
     * `checks` is the rows themselves — the same names, the same verdicts and the same
     * suggestions the table prints, including the repairs a row can name — and each key is
     * renamed only where the table's word is the wrong one for a machine: a row's `status` is
     * published as `verdict`, which is what the table calls it and what decides the run.
     *
     * @param list<array{status: string, name: string, detail: string, suggestions: list<string>}> $rows
     * @param array<string, string|null> $subject the keys naming what was inspected
     * @param array{checks: int, passed: int, warnings: int, failed: int} $counts
     */
    private function report(array $rows, array $subject, bool $strict, array $counts, int $exitCode): int
    {
        $this->output->writeln(
            (string) json_encode(
                [
                    'command' => 'db:doctor',
                    ...$subject,
                    'strict' => $strict,
                    'connection_only' => $this->connectionOnly(),
                    'real_test' => $this->realTest(),
                    'verdict' => $this->runVerdict($rows),
                    'exit_code' => $exitCode,
                    'counts' => $counts,
                    'checks' => array_map(
                        static fn (array $row): array => [
                            'name' => $row['name'],
                            'verdict' => $row['status'],
                            'detail' => $row['detail'],
                            'suggestions' => $row['suggestions'],
                        ],
                        $rows,
                    ),
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
            OutputInterface::OUTPUT_RAW,
        );

        return $exitCode;
    }

    /**
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function storeReachability(WeightedDatabaseManager $manager, string $connection): array
    {
        try {
            $summary = $manager->healthSummary($connection);
        } catch (Throwable $e) {
            return $this->row('store reachability', self::FAIL, 'the store could not be inspected: ' . $e->getMessage());
        }

        $primary = $summary['primary_store'];
        $serving = $summary['store'];

        // Evidence that the store has already failed here, ahead of any probe.
        if (!$summary['store_healthy']) {
            return $this->row(
                'store reachability',
                self::FAIL,
                "[{$primary}] failed in this process — reads fall back to [{$serving}], which is not shared between workers",
            );
        }

        if ($summary['degraded']) {
            return $this->row(
                'store reachability',
                self::WARN,
                "this process already fell back to [{$serving}] — check whether that predates the deploy",
            );
        }

        // A deliberate in-process store has nothing to reach; the price is that
        // replica rotation is per-process, which is the point of the package.
        if ($this->swrr('primary_store', 'redis') === 'local') {
            return $this->row(
                'store reachability',
                self::WARN,
                "[{$primary}] is the configured primary store — rotation is per-process and not shared between workers",
            );
        }

        $redis = $this->swrr('redis_connection', 'default');

        // Through the container, not the facade: a `Redis` facade resolved before the
        // application bound `redis` caches the phpredis extension class, and every
        // `Redis::connection()` in that process then throws. Asking the container
        // reports the real reason instead of that artefact.
        $error = RedisAccess::ping($this->laravel, $redis);

        if ($error !== null) {
            return $this->row(
                'store reachability',
                self::FAIL,
                "[{$primary}] could not serve a read: {$error} — reads fall back to an in-process rotation that is not shared between workers",
            );
        }

        return $this->row('store reachability', self::PASS, "[{$primary}] answered a PING");
    }

    /**
     * One real query on the connection this run follows — `Support\PinnedQuery`'s reading, asked
     * only where `--real-test` asked for it and reporting that it was not asked otherwise.
     *
     * WHY THE ROW EXISTS WHEN THE QUERY IS OFF
     * ---------------------------------------
     *   A row is a name a gate selects on, and "the query passed" and "no query was run" are two
     *   different answers to the question this row is — the difference the health endpoint draws
     *   with `checked`, and the difference an image built with no database behind the connection
     *   turns on. So the row is built in every run and says which of the two it is, the way the
     *   read-path rows say their probe is not armed rather than going quiet: a gate that reads
     *   `real query` by name can never take an absent row for a passing one.
     *
     *   It is also why the row is not in {@see REACHES_OUT}. That table is for rows a bounded run
     *   may not *build*, and this one is built and simply not asked: `--connection-only` promises a
     *   run with no reachable database, and that promise is kept by the flag being absent rather
     *   than by a mode, so a caller that asks gets its query in either shape.
     *
     * The failure is the driver's own words rather than a sentence of this row's, which is what
     * makes it the sentence an operator pastes into a search — and for PostgreSQL, the one carrying
     * the SQLSTATE a deploy gate matches on. No `suggestion`: the repair is a pooler, a host or a
     * credential, and none of them is a value this line can name.
     *
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function realQuery(): array
    {
        $connection = $this->followedConnection();

        if (!$this->realTest()) {
            return $this->row(
                self::REAL_QUERY,
                self::PASS,
                'not asked — a real query leaves this process, and `--real-test` is what asks for one: '
                .'nothing was connected to, so this row is not a claim about the database',
            );
        }

        $probe = PinnedQuery::run($connection);

        if ($probe['ok']) {
            return $this->row(self::REAL_QUERY, self::PASS, sprintf(
                '[%s] answered `%s` in %s ms — the connection this run follows, asked through the application\'s '
                .'own path, so this is a server answering rather than a configuration reading',
                $connection,
                PinnedQuery::QUERY,
                number_format((float) $probe['latency_ms'], 2, '.', ''),
            ));
        }

        return $this->row(self::REAL_QUERY, self::FAIL, sprintf(
            '[%s] could not answer `%s`: %s',
            $connection === '' ? 'none' : $connection,
            PinnedQuery::QUERY,
            (string) $probe['error'],
        ));
    }

    /**
     * Whether this run was asked for one real query. Read from the flag where it is needed, like
     * `connectionOnly()`, so the row, the banner and the object cannot disagree about whether the
     * question was put to a database.
     */
    private function realTest(): bool
    {
        return (bool) $this->option('real-test');
    }

    /**
     * A `db-manager.swrr.*` value, read the way the provider builds the store so
     * the doctor and the runtime cannot disagree about which store is in use.
     */
    private function swrr(string $key, string $fallback): string
    {
        $config = $this->laravel->make(Repository::class);

        return ConfigValue::string($config->get("db-manager.swrr.{$key}"), $fallback);
    }

    /**
     * @param list<array<string, mixed>> $replicas
     */
    private function hasWeightMetadata(array $replicas): bool
    {
        foreach ($replicas as $replica) {
            if (isset($replica['weight']) || isset($replica['cpu_cores']) || isset($replica['ram_gb'])) {
                return true;
            }
        }

        return false;
    }

    private function providerRegistered(): bool
    {
        return $this->laravel->getProviders(WeightedDatabaseServiceProvider::class) !== [];
    }

    /**
     * One check's verdict. `suggestions` are the repairs, printed one per line under the
     * row and only where the offending value reduces to one without guessing —
     * `ReaderWindows::suggestion()` and `ReaderDays::suggestion()` for the reader settings,
     * `PgcatConfigFlipper::suggestionForGate()` and `suggestionForSupervisor()` for the pgcat
     * ones, each from the class that owns the value. A row that names more than one refused
     * setting has one per setting, in the order the problems are named. They are not a fourth
     * verdict and are not counted as checks: the row above them is still the FAIL this
     * run exits on.
     *
     * @param list<string> $suggestions
     * @return array{status: string, name: string, detail: string, suggestions: list<string>}
     */
    private function row(string $name, string $status, string $detail, array $suggestions = []): array
    {
        return [
            'status' => $status,
            'name' => $name,
            'detail' => $detail,
            'suggestions' => $suggestions,
        ];
    }

    /**
     * The run as a table: a banner naming what was inspected, a line per row with its repairs
     * beneath it, the tally, and one closing sentence.
     *
     * The rows, the tally and the exit rule are the installation's and the config file's alike —
     * the closing sentence is not. "Installation looks healthy" is a claim about a boot, and a run
     * that read one file has not seen a boot; the two spellings are close enough that a reader
     * skimming two reports could take the wrong one for the other, so the subject is named in
     * them rather than implied by the banner above.
     *
     * @param list<array{status: string, name: string, detail: string, suggestions: list<string>}> $rows
     * @param array{checks: int, passed: int, warnings: int, failed: int} $counts
     * @param array<string, string|null> $subject the keys naming what was inspected
     */
    private function render(array $rows, string $banner, array $counts, bool $vetting, bool $contract = false, array $subject = []): void
    {
        $this->line($banner);
        $this->newLine();

        // What was inspected, in the configuration's own spelling, under the prose that names it:
        // the banner is for a reader, and this is the line a shell gate greps. Empty for a vet,
        // whose subject is a file rather than a connection.
        $facts = $this->subjectFacts($subject);

        if ($facts !== []) {
            foreach ($facts as $fact) {
                $this->line(sprintf('  %s  %s', str_pad($fact[0], 26), $fact[1]));
            }

            $this->newLine();
        }

        foreach ($rows as $row) {
            if ($row['status'] === self::FAIL) {
                $colour = 'red';
            } elseif ($row['status'] === self::WARN) {
                $colour = 'yellow';
            } else {
                $colour = 'green';
            }

            $this->line(sprintf(
                '<fg=%s;options=bold>%s</>  %s  %s',
                $colour,
                $row['status'],
                str_pad($row['name'], 18),
                $row['detail'],
            ));

            // The repairs, in the name column so they read as part of the row above them
            // and so a log can be grepped for them — the label is the only marker they
            // need, and none of them ever becomes a row of its own.
            foreach ($row['suggestions'] as $suggestion) {
                $this->line(sprintf(
                    '      <comment>%s</comment>  %s',
                    str_pad('suggestion', 18),
                    $suggestion,
                ));
            }
        }

        $this->newLine();
        $this->line(sprintf(
            '%d checks: <fg=green>%d passed</>, <fg=yellow>%d warning%s</>, <fg=red>%d failed</>',
            $counts['checks'],
            $counts['passed'],
            $counts['warnings'],
            $counts['warnings'] === 1 ? '' : 's',
            $counts['failed'],
        ));

        if ($counts['failed'] === 0 && $counts['warnings'] === 0) {
            $this->line(match (true) {
                $vetting => '<fg=green>Nothing in this file would be refused.</>',
                $contract => '<fg=green>Every requirement of the deployment contract is answered.</>',
                default => '<fg=green>Installation looks healthy.</>',
            });
        } elseif ($counts['failed'] === 0) {
            // A warning became a failure on purpose, so the run is not the thing that
            // broke — and the rows above, all PASS and WARN, cannot say that on their own.
            // The sentence names the subject, because "this run" is the only thing the two
            // modes have in common: the gate is the same gate, the thing behind it is not.
            $this->line($this->gateFailed($counts['failed'], $counts['warnings'])
                ? sprintf(
                    '<fg=yellow>No failures — but </><fg=red>--strict counts the %d warning%s above as failures: this run exits 1 as a release gate, not because %s.</>',
                    $counts['warnings'],
                    $counts['warnings'] === 1 ? '' : 's',
                    match (true) {
                        $vetting => 'the file is unusable',
                        $contract => 'a requirement of the contract is unmet',
                        default => 'the installation is broken',
                    },
                )
                : '<fg=yellow>No failures — review the warnings above.</>');
        } else {
            $this->line(match (true) {
                $vetting => '<fg=red>A value in this file would be refused: a boot of it runs on the defaults, and logs the refusals above.</>',
                $contract => '<fg=red>A requirement of the deployment contract is not met: the rows above name the reading that failed.</>',
                default => '<fg=red>This installation will not behave as configured.</>',
            });
        }
    }
}
