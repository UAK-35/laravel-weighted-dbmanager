<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Database\Weighted;

use Closure;
use PDO;
use Throwable;
use Uak35\WeightedDbManager\Support\ConfigValue;

/**
 * What the endpoints on a connection's read path say about themselves.
 *
 * WHY THIS EXISTS
 *   Two of the things a read this package routes can be cut by are the *servers'* own settings, and
 *   neither is anywhere in a Laravel config file: the `statement_timeout` an endpoint is capped at
 *   and whether a standby keeps `hot_standby_feedback` on. They live in a parameter group, they are
 *   read per endpoint, and without this class nothing in the package knows they exist. So an
 *   installation could route every read to a replica capped at 600 ms while the writer was
 *   configured for 120 s — the replica cutting reads the writer was configured never to cut — and
 *   every row of `db:doctor` would read well.
 *
 * THREE READINGS, ONE SWEEP
 *   `caps()` is what each endpoint is capped at, `feedback()` is whether the standbys in the read
 *   list keep `hot_standby_feedback` on, and `pair()` is whether the connection has a `read`/`write`
 *   pair at all — the shape a statement pinned to the writer depends on, since Laravel honours
 *   `useWritePdo()` only where the two halves are distinct. One object answers all three because they
 *   are three questions about one connection's endpoints, and because the first two share a sweep:
 *   one connection per endpoint, one statement, and both answers read off it.
 *
 * WHAT LEAVES THE PROCESS, AND WHY THAT IS OPT-IN
 *   `caps()` and `feedback()` open one connection per endpoint, so they are behind a switch
 *   (`db-manager.swrr.read_path.enabled`, off in the shipped config) and the rows say the probe is
 *   not armed rather than reporting silence as if the endpoints had been asked. `pair()` reads the
 *   connection's config and asks nothing, so it is answered whatever the switch says — it is the one
 *   of the three that costs nothing.
 *
 *   Nothing is written to any endpoint, and the reading is one `select` of the servers' own settings
 *   — `current_setting()` and `pg_is_in_recovery()` — on a throwaway connection that
 *   {@see SingleHostProbe} purges whatever the query did. The timeout bounds the *connect*, so a
 *   preflight cannot wait on an endpoint that is not there.
 *
 * WHAT AN ENDPOINT THAT DID NOT ANSWER IS
 *   A reading, not a fault. This class reports *what it was told*: an endpoint that refused, timed
 *   out, or answered without the settings is named with what it said in the same sentence as the
 *   endpoints that did answer, and it never moves a verdict. An unreachable read endpoint is a fact
 *   about the preflight's own moment rather than about the installation, and the surfaces that judge
 *   reachability are the health monitor's — a second row failing for it would be one fact reported
 *   twice, in two vocabularies.
 *
 * WHAT IT CANNOT SEE
 *   - **A cap enforced somewhere else.** A `statement_timeout` may also be set per session by the
 *     application's own connection options, in which case what a server reports here is what a
 *     *fresh* connection to it is set to rather than what a request's connection gets. This is the
 *     server's own value, and so is the comparison — the question is whether the two sides differ.
 *   - **A standby that is not in the read list.** Only the endpoints this connection routes reads to
 *     are asked, so a standby kept warm somewhere else is not a second opinion about the read path.
 *   - **Whether a conflict would actually happen.** `hot_standby_feedback` off makes a standby cancel
 *     a query whose snapshot needs a row version the primary is vacuuming; whether that happens
 *     depends on the primary's write and vacuum work, which is not a setting any server reports.
 */
final class EndpointReadings
{
    /** The reading found nothing an operator can act on, and its sentence still states what it read. */
    public const LEVEL_OK = 'ok';

    /** The reading found something worth a warning. */
    public const LEVEL_WARN = 'warn';

    /** The writer, as a role the sweep labels one reading with. */
    public const ROLE_WRITER = 'writer';

    /** A read endpoint, as a role the sweep labels one reading with. */
    public const ROLE_READ = 'read';

