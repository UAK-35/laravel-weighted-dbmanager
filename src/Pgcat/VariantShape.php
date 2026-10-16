<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Pgcat;

use Uak35\WeightedDbManager\Support\ConfigValue;

/**
 * Is the shape of what a flip swaps to one this installation can serve?
 *
 * WHY THIS EXISTS
 *   A flip replaces one file with another and restarts the pooler, and until now nothing read the
 *   file it was about to put in front of pgcat. `pgcat files` asks whether the paths can be read
 *   and written, `pgcat supervisor` asks whether the command after the swap can run, and the pool
 *   probe asks the *running* pooler which pools it holds — so a file that is readable, writable and
 *   swapped in cleanly could still be one the installation cannot use: a writer-only variant that
 *   declares the readers it exists to leave out, a variant missing a pool its own connection is
 *   written with, or a file whose pools the container's boot would have pruned because their
 *   addresses do not resolve. Every one of those is a pooler pointed at something that is not
 *   there, and pgcat validates its servers when it starts — so the failure is the pooler not
 *   coming up at all, at the window boundary, where nothing is looking.
 *
 * WHAT IT READS, AND WHAT IT REFUSES TO GUESS
 *   Three files and one connection. The files are the ones the flipper is armed with — the target
 *   pgcat is running on, the readers variant and the writer-only variant — and the pools each
 *   declares are the `[pools.<name>]` section headers of that file, read by the same reader the
 *   pool probe uses (`PoolProbe::declaredPools()`), so "the pools a variant declares" means one
 *   thing in this package rather than two. The connection is the one the package follows, and the
 *   pool names it is written with are its own `database` — which is what pgcat addresses a pool by,
 *   and how a client reaches one — plus the `database` of each entry in its `read` list, since a
 *   weighted pick is a pick between pools when the connection points at a pooler.
 *
 *   The comparison is deliberately narrower than "the connection's database against the file".
 *   Laravel's `database` is a database name on every connection and a *pool* name only where a
 *   pooler is in front, so a name no configured variant declares is not judged: an installation
 *   whose pools and whose database are simply different words is not a fault, and a preflight that
 *   failed it would be failing the configuration that works. What *is* judged is the name the
 *   connection uses and a variant declares — that is a pool this installation demonstrably serves,
 *   so every other variant is expected to serve it too, and a file in place that has lost it is the
 *   defect the reading exists for:
 *
 *     - every readable variant declares the write pool the connection writes through;
 *     - the readers variant declares every read pool the connection weights;
 *     - the writer-only variant declares none of them, because leaving the readers out is what that
 *       file is for;
 *     - the file in place is one of the two shapes rather than a mixture of both — all of the read
 *       pools or none of them, which is what a half-rendered variant (a pool commented out, a
 *       template rendered twice under two names) looks like from the outside.
 *
 * THE PRUNE IS REPORTED, NOT ASSUMED AWAY
 *   A pool whose addresses do not resolve is a pool the container's boot removes before supervisord
 *   starts, because pgcat refuses to start on a server it cannot resolve. A variant declaring one is
 *   therefore reported rather than silently served: a flip to it puts the failure the prune exists
 *   for back. It is a *warning* and not a failure on purpose, and the reason is the same one
 *   `pgcat-flip-dry-run.md` writes down for the mode bits: resolution is a fact about the host that
 *   asks, so a release engineer's laptop, a build container or a CI runner is not the network the
 *   pooler will run in. The reading is "this is what the boot would prune from here" rather than "a
 *   flip cannot work". A pool is reported only when *none* of the addresses it declares resolves: a
 *   pool with one dead address and one live one survives the prune narrowed, and calling that a
 *   fault would be reporting a fault that is not there.
 *
 * WHAT IT CANNOT SEE
 *   A variant whose pool addresses are written some other way declares no address here and is not
 *   judged for a prune (`addresses()` reads the pair form pgcat documents). A file with no
 *   readable `[pools.` section declares no pool, and a pool that no configured variant declares
 *   cannot be compared with the connection — so an installation whose every variant has been
 *   rendered empty is read as "nothing to compare" rather than as a fault, which is the line
 *   `PoolProbe`'s own reading draws for a variant that declares nothing to ask about. And it is not
 *   a TOML parser: it reads section headers and the addresses inside the sections pgcat puts them
 *   in, and leaves the rest of the file to pgcat.
 */
