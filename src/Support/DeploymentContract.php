<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Support;

/**
 * The deployment contract, as the requirements a preflight can be asked for by name.
 *
 * WHY THIS EXISTS
 * ---------------
 *   `docs/deployment-contract.md` states what a deployment of this package has to have: the pooler
 *   files, the prune, the state directory, the supervisor, the store. Every one of those facts is
 *   already read by a row of `db:doctor` — but the rows are named after the mechanism
 *   (`variant shape`, `pgcat supervisor`, `store reachability`), not after the requirement, so a
 *   pipeline that wants to fail *a deployment* reads eleven rows by name and keeps that list in a
 *   shell script. That list is the contract, and a list in a script is a copy of a rule rather
 *   than the rule.
 *
 *   So the mapping lives here, once: each requirement of the record, the rows that answer it, and —
 *   where no reading can answer it — the sentence saying what it would take. `db:doctor --contract`
 *   builds one row per entry, so the thing a deploy fails on is named the way the contract names
 *   it, and this class is what the record and the guard are held to.
 *
 * WHAT A READING IS
 * -----------------
 *   A reading is the *name of a row the doctor already builds*, not a second check: the contract
 *   adds no reading of its own. That is deliberate — a contract that judged something the preflight
 *   does not would be a second opinion about the same installation, and the two could disagree on
 *   the day one of them changed. The consequence is that a requirement is exactly as sharp as the
 *   rows behind it: `pooler` is answered by `pool probe`, which asks what the running pooler holds
 *   rather than which version is answering, and the entry's `note` says so.
 *
 *   The names are compared with the README's preflight table by `DeploymentContractTest`, in both
 *   directions: an entry cannot name a row that does not exist or one the README does not
 *   document, and every row the README documents is behind at least one requirement. That second
 *   half is what makes `--contract` a complete run rather than a shorter one — a new reading the
 *   doctor grows has to be placed in the contract, so the run that fails on requirements cannot
 *   quietly stop asking a question.
 *
 *   A requirement may share a row with another (`pgcat files` answers three of them), because the
 *   contract states obligations and a row is a reading: one path can be the state directory's, the
 *   boot stamp's and the lock's at once, and it should be named by whichever requirement is asking.
 */
final class DeploymentContract
{
    /**
     * The requirements, in the order the record states them.
     *
     * `readings` are `db:doctor` row names; `unread` is the sentence for a requirement no row can
     * answer (null where the readings do answer it); `note` is what the readings behind a
     * requirement cannot see, carried in the row's detail in every state (null where they see it
     * all).
     *
     * An entry with no readings at all is one the contract states and no row can judge — the
     * deployment's own script or image build is where it is decided. A contract run reports it as a
     * PASS whose detail says it was not judged, rather than as a row that failed: the package
     * cannot fail a deployment over a fact it never read, and a gate that counted it as a failure
     * would be counting this package's silence as the deployment's fault.
     *
     * @return array<string, array{
     *     statement: string,
     *     readings: list<string>,
     *     unread: string|null,
     *     note: string|null,
     * }>
     */
    public static function requirements(): array
    {
        return [
            'wiring' => [
                'statement' => 'the package in charge of reads, on the settings this boot read',
                'readings' => ['boot environment', 'provider swap', 'weighted factory', 'connector', 'published config'],
                'unread' => null,
                'note' => 'these are the readings about this boot and this installation\'s wiring — a requirement here that passes says the rows below were read from the settings the deployment spells, and one that fails says they were read from something else',
            ],
            'configuration' => [
                'statement' => 'a configuration the package reads as written',
                'readings' => ['pgcat gate', 'replica metadata', 'read/write pair', 'switch values', 'reader windows'],
                'unread' => null,
                'note' => 'a refused value resolves to its documented default rather than failing the boot, which is why this requirement is a row here and not a note somewhere else',
            ],
            'variants' => [
                'statement' => 'three pooler variants rendered from one template',
                'readings' => ['variant shape', 'pgcat files'],
                'unread' => null,
                'note' => 'the readings judge the three files this installation is pointed at; which template rendered them is the deployment\'s, and no row here can see it',
            ],
            'prune' => [
                'statement' => 'the prune that removes a pool no address resolves for, before the pooler starts',
                'readings' => ['variant shape'],
                'unread' => null,
                'note' => 'the row reads the variants the container holds now; the prune runs in the entrypoint before them, so a pool added to one afterwards is not seen here',
            ],
            'pooler' => [
                'statement' => 'a pinned, compiled pooler',
                'readings' => ['pool probe'],
                'unread' => null,
                'note' => 'the version is decided by the image build and no row reads it — `pool probe` asks what the running pooler holds, not which build is answering',
            ],
            'boot-stamp' => [
                'statement' => 'a boot stamp the flip\'s window is measured from',
                'readings' => ['pgcat files', 'schedule beat'],
                'unread' => null,
                'note' => null,
            ],
            'mechanism' => [
                'statement' => 'one mechanism for the mode, a window and a lock',
                'readings' => ['flip schedule', 'pgcat files', 'schedule beat', 'pgcat mode'],
                'unread' => null,
                'note' => null,
            ],
            'state-dir' => [
                'statement' => 'a writable state directory the schedule can write',
                'readings' => ['pgcat files', 'store probe'],
                'unread' => null,
                'note' => 'the rows report the mode and owner each path was found with rather than judging the user the schedule runs as, which is an entrypoint fact',
            ],
            'supervisor' => [
                'statement' => 'a supervisor that can be spawned and knows the pooler\'s program',
                'readings' => ['pgcat supervisor'],
                'unread' => null,
                'note' => null,
            ],
            'health-check' => [
                'statement' => 'a container health check that asks the pooler',
                'readings' => ['pool probe', 'real query'],
                'unread' => null,
                'note' => 'the check is the deployment\'s script and no row here runs it; `pool probe` answers what the pooler holds from this side, and `real query` is the query such a check runs when it is asked deeply — put only with `--real-test`, and counted here as unasked until a run passes it',
            ],
            'store' => [
                'statement' => 'a store, and a known answer for its absence',
                'readings' => ['store reachability', 'store probe', 'schedule beat'],
                'unread' => null,
                'note' => null,
            ],
            'read-caps' => [
                'statement' => 'read caps that need not match the write cap',
                'readings' => ['read caps', 'standby feedback'],
                'unread' => null,
                'note' => 'both readings are armed by swrr.read_path.enabled; switched off, they pass saying the probe was not armed, so this requirement is then asked of nothing',
            ],
            'health-endpoint' => [
                'statement' => 'the health endpoint as a machine\'s surface',
                'readings' => [],
                'unread' => 'whether a monitor or a load balancer reads /health/db, and what the container\'s own health check asks, is the deployment\'s — no row here can see either',
                'note' => null,
            ],
        ];
    }

    /**
     * The keys, in the record's order — what a contract run's `checks` are named, and what the
     * record's requirement table is held to.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::requirements());
    }
}