    /**
     * How far below the writer's cap a read endpoint's own cap has to be to be worth warning about.
     *
     * A fraction rather than a number of milliseconds, because caps scale with the installation:
     * 90 s against 120 s is the pair the recording under `docs/` was measured on and is *not* a fault
     * — it is the reason a read a replica cut can be answered by the writer — while a read endpoint
     * capped at a fraction of the writer's is one whose reads are cut where the writer was configured
     * not to cut them.
     */
    public const FAR_BELOW = 0.5;

    /** The connect timeout the shipped config names, in milliseconds. */
    public const DEFAULT_TIMEOUT_MS = 2000;

    /**
     * The one statement a reading runs, against one endpoint.
     *
     * One statement rather than three, because it is one connection: the two settings and the
     * standby question are answered by the same server in the same round trip, and a reading that
     * asked for them separately could report a server that was in recovery for one answer and out of
     * it for the next.
     */
    private const READING_SQL = "select current_setting('statement_timeout') as statement_timeout, "
        ."current_setting('hot_standby_feedback') as hot_standby_feedback, "
        .'pg_is_in_recovery() as in_recovery';

    /** What one millisecond is worth in each unit a server prints a duration in. */
    private const UNITS = [
        'us' => 0.001,
        'ms' => 1.0,
        's' => 1000.0,
        'min' => 60000.0,
        'h' => 3600000.0,
        'd' => 86400000.0,
    ];

    /**
     * One endpoint's reading: where it is, and what it said.
     *
     * @var list<array{role: string, host: string, port: int, cap_ms: int|null, cap: string|null, standby: bool|null, feedback: bool|null, error: string|null}>
     */
    private readonly array $endpoints;

    /**
     * @param list<array{role: string, host: string, port: int, cap_ms: int|null, cap: string|null, standby: bool|null, feedback: bool|null, error: string|null}> $endpoints the sweep, writer first
     * @param list<string> $declared the connection's `read` list as configured, `host:port` each
     * @param bool         $armed    whether the config arms the sweep; false reads nothing at all
     * @param string       $base     the host:port the connection's own config names — the writer when
     *                               no `write` block names one
     * @param string       $writer   the host:port the `write` block names, or the connection's own
     * @param string|null  $failure  why nothing could be read at all, when that is why
     */
    private function __construct(
        array $endpoints,
        private readonly array $declared,
        private readonly string $connection,
        private readonly bool $armed,
        private readonly bool $writeDeclared,
        private readonly string $base,
        private readonly string $writer,
        private readonly ?string $failure,
    ) {
        $this->endpoints = $endpoints;
    }

    /**
     * Read the connection's endpoints, as far as the switch and the config let this process.
     *
     * The config is read through the manager rather than off the repository, so the endpoints asked
     * are the endpoints the resolver routes to: a `read` that is one map rather than a list, a host
     * whose port comes from the connection, and a `write` that is a list are all read here the way
     * they are read there.
     *
     * @param bool   $armed     `db-manager.swrr.read_path.enabled`
     * @param int    $timeoutMs `db-manager.swrr.read_path.timeout_ms`
     * @param Closure(array<string, mixed>, array<string, mixed>, string): list<array<string, mixed>>|null $query
     *                          how one reading is taken from one endpoint: `[$base, $host, $sql]` ->
     *                          rows. Injected so a test can answer without a server; the default is
     *                          one throwaway connection per endpoint.
     */
    public static function of(
        WeightedDatabaseManager $manager,
        string $connection,
        bool $armed,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
        ?Closure $query = null,
    ): self {
        try {
            $config = $manager->connectionConfig($connection);
        } catch (Throwable $e) {
            return new self([], [], $connection, $armed, false, 'unknown', 'unknown', $e->getMessage());
        }

        $declared = $config['read'] ?? null;
        $replicas = is_array($declared) && $declared !== []
            ? (array_is_list($declared) ? $declared : [$declared])
            : [];

        $writers = $config['write'] ?? null;
        $writeDeclared = is_array($writers) && $writers !== [];

        $defaultPort = ConfigValue::int($config['port'] ?? null, 5432);
        $base = SingleHostProbe::address($config, $defaultPort);
        $writerHost = self::writerHost($config);

        // Read off the config rather than off the sweep, because `pair()` is answered whether or not
        // the probe is armed: the question is what the connection declares, which a connection whose
        // endpoints were never asked still declares.
        $writer = $writerHost === []
            ? $base[0].':'.$base[1]
            : implode(':', SingleHostProbe::address($writerHost, $defaultPort));

        $listed = [];

        foreach ($replicas as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $listed[] = implode(':', SingleHostProbe::address(ConfigValue::assoc($entry), $defaultPort));
        }

        $readings = [];

        if ($armed) {
            // The timeout is merged into the base config rather than passed beside it, because the
            // connection a probe opens is built from that map and nothing else: a bound handed to a
            // probe *alongside* the config would be relying on the probe to apply it, and a caller
            // that forgot would open a socket with no bound at all.
            $options = ConfigValue::assoc($config['options'] ?? null);
            $options[PDO::ATTR_TIMEOUT] = max(1, (int) ceil($timeoutMs / 1000));
            $probe = ['options' => $options] + $config;

            $readings[] = self::read($manager, $probe, $writerHost, self::ROLE_WRITER, $defaultPort, $query);

            foreach ($replicas as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $readings[] = self::read($manager, $probe, ConfigValue::assoc($entry), self::ROLE_READ, $defaultPort, $query);
            }
        }

        return new self(
            $readings,
            $listed,
            $connection,
            $armed,
            $writeDeclared,
            $base[0].':'.$base[1],
            $writer,
            null,
        );
    }