final class VariantShape
{
    /** A problem a flip to this shape cannot survive. */
    public const SEVERITY_FAIL = 'fail';

    /** A reading an operator needs and a preflight must not fail over. */
    public const SEVERITY_WARN = 'warn';

    /** A `[pools…]` section this reader cannot read a pool name out of. */
    public const PROBLEM_HEADER = 'header';

    /** A readable variant that declares no pool the connection writes through. */
    public const PROBLEM_WRITE_POOL = 'write_pool_missing';

    /** The readers variant declares no pool the connection weights for reads. */
    public const PROBLEM_READ_POOL = 'read_pool_missing';

    /** The writer-only variant declares a pool the connection weights for reads. */
    public const PROBLEM_READER_IN_WRITER_ONLY = 'reader_pool_in_writer_only';

    /** The file in place declares some of the connection's read pools and not all of them. */
    public const PROBLEM_MIXED_READ_POOLS = 'mixed_read_pools';

    /** A pool whose addresses the boot's prune would remove. */
    public const PROBLEM_UNRESOLVED_POOL = 'unresolved_pool';

    /**
     * The three files, by the setting each is written with, and what each one is in a sentence:
     * a reader of a report should not have to know which of the three paths is the target.
     */
    private const ROLES = [
        'config_path' => 'the file in place',
        'readers_path' => 'the readers variant',
        'no_readers_path' => 'the writer-only variant',
    ];

    /**
     * A well-formed pool section: `[pools.<name>]`, a sub-table of one, and a quoted name that keeps
     * the dots it was quoted for. It exists only to decide whether a `[pools…]` line is one this
     * package can read a name out of — the names themselves come from `PoolProbe::declaredPools()`,
     * so the two cannot disagree about what a pool is called.
     */
    private const POOL_SECTION = '/^\[\s*pools\s*\.\s*(?:"[^"]+"|[A-Za-z0-9_\-]+)(?:\s*\.\s*(?:"[^"]+"|[A-Za-z0-9_\-]+))*\s*\]\s*(?:#.*)?$/';

    /**
     * The section pgcat puts a pool's servers in, and the pair form it writes them in:
     * `servers = [["host", 5432], ["other", 5432]]`, in a `[pools.<name>.shards.<n>]` table.
     */
    private const SHARDS_SECTION = '/^\[\s*pools\s*\.\s*(?:"([^"]+)"|([A-Za-z0-9_\-]+))\s*\.\s*shards\s*\./';

    /** @var \Closure(string): bool */
    private \Closure $resolves;

    /**
     * @param array<string, string> $paths the three settings and the path each is written with
     * @param array{write: string, read: list<string>} $pools what the pinned connection is written
     *        with: the pool it writes through, and the pools its read list weights
     * @param \Closure(string): bool|null $resolves whether one address resolves from this host,
     *        injected so a test can answer without a resolver — the fixture discipline the boot's
     *        own prune test uses, a stub with a strict allowlist rather than a real DNS
     */
    public function __construct(
        private readonly array $paths,
        private readonly array $pools,
        private readonly string $connection = '',
        ?\Closure $resolves = null,
    ) {
        $this->resolves = $resolves ?? self::defaultResolves();
    }

    /**
     * What one connection is written with, as pool names: the pool it writes through and the pools
     * its read list weights.
     *
     * The write pool is the `write` block's own `database` when it names one — a connection can
     * write to a different database than it reads from, and the copy Laravel merges for a write is
     * the one a query lands with — and otherwise the connection's own. A read entry that names no
     * `database` inherits it, so it names no pool of its own and is not counted; a read entry that
     * names the connection's own database is a read of the write pool rather than a second pool,
     * and is not one either. What is left is the set of names a pooler would have to hold for this
     * connection to work.
     *
     * @param array<string, mixed> $connection one `database.connections.<name>` entry
     * @return array{write: string, read: list<string>}
     */
    public static function namedPools(array $connection): array
    {
        $write = trim(ConfigValue::string(ConfigValue::assoc($connection['write'] ?? null)['database'] ?? null));

        if ($write === '') {
            $write = trim(ConfigValue::string($connection['database'] ?? null));
        }

        $read = [];

        foreach (ConfigValue::assocList($connection['read'] ?? null) as $entry) {
            $name = trim(ConfigValue::string($entry['database'] ?? null));

            if ($name !== '' && $name !== $write) {
                $read[] = $name;
            }
        }

        return ['write' => $write, 'read' => array_values(array_unique($read))];
    }