    /**
     * What each endpoint on the connection is capped at, and what that means for a read this package
     * routes to a read endpoint.
     *
     * @return array{level: string, sentence: string}
     */
    public function caps(): array
    {
        if (($unread = $this->unreadable()) !== null) {
            return $unread;
        }

        if (!$this->armed) {
            return self::reading(self::LEVEL_OK, sprintf(
                'the probe is not armed (db-manager.swrr.read_path.enabled), so no endpoint on [%s] was asked '
                .'what it is capped at — the caps are the servers\' own settings, so nothing else can report '
                .'them, and turning this on has `db:doctor` ask each one',
                $this->connection,
            ));
        }

        $writer = $this->writerReading();
        $readers = $this->readReadings();
        $answered = self::answered($readers);

        if ($readers === []) {
            return self::reading(self::LEVEL_OK, sprintf(
                'every read on [%s] uses the writer, which %s — there is no second endpoint here for a cut '
                .'read to be answered by',
                $this->connection,
                $writer['error'] === null ? 'is capped at '.$writer['cap'] : 'could not be asked ('.$writer['error'].')',
            ));
        }

        if ($answered === []) {
            return self::reading(self::LEVEL_OK, sprintf(
                'no read endpoint on [%s] could be asked what it is capped at: %s%s',
                $this->connection,
                self::unanswered($readers),
                $writer['error'] === null
                    ? ' — the writer answered ('.$writer['cap'].') and there is nothing on the read side to compare it with'
                    : ', and neither did the writer ('.$writer['error'].')',
            ));
        }

        $below = self::farBelow($answered, $writer);

        if ($below !== []) {
            return self::reading(self::LEVEL_WARN, sprintf(
                'a read cut on [%s] is one the writer\'s cap could have answered: with the writer at %s, %s — a '
                .'read endpoint capped far below the writer\'s is one whose reads this package routes into a cut '
                .'the writer was configured not to make, so a statement a slower endpoint would have finished is '
                .'cancelled here. Raise `statement_timeout` on that parameter group, or take the endpoint out of '
                .'the connection\'s `read` list%s',
                $this->connection,
                $writer['cap'] === null ? 'a cap this cannot read' : $writer['cap'],
                implode(' and ', $below),
                self::silentClause($readers),
            ));
        }

        if ($writer['error'] !== null) {
            return self::reading(self::LEVEL_OK, sprintf(
                'the writer of [%s] could not be asked what it is capped at (%s), so the two sides cannot be '
                .'compared: the read endpoints answered %s%s',
                $this->connection,
                $writer['error'],
                self::listOf($answered),
                self::silentClause($readers),
            ));
        }

        if (self::unequal($answered, $writer)) {
            return self::reading(self::LEVEL_OK, sprintf(
                'the two sides of [%s] are capped differently on purpose: %s and the writer at %s, so a read cut '
                .'on a read endpoint is one the writer\'s cap can answer — which is the reason a statement that '
                .'times out on a replica can be escalated by its caller rather than retried against the same '
                .'server. Nothing here is wrong%s',
                $this->connection,
                self::listOf($answered),
                (string) $writer['cap'],
                self::silentClause($readers),
            ));
        }

        return self::reading(self::LEVEL_OK, sprintf(
            'every endpoint on [%s] is capped the same way: %s and the writer at %s, so a read and a write are cut '
            .'at the same moment and escalating a read a read endpoint cut buys its caller no more time than '
            .'re-running it would%s',
            $this->connection,
            self::listOf($answered),
            (string) $writer['cap'],
            self::silentClause($readers),
        ));
    }

    /**
     * Whether the standbys this package sends reads to ask the primary to keep the row versions a
     * query's snapshot needs.
     *
     * @return array{level: string, sentence: string}
     */
    public function feedback(): array
    {
        if (($unread = $this->unreadable()) !== null) {
            return $unread;
        }

        if (!$this->armed) {
            return self::reading(self::LEVEL_OK, sprintf(
                'the probe is not armed (db-manager.swrr.read_path.enabled), so no standby on [%s] was asked '
                .'whether it keeps `hot_standby_feedback` on — a standby without it can cancel a read at SQLSTATE '
                .'40001, and `docs/read-path-cancellations.md` is where that failure is written down',
                $this->connection,
            ));
        }

        $readers = $this->readReadings();

        if ($readers === []) {
            return self::reading(self::LEVEL_OK, sprintf(
                'every read on [%s] reaches the writer, and a primary does not ask a primary to hold its own row '
                .'versions back, so this connection has no standby to read `hot_standby_feedback` from',
                $this->connection,
            ));
        }

        $answered = self::answered($readers);

        if ($answered === []) {
            return self::reading(self::LEVEL_OK, sprintf(
                'no read endpoint on [%s] could be asked about `hot_standby_feedback`: %s',
                $this->connection,
                self::unanswered($readers),
            ));
        }

        $off = [];
        $unknown = [];
        $standbys = [];

        foreach ($answered as $endpoint) {
            if ($endpoint['standby'] !== true) {
                continue;
            }

            $standbys[] = $endpoint;

            // `off` and "did not say" are two readings rather than one: a standby that answered off
            // is one whose reads can be cancelled, and a standby that answered without the setting is
            // one whose answer nobody has — and a warning that treated the second as the first would
            // report a fault it had not read.
            if ($endpoint['feedback'] === false) {
                $off[] = $endpoint;
            } elseif ($endpoint['feedback'] === null) {
                $unknown[] = $endpoint;
            }
        }

        if ($off !== []) {
            return self::reading(self::LEVEL_WARN, sprintf(
                'a read on [%s] can be cancelled by a conflict with recovery: %s in recovery with '
                .'`hot_standby_feedback` off, so %s does not ask the primary to hold the row versions a query\'s '
                .'snapshot needs and the primary may vacuum them away while a read is running — the read is then '
                .'cut at SQLSTATE 40001 and the caller is the one that has to answer it. Turn the setting on for '
                .'that parameter group, or take the endpoint out of the connection\'s `read` list%s%s',
                $this->connection,
                self::listOf($off),
                count($off) === 1 ? 'it' : 'they',
                self::silentClause($readers),
                self::unknownClause($unknown),
            ));
        }

        if ($standbys !== []) {
            return self::reading(self::LEVEL_OK, sprintf(
                'every standby [%s] sends reads to keeps `hot_standby_feedback` on (%s), so the primary is asked '
                .'to hold the row versions a read\'s snapshot needs and a conflict with recovery is not the shape '
                .'this path fails in%s%s',
                $this->connection,
                self::listOf($standbys),
                self::silentClause($readers),
                self::unknownClause($unknown),
            ));
        }

        return self::reading(self::LEVEL_OK, sprintf(
            'no endpoint in the read list of [%s] is in recovery: %s, so reads chosen for %s reach a primary and '
            .'nothing there is cancelled by a conflict with recovery%s',
            $this->connection,
            self::listOf($answered),
            count($answered) === 1 ? 'it' : 'them',
            self::silentClause($readers),
        ));
    }