    /**
     * Build the reading for one installation.
     *
     * @param array<string, mixed> $pgcat the `swrr.pgcat` slice the flipper is armed with
     * @param array{write: string, read: list<string>} $pools see {@see namedPools()}
     * @param \Closure(string): bool|null $resolves
     */
    public static function fromConfig(
        array $pgcat,
        array $pools,
        string $connection = '',
        ?\Closure $resolves = null,
    ): self {
        $paths = [];

        foreach (array_keys(self::ROLES) as $setting) {
            $paths[$setting] = ConfigValue::string($pgcat[$setting] ?? null);
        }

        return new self($paths, $pools, $connection, $resolves);
    }

    /**
     * Every problem the shape has, in the order the settings are written, each with the sentence a
     * report prints and the severity that decides what a surface does with it.
     *
     * A path this reading cannot read at all is not one of them, and neither is an empty one. That
     * half of the phase belongs to the rows and steps that already own it: `pgcat gate` names an
     * arming without the paths a flip needs, `pgcat files` reports each path that is not readable or
     * not writable, and the rehearsal's own `read source` step fails on the source the run is about
     * to swap. What is left here is the half none of them reads — what a file that *is* there
     * declares — so a report says "this variant is missing" once, in the row that is about files,
     * and this reading says what the files it could read declare. A file it could not read is
     * counted in `summary()` rather than reported as a problem, so the silence is legible.
     *
     * @return list<array{setting: string, role: string, kind: string, path: string, pool: string, severity: string, sentence: string}>
     */
    public function problems(): array
    {
        $problems = [];
        $declared = [];
        $bodies = [];

        foreach (self::ROLES as $setting => $role) {
            $path = trim($this->paths[$setting] ?? '');

            if ($path === '') {
                continue;
            }

            // A path that is not a readable file is skipped rather than reported: the rows above
            // this one own it, and the sentence a flip's own refusal carries already says where a
            // flip stops. What is left is the question they do not ask.
            if (!is_readable($path)) {
                continue;
            }

            $toml = @file_get_contents($path);

            if ($toml === false) {
                continue;
            }

            $bodies[$setting] = $toml;
            $declared[$setting] = PoolProbe::declaredPools($toml);

            foreach ($this->unreadableSections($toml) as $line) {
                $problems[] = $this->problem(
                    $setting,
                    self::PROBLEM_HEADER,
                    $path,
                    '',
                    self::SEVERITY_FAIL,
                    sprintf(
                        'swrr.pgcat.%s [%s] declares a section this package cannot read a pool name out of — "%s" — and a pool a flip would swap in has to be declared as [pools.<name>].',
                        $setting,
                        $path,
                        $line,
                    ),
                );
            }
        }

        $write = $this->judgedWritePool($declared);
        $read = $this->judgedReadPools($declared);

        if ($write === '' && $read === []) {
            return $problems;   // nothing this connection is written with is a pool any variant declares
        }

        foreach ($declared as $setting => $pools) {
            $role = self::ROLES[$setting];

            if ($write !== '' && !in_array($write, $pools, true)) {
                $problems[] = $this->problem(
                    $setting,
                    self::PROBLEM_WRITE_POOL,
                    (string) $this->paths[$setting],
                    $write,
                    self::SEVERITY_FAIL,
                    sprintf(
                        'swrr.pgcat.%s [%s] declares no pool "%s", the pool connection "%s" writes through%s.',
                        $setting,
                        $this->paths[$setting],
                        $write,
                        $this->connection,
                        $setting === 'config_path'
                            ? ', so the file pgcat is running on has no pool for a write to reach'
                            : ', so a flip to this file would leave every write with no pool to reach',
                    ),
                );
            }

            if ($setting === 'readers_path') {
                foreach ($read as $pool) {
                    if (!in_array($pool, $pools, true)) {
                        $problems[] = $this->problem(
                            $setting,
                            self::PROBLEM_READ_POOL,
                            (string) $this->paths[$setting],
                            $pool,
                            self::SEVERITY_FAIL,
                            sprintf(
                                'swrr.pgcat.%s [%s] declares no pool "%s", which connection "%s" weights in its read list — a flip to it would leave every read to that pool with nothing to answer it.',
                                $setting,
                                $this->paths[$setting],
                                $pool,
                                $this->connection,
                            ),
                        );
                    }
                }
            }

            if ($setting === 'no_readers_path') {
                foreach ($read as $pool) {
                    if (in_array($pool, $pools, true)) {
                        $problems[] = $this->problem(
                            $setting,
                            self::PROBLEM_READER_IN_WRITER_ONLY,
                            (string) $this->paths[$setting],
                            $pool,
                            self::SEVERITY_FAIL,
                            sprintf(
                                'swrr.pgcat.%s [%s] declares pool "%s", which connection "%s" weights in its read list — the writer-only variant exists to leave the readers out, so a flip to it would put them back.',
                                $setting,
                                $this->paths[$setting],
                                $pool,
                                $this->connection,
                            ),
                        );
                    }
                }
            }

            if ($setting === 'config_path' && $read !== [] && $write !== '') {
                // Which shape the file in place is, asked in the pools rather than in the bytes: a
                // file has all of the read pools (the readers variant) or none of them (the
                // writer-only one), and anything in between is a half-rendered file — the state a
                // pool commented out of one of them leaves.
                $declaredRead = array_values(array_filter($read, static fn (string $pool): bool => in_array($pool, $pools, true)));

                if ($declaredRead !== [] && count($declaredRead) !== count($read)) {
                    $missing = array_values(array_filter($read, static fn (string $pool): bool => !in_array($pool, $pools, true)));

                    $problems[] = $this->problem(
                        $setting,
                        self::PROBLEM_MIXED_READ_POOLS,
                        (string) $this->paths[$setting],
                        '',
                        self::SEVERITY_FAIL,
                        sprintf(
                            'swrr.pgcat.%s [%s] declares %d of the %d read pools connection "%s" weights (%s declared, %s missing), so the file in place is neither the readers variant nor the writer-only one.',
                            $setting,
                            $this->paths[$setting],
                            count($declaredRead),
                            count($read),
                            $this->connection,
                            $this->names($declaredRead),
                            $this->names($missing),
                        ),
                    );
                }
            }

            foreach ($pools as $pool) {
                $addresses = $this->unresolvedAddresses((string) ($bodies[$setting] ?? ''), $pool);

                if ($addresses === null) {
                    continue;
                }

                $problems[] = $this->problem(
                    $setting,
                    self::PROBLEM_UNRESOLVED_POOL,
                    (string) $this->paths[$setting],
                    $pool,
                    self::SEVERITY_WARN,
                    sprintf(
                        'swrr.pgcat.%s [%s] declares pool "%s", whose addresses do not resolve from here (%s) — the boot prunes a pool it cannot resolve, so a flip to this file would put back the failure the prune exists for.',
                        $setting,
                        $this->paths[$setting],
                        $pool,
                        implode(', ', $addresses),
                    ),
                );
            }
        }

        return $problems;
    }