    /**
     * Whether the connection declares a `read`/`write` pair — the shape a statement pinned to the
     * writer is honoured on.
     *
     * WHY THIS READING FAILS NOTHING
     *   Laravel honours `useWritePdo()` where the two halves are distinct: with a `read` list and no
     *   `write` block a pinned statement reaches the connection's own host, and with neither it
     *   reaches the server every read already reaches. Both are valid installations — a single-host
     *   connection is the documented shape for one with no replicas — and whether an application
     *   *relies* on a pin is that application's own setting, which this package cannot read and would
     *   be guessing at. So the reading states the shape and what a pinned statement reaches under it,
     *   which is the fact a reader came for.
     *
     * @return array{level: string, sentence: string}
     */
    public function pair(): array
    {
        if (($unread = $this->unreadable()) !== null) {
            return $unread;
        }

        $reach = $this->readEndpoints();
        $writer = $this->writerAddress();

        if ($this->declared !== [] && $this->writeDeclared) {
            return self::reading(self::LEVEL_OK, sprintf(
                'connection [%s] declares both halves of the pair: reads reach %s and writes reach %s from its '
                .'`write` block, so a statement pinned to the write connection — the `useWritePdo()` an '
                .'application uses to escape the read pool — is answered by the writer rather than by an endpoint '
                .'the pooled reads use',
                $this->connection,
                $reach,
                $writer,
            ));
        }

        if ($this->writeDeclared) {
            return self::reading(self::LEVEL_OK, sprintf(
                'connection [%s] declares a `write` block and no `read` list: reads and writes both reach %s, so '
                .'every read already uses the writer and pinning a statement to it changes nothing',
                $this->connection,
                $writer,
            ));
        }

        if ($this->declared !== []) {
            return self::reading(self::LEVEL_OK, sprintf(
                'connection [%s] declares a `read` list and no `write` block: reads are pooled over %s, and writes '
                .'reach %s — the connection\'s own host, since no `write` block names another — so a pinned '
                .'statement is answered there instead of by the pooled connection',
                $this->connection,
                $reach,
                $this->base,
            ));
        }

        return self::reading(self::LEVEL_OK, sprintf(
            'connection [%s] declares neither half of the pair: one host (%s) answers every read and every write, '
            .'so a statement pinned to the write connection is answered by the server every read already reaches',
            $this->connection,
            $this->base,
        ));
    }

    /**
     * The sentence for a connection nothing could be read from — the same one for all three readings,
     * naming the row that owns that fault rather than reporting it as this one's own.
     *
     * @return array{level: string, sentence: string}|null
     */
    private function unreadable(): ?array
    {
        if ($this->failure === null) {
            return null;
        }

        return self::reading(self::LEVEL_OK, sprintf(
            'the connection [%s] could not be read (%s), so nothing here was asked — `replica metadata` is the row '
            .'that reports a connection this package cannot resolve',
            $this->connection,
            $this->failure,
        ));
    }

    /**
     * One endpoint asked, on a connection of its own.
     *
     * A server that answered without the settings is not the same as one that refused, and neither is
     * a server that answered, so the first two are `error`: what a reader needs from this is that the
     * cap is *unknown* rather than that there is none.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $host
     * @param Closure(array<string, mixed>, array<string, mixed>, string): list<array<string, mixed>>|null $query
     * @return array{role: string, host: string, port: int, cap_ms: int|null, cap: string|null, standby: bool|null, feedback: bool|null, error: string|null}
     */
    private static function read(
        WeightedDatabaseManager $manager,
        array $base,
        array $host,
        string $role,
        int $defaultPort,
        ?Closure $query,
    ): array {
        // An empty host map is the connection's own host — the writer where no `write` block names
        // one — which is the same reading `SingleHostProbe::config()` makes of it.
        [$hostname, $port] = SingleHostProbe::address($host === [] ? $base : $host, $defaultPort);

        $reading = [
            'role' => $role,
            'host' => $hostname,
            'port' => $port,
            'cap_ms' => null,
            'cap' => null,
            'standby' => null,
            'feedback' => null,
            'error' => null,
        ];

        // The maps are handed over as the class's own narrowing reads them — `ConfigValue::assoc()
        // re-keys them and the probe narrows the same two arrays the same way — so the injected
        // closure and the real reading agree about what a connection config is.
        /** @var Closure(array<string, mixed>, array<string, mixed>, string): list<array<string, mixed>> $ask */
        $ask = $query ?? static fn (array $base, array $host, string $sql): array => self::ask(
            $manager,
            ConfigValue::assoc($base),
            ConfigValue::assoc($host),
            $sql,
        );

        try {
            $rows = $ask($base, $host, self::READING_SQL);
        } catch (Throwable $e) {
            $reading['error'] = $e->getMessage();

            return $reading;
        }

        $row = $rows[0] ?? [];

        if ($row === []) {
            $reading['error'] = 'it answered without the settings this reading asks for';

            return $reading;
        }

        $cap = $row['statement_timeout'] ?? null;

        $reading['cap_ms'] = self::milliseconds(is_scalar($cap) ? (string) $cap : null);

        if ($reading['cap_ms'] === null) {
            $reading['error'] = $cap === null
                ? 'it answered without a `statement_timeout`'
                : sprintf('it answered with a `statement_timeout` this cannot read (%s)', self::quote($cap));

            return $reading;
        }

        $reading['cap'] = self::duration($reading['cap_ms']);
        $reading['standby'] = self::boolean($row['in_recovery'] ?? null);
        $reading['feedback'] = self::boolean($row['hot_standby_feedback'] ?? null);

        return $reading;
    }

    /**
     * The default reading: one query, on a connection of its own, to the endpoint it is asked about.
     *
     * A method rather than a closure written inline, so the one place the reading is taken is also
     * the one place its argument order is written down: an injected closure is handed the same three
     * values this takes, and a caller that wants the real reading passes nothing at all.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $host
     * @return list<array<string, mixed>>
     */
    private static function ask(WeightedDatabaseManager $manager, array $base, array $host, string $sql): array
    {
        return SingleHostProbe::query($manager, $base, $host, $sql);
    }

    /**
     * The writer's host map, in the three shapes the connector accepts: a `write` list whose first
     * entry is the one it uses, a `write` map, or no `write` block at all — in which case the
     * connection's own host is the writer, and an empty map is what makes that the same call.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function writerHost(array $config): array
    {
        $declared = $config['write'] ?? null;

        if (!is_array($declared) || $declared === []) {
            return [];
        }

        $candidate = array_is_list($declared) ? ($declared[0] ?? null) : $declared;

        return is_array($candidate) ? ConfigValue::assoc($candidate) : [];
    }

    /**
     * A server's duration in milliseconds, or null when the value is not one this can read.
     *
     * PostgreSQL answers `show statement_timeout` in the largest unit that divides it exactly, so one
     * cap arrives as `90s`, `2min` or `600ms` depending only on its value, and a parser that assumed
     * one suffix would read two of those three as something else. `0` is the documented way to switch
     * the cap off, and it stays `0` rather than becoming null: "nothing cuts a statement here" is a
     * reading, and an endpoint capped at nothing is not an endpoint nobody asked.
     */
    private static function milliseconds(?string $value): ?int
    {
        $value = trim((string) $value);

        if ($value === '' || preg_match('/^(\d+(?:\.\d+)?)\s*([a-z]*)$/i', $value, $matches) !== 1) {
            return null;
        }

        $unit = strtolower($matches[2]) === '' ? 'ms' : strtolower($matches[2]);

        if (!isset(self::UNITS[$unit])) {
            return null;
        }

        return (int) round((float) $matches[1] * self::UNITS[$unit]);
    }