    /**
     * The problems a flip to this shape could not survive.
     *
     * @return list<array{setting: string, role: string, kind: string, path: string, pool: string, severity: string, sentence: string}>
     */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->problems(),
            static fn (array $problem): bool => $problem['severity'] === self::SEVERITY_FAIL,
        ));
    }

    /**
     * The problems a report names and no preflight fails over: a pool this host cannot resolve,
     * which the container's own boot may well resolve.
     *
     * @return list<array{setting: string, role: string, kind: string, path: string, pool: string, severity: string, sentence: string}>
     */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->problems(),
            static fn (array $problem): bool => $problem['severity'] === self::SEVERITY_WARN,
        ));
    }

    /**
     * Whether a flip would be safe to take, as far as the shape goes. Warnings do not make it false:
     * a pool this host cannot resolve is a reading, and failing a rehearsal over it would fail on the
     * network the rehearsal happens to run in.
     */
    public function isShaped(): bool
    {
        return $this->failures() === [];
    }

    /**
     * Whether anything was compared at all. False is not a fault and is not the same fact as a shape
     * that passed: the connection names no pool a configured variant declares, so the files were read
     * and there was nothing to hold them to. A report says which of the two it is, because "not
     * judged" and "judged, and well" must not be the same silence.
     */
    public function judged(): bool
    {
        $declared = [];

        foreach (self::ROLES as $setting => $role) {
            $path = trim($this->paths[$setting] ?? '');

            if ($path === '' || !is_readable($path)) {
                continue;
            }

            $toml = @file_get_contents($path);

            if (is_string($toml)) {
                $declared[$setting] = PoolProbe::declaredPools($toml);
            }
        }

        return $this->judgedWritePool($declared) !== '' || $this->judgedReadPools($declared) !== [];
    }

    /**
     * What a report says about a shape with nothing to judge in it, or one that was judged and holds:
     * the two halves of one sentence, built from what was read rather than from a count of problems.
     */
    public function summary(): string
    {
        $declared = [];
        $readable = 0;

        foreach (self::ROLES as $setting => $role) {
            $path = trim($this->paths[$setting] ?? '');

            if ($path === '' || !is_readable($path)) {
                continue;
            }

            $toml = @file_get_contents($path);

            if (!is_string($toml)) {
                continue;
            }

            $readable++;
            $declared[$setting] = PoolProbe::declaredPools($toml);
        }

        $write = $this->judgedWritePool($declared);
        $read = $this->judgedReadPools($declared);
        $pools = $this->pools['write'] === '' ? $this->pools['read'] : [$this->pools['write'], ...$this->pools['read']];

        if ($write === '' && $read === []) {
            return $pools === []
                ? sprintf(
                    'connection "%s" names no pool of its own, so there is nothing to compare against the variants',
                    $this->connection,
                )
                : sprintf(
                    'no configured variant declares a pool connection "%s" is written with (%s), so there was nothing to compare',
                    $this->connection,
                    $this->names(array_values(array_unique($pools))),
                );
        }

        $sentences = [sprintf(
            '%d configured variant%s parse',
            $readable,
            $readable === 1 ? '' : 's',
        )];

        if ($write !== '') {
            $sentences[] = sprintf('every one declares the write pool "%s"', $write);
        }

        if ($read !== []) {
            $sentences[] = sprintf(
                'the writer-only variant declares none of the %d read pool%s connection "%s" weights (%s)',
                count($read),
                count($read) === 1 ? '' : 's',
                $this->connection,
                $this->names($read),
            );
        }

        return implode('; ', $sentences);
    }

    /**
     * The write pool the connection is written with, where a configured variant declares it — the
     * name the reading can hold every file to, and '' where there is none to hold them to.
     *
     * @param array<string, list<string>> $declared the pools of every variant that was read
     */
    private function judgedWritePool(array $declared): string
    {
        $write = trim($this->pools['write']);

        return $write !== '' && $this->declaredSomewhere($declared, $write) ? $write : '';
    }

    /**
     * The read pools the connection weights, where a configured variant declares them.
     *
     * @param array<string, list<string>> $declared
     * @return list<string>
     */
    private function judgedReadPools(array $declared): array
    {
        $judged = [];

        foreach ($this->pools['read'] as $pool) {
            if (trim($pool) !== '' && $this->declaredSomewhere($declared, $pool)) {
                $judged[] = $pool;
            }
        }

        return $judged;
    }

    /**
     * @param array<string, list<string>> $declared
     */
    private function declaredSomewhere(array $declared, string $pool): bool
    {
        foreach ($declared as $pools) {
            if (in_array($pool, $pools, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `[pools…]` lines of one variant this reader cannot read a name out of — a truncated
     * template, a section with no name, a `[pools]` with no pool below it.
     *
     * It is the parse half of "the path exists and parses", asked in the one vocabulary this package
     * owns: a pool section it cannot name is a pool no probe would ask about and no flip would
     * declare, so it is reported rather than passed over as a file with fewer pools in it. That is
     * the failure the boot's own prune test writes a case for — a reader that picks up more or less
     * than the bare name makes every downstream reading quietly wrong — and it is why this asks
     * whether the *line* is a section this package can read rather than whether the file is valid
     * TOML, which would be a parser this package does not have.
     *
     * @return list<string>
     */
    private function unreadableSections(string $toml): array
    {
        $found = [];

        foreach (preg_split('/\r\n|\r|\n/', $toml) ?: [] as $line) {
            $trimmed = ltrim($line);

            if (!str_starts_with($trimmed, '[pools') && !str_starts_with($trimmed, '[ pools')) {
                continue;
            }

            if (preg_match(self::POOL_SECTION, $trimmed) === 1) {
                continue;
            }

            $found[] = trim($line);
        }

        return $found;
    }

    /**
     * The addresses of one pool that do not resolve from this host, or null when the pool is not
     * reported: it declares no address this reader can find, or at least one of the addresses it
     * declares resolves, which is a pool the prune leaves in place.
     *
     * @return list<string>|null
     */
    private function unresolvedAddresses(string $toml, string $pool): ?array
    {
        $addresses = $this->addresses($toml, $pool);

        if ($addresses === []) {
            return null;
        }

        foreach ($addresses as $address) {
            if (($this->resolves)($address)) {
                return null;
            }
        }

        return $addresses;
    }

    /**
     * The servers one pool declares, read out of its `[pools.<name>.shards.<n>]` tables: the first
     * element of each `["host", port]` pair pgcat writes there.
     *
     * A pool's addresses are the one part of the file this package has to read itself rather than
     * through `PoolProbe`, because the probe asks about pools and this asks about servers. It reads
     * the pair form and nothing else: a file whose servers are written another way declares no
     * address here and is not judged for a prune, which is the honest answer — this is a reading of
     * the shape pgcat documents, not a TOML parser.
     *
     * @return list<string>
     */
    private function addresses(string $toml, string $pool): array
    {
        $addresses = [];

        foreach ($this->sections($toml) as $header => $body) {
            if (preg_match(self::SHARDS_SECTION, '['.$header.']', $matches) !== 1) {
                continue;
            }

            // The name is quoted or bare, and the pattern captures whichever one it is: the
            // first group is the empty string where the second one matched, which is what the
            // test reads. The other group is not written at all in that case rather than
            // written empty, so only the branch that matched asks for it.
            $name = $matches[1] !== '' ? $matches[1] : $matches[2];

            if ($name !== $pool) {
                continue;
            }

            if (preg_match('/servers\s*=\s*\[(.*)/s', $body, $servers) !== 1) {
                continue;
            }

            if (preg_match_all('/\[\s*"([^"]+)"/', $servers[1], $hosts) !== false) {
                $addresses = [...$addresses, ...$hosts[1]];
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * One variant's sections, header path to body — the header text without its brackets, so the
     * sub-table a pool's servers live in can be recognised by the same path syntax a reader sees in
     * the file.
     *
     * @return array<string, string>
     */
    private function sections(string $toml): array
    {
        $sections = [];
        $header = '';
        $body = [];
        $lines = preg_split('/\r\n|\r|\n/', $toml) ?: [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*\[([^\]]*)\]\s*(?:#.*)?$/', $line, $matches) === 1) {
                $sections[$header] = implode("\n", $body);
                $header = $matches[1];
                $body = [];

                continue;
            }

            $body[] = $line;
        }

        $sections[$header] = implode("\n", $body);

        return $sections;
    }

    /**
     * Pool names as a sentence reads them, quoted and separated: `"a", "b"`.
     *
     * @param list<string> $names
     */
    private function names(array $names): string
    {
        return implode(', ', array_map(static fn (string $name): string => '"'.$name.'"', $names));
    }

    /**
     * @return array{setting: string, role: string, kind: string, path: string, pool: string, severity: string, sentence: string}
     */
    private function problem(
        string $setting,
        string $kind,
        string $path,
        string $pool,
        string $severity,
        string $sentence,
    ): array {
        return [
            'setting' => $setting,
            'role' => self::ROLES[$setting],
            'kind' => $kind,
            'path' => $path,
            'pool' => $pool,
            'severity' => $severity,
            'sentence' => $sentence,
        ];
    }

    /**
     * The default answer to "does this address resolve from here": an address literal always does,
     * and a name is resolved by the host's own resolver — the same question `getent hosts` asks, and
     * the question the container's boot asks when it prunes.
     *
     * @return \Closure(string): bool
     */
    private static function defaultResolves(): \Closure
    {
        return static function (string $address): bool {
            if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
                return true;
            }

            // `gethostbyname()` answers with the name it was given when it cannot resolve it, which
            // is the one answer that needs no error channel to read.
            return gethostbyname($address) !== $address;
        };
    }
}