    /** A cap as a sentence reads it: whole minutes, whole seconds, or milliseconds. */
    private static function duration(int $milliseconds): string
    {
        if ($milliseconds === 0) {
            return 'uncapped (statement_timeout is 0)';
        }

        if ($milliseconds % 60000 === 0) {
            return intdiv($milliseconds, 60000).' minute(s)';
        }

        if ($milliseconds % 1000 === 0) {
            return intdiv($milliseconds, 1000).' s';
        }

        return $milliseconds.' ms';
    }

    /**
     * The endpoints that answered, in the order the connection lists them.
     *
     * @param list<array{role: string, host: string, port: int, cap_ms: int|null, cap: string|null, standby: bool|null, feedback: bool|null, error: string|null}> $endpoints
     * @return list<array{role: string, host: string, port: int, cap_ms: int|null, cap: string|null, standby: bool|null, feedback: bool|null, error: string|null}>
     */
    private static function answered(array $endpoints): array
    {
        return array_values(array_filter(
            $endpoints,
            static fn (array $endpoint): bool => $endpoint['error'] === null,
        ));
    }

    /**
     * The endpoints that did not answer, as one clause: every one of them, because a reader deciding
     * whether to look further needs to know *which* of their endpoints is silent.
     *
     * @param list<array{role: string, host: string, port: int, cap_ms: int|null, cap: string|null, standby: bool|null, feedback: bool|null, error: string|null}> $endpoints
     */
    private static function unanswered(array $endpoints): string
    {
        $named = [];

        foreach ($endpoints as $endpoint) {
            if ($endpoint['error'] !== null) {
                $named[] = self::silent($endpoint);
            }
        }

        return implode('; ', $named);
    }

    /**
     * The endpoints whose cap is far enough below the writer's to be worth a warning, as clauses.
     *
     * An uncapped read endpoint (`statement_timeout = 0`) is never one of them: nothing cuts a
     * statement there, so there is no cut for the writer's cap to answer. Neither is an endpoint the
     * writer could not be compared with — the caller decides that before asking.
     *
     * @param list<array{role: string, host: string, port: int, cap_ms: int|null, cap: string|null, standby: bool|null, feedback: bool|null, error: string|null}> $answered
     * @param array{role: string, host: string, port: int, cap_ms: int|null, cap: string|null, standby: bool|null, feedback: bool|null, error: string|null} $writer
     * @return list<string>
     */
    private static function farBelow(array $answered, array $writer): array
    {
        $writerCap = $writer['cap_ms'];

        if ($writerCap === null || $writerCap === 0) {
            return [];
        }

        $below = [];

        foreach ($answered as $endpoint) {
            $cap = $endpoint['cap_ms'];

            if ($cap === null || $cap === 0 || $cap >= $writerCap * self::FAR_BELOW) {
                continue;
            }

            $below[] = sprintf('%s:%d is capped at %s', $endpoint['host'], $endpoint['port'], (string) $endpoint['cap']);
        }

        return $below;
    }

    /**
     * Whether any answered read endpoint is capped differently from the writer.
     *
     * @param list<array{role: string, host: string, port: int, cap_ms: int|null, cap: string|null, standby: bool|null, feedback: bool|null, error: string|null}> $answered
     * @param array{role: string, host: string, port: int, cap_ms: int|null, cap: string|null, standby: bool|null, feedback: bool|null, error: string|null} $writer
     */
    private static function unequal(array $answered, array $writer): bool
    {
        foreach ($answered as $endpoint) {
            if ($endpoint['cap_ms'] !== $writer['cap_ms']) {
                return true;
            }
        }

        return false;
    }

    /**
     * The endpoints' caps as one clause, each named with its own: the comparison this reading is about
     * is between values, so a reader has to see which endpoint holds which.
     *
     * @param list<array{role: string, host: string, port: int, cap_ms: int|null, cap: string|null, standby: bool|null, feedback: bool|null, error: string|null}> $endpoints
     */
    private static function listOf(array $endpoints): string
    {
        $named = [];

        foreach ($endpoints as $endpoint) {
            $named[] = sprintf('%s:%d at %s', $endpoint['host'], $endpoint['port'], (string) $endpoint['cap']);
        }

        return implode(', ', $named);
    }

    /**
     * The endpoints that did not answer, appended to a sentence that has already stated what did.
     *
     * @param list<array{role: string, host: string, port: int, cap_ms: int|null, cap: string|null, standby: bool|null, feedback: bool|null, error: string|null}> $endpoints
     */
    private static function silentClause(array $endpoints): string
    {
        $silent = [];

        foreach ($endpoints as $endpoint) {
            if ($endpoint['error'] !== null) {
                $silent[] = self::silent($endpoint);
            }
        }

        return $silent === [] ? '' : ' — and nothing here is read from '.implode(', ', $silent);
    }

    /**
     * The standbys whose answer did not carry the setting, as a clause of their own.
     *
     * A reading rather than a warning: `hot_standby_feedback` off is a fact about a server's
     * configuration, and a server that answered without the setting is one this did not read — the two
     * are told apart here because a warning that folded them together would report a value nobody saw.
     *
     * @param list<array{role: string, host: string, port: int, cap_ms: int|null, cap: string|null, standby: bool|null, feedback: bool|null, error: string|null}> $unknown
     */
    private static function unknownClause(array $unknown): string
    {
        if ($unknown === []) {
            return '';
        }

        $named = [];

        foreach ($unknown as $endpoint) {
            $named[] = $endpoint['host'].':'.$endpoint['port'];
        }

        return ' — '.implode(', ', $named).' answered in recovery without the setting, so whether '
            .(count($unknown) === 1 ? 'it keeps' : 'they keep').' it on is not known here';
    }

    /**
     * One endpoint that did not answer, as a clause: where it is, and what it said instead.
     *
     * @param array{role: string, host: string, port: int, cap_ms: int|null, cap: string|null, standby: bool|null, feedback: bool|null, error: string|null} $endpoint
     */
    private static function silent(array $endpoint): string
    {
        return sprintf('%s:%d did not answer (%s)', $endpoint['host'], $endpoint['port'], (string) $endpoint['error']);
    }

    /**
     * The writer's reading, or an unasked one when the sweep was not run at all — the connection's
     * own host, which is the writer where no `write` block names another.
     *
     * @return array{role: string, host: string, port: int, cap_ms: int|null, cap: string|null, standby: bool|null, feedback: bool|null, error: string|null}
     */
    private function writerReading(): array
    {
        return $this->endpoints[0] ?? [
            'role' => self::ROLE_WRITER,
            'host' => $this->base,
            'port' => 0,
            'cap_ms' => null,
            'cap' => null,
            'standby' => null,
            'feedback' => null,
            'error' => null,
        ];
    }

    /**
     * Where a statement pinned to the write connection is answered: the endpoint the `write` block
     * names, or the connection's own host where it names none.
     */
    private function writerAddress(): string
    {
        return $this->writer;
    }

    /**
     * The read endpoints' readings, or an empty list when the sweep was not run: a connection whose
     * probe is off has a read list, but nothing was asked of it, and `pair()` reads that list from the
     * config instead.
     *
     * @return list<array{role: string, host: string, port: int, cap_ms: int|null, cap: string|null, standby: bool|null, feedback: bool|null, error: string|null}>
     */
    private function readReadings(): array
    {
        return array_slice($this->endpoints, 1);
    }

    /** The read list as one clause, from the config rather than from the sweep. */
    private function readEndpoints(): string
    {
        return count($this->declared).' read endpoint(s) ('.implode(', ', $this->declared).')';
    }

    /**
     * A PostgreSQL boolean, as the driver spells it: `t`/`f` over PDO, `true`/`false` over some
     * connectors. A value that is neither is `null` rather than false — a setting nothing answered is
     * a different reading from one that is off.
     */
    private static function boolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (!is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            't', 'true', 'on', 'yes', '1' => true,
            'f', 'false', 'off', 'no', '0' => false,
            default => null,
        };
    }

    /** A value as a sentence can quote it, whether or not it is one. */
    private static function quote(mixed $value): string
    {
        return is_scalar($value) ? var_export($value, true) : get_debug_type($value);
    }

    /**
     * @return array{level: string, sentence: string}
     */
    private static function reading(string $level, string $sentence): array
    {
        return ['level' => $level, 'sentence' => $sentence];
    }
}
