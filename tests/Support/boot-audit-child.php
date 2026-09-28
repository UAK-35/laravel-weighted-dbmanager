<?php

declare(strict_types=1);

/**
 * One boot of the audit record, as a process of its own — the child half of
 * BootAuditConcurrencyTest, and runnable by hand for the same reason a recipe is.
 *
 * WHY A PROCESS RATHER THAN A SECOND BootAudit IN ONE PROCESS
 * -----------------------------------------------------------
 * The mechanism under test is `flock()` on a companion file, and a lock is only worth
 * measuring between processes: two descriptors the same process opened still collide, so a
 * same-process test would pass against a design that did not exclude anything at all — say
 * one that took the lock and never released it, or that locked a path the writers do not
 * share. Every other cross-process behaviour is here too: the record is read, merged and
 * renamed by processes that share nothing but the filesystem.
 *
 * WHAT IS MEASURED
 * ----------------
 * Two windows, both of them inside this boot, and the difference between them is the whole
 * decision the merge and the lock were weighed against:
 *
 *   window   this boot read its copy of the record to the rename that replaced it. This is
 *            the window a *merge* closes, and in a real boot it is the boot itself — the
 *            store probe sits between those two points, and its connect timeout is what the
 *            stage argument stands in for.
 *   hold     the moment this boot's write took the lock to the moment the rename landed.
 *            This is the window the *lock* closes, and it is meant to be the read, the merge
 *            and the write: a lock held across the boot stage would make the boot's own
 *            slowness everyone else's.
 *
 * Both are timed around the *shipped* `lockAcquirer` and `lockReleaser` closures, pulled off a
 * plain instance with reflection rather than reimplemented here, so a change to the mechanism
 * cannot leave this measurement timing something that is no longer the mechanism.
 *
 * WHAT IT LEAVES BEHIND
 * ---------------------
 * One JSON object per line on stdout — the timings, the key this boot recorded, whether that
 * key is in the record afterwards, and how many lines it wrote about the record's own write.
 * Every line the boot *logs* goes to the log file it was given, one JSON object per line, so
 * the parent can tell a boot that merged quietly from a boot that said it could not serialise.
 *
 * Usage: php tests/Support/boot-audit-child.php <record> <log> <start-at> <key> <stage-ms>
 */

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Uak35\WeightedDbManager\Support\BootAudit;
use Uak35\WeightedDbManager\Support\BootAuditFinding;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$arguments = array_slice($argv, 1);

if (count($arguments) !== 5) {
    fwrite(STDERR, "usage: boot-audit-child.php <record> <log> <start-at> <key> <stage-ms>\n");
    exit(2);
}

[$path, $logPath, $startAt, $key, $stageMs] = $arguments;

/**
 * Where this boot's log lines go: recorded for the counts below, and appended to the file the
 * parent reads, one JSON object per line. A boot's diagnostics are not the subject here, only
 * whether they were written — so this is a recorder rather than a Monolog channel.
 */
$log = new class ($logPath) {
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $lines = [];

    public function __construct(private readonly string $file)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $this->lines[] = ['level' => $level, 'message' => $message, 'context' => $context];

        file_put_contents(
            $this->file,
            json_encode(['level' => $level, 'message' => $message, 'context' => $context]).PHP_EOL,
            FILE_APPEND,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }
};

// `BootAudit` logs through the `Log` facade, and this is the whole of the application that
// needs to exist for it: the record is what is shared between boots, not the container.
$container = new Container();
$container->instance('log', $log);
Facade::setFacadeApplication($container);

$shipped = new BootAudit($path, 0);

$acquirer = (new ReflectionProperty(BootAudit::class, 'lockAcquirer'))->getValue($shipped);
$releaser = (new ReflectionProperty(BootAudit::class, 'lockReleaser'))->getValue($shipped);

/** @var array{boot: float, lock: float, rename: float} $times */
$times = ['boot' => 0.0, 'lock' => 0.0, 'rename' => 0.0];

$audit = new BootAudit(
    $path,
    0,
    lockAcquirer: static function (string $lock) use ($acquirer, &$times): array {
        $times['lock'] = microtime(true);

        return $acquirer($lock);
    },
    // Runs in `finally`, so this is the moment the write is done with the record — the rename
    // having landed, and the lock on its way back for the next boot.
    lockReleaser: static function (mixed $handle) use ($releaser, &$times): void {
        $times['rename'] = microtime(true);

        $releaser($handle);
    },
);

// The barrier: every child in a run waits for the same wall-clock instant, so they all read
// the record before any of them has written it. Without it the run would be a queue.
$wait = (float) $startAt - microtime(true);

if ($wait > 0) {
    usleep((int) round($wait * 1_000_000));
}

// The copy this boot decides from...
$times['boot'] = microtime(true);
$stale = $audit->read();

// ...and then the work a real boot does between reading the record and writing it back. The
// store probe is the slow one and its cost is a connect timeout, which is why 0 here is the
// regime where the lock is doing all the work: every boot is in its critical section at once.
$stage = (int) $stageMs;

if ($stage > 0) {
    usleep($stage * 1000);
}

$audit->report($stale, [new BootAuditFinding(
    key: $key,
    warning: sprintf('the %s setting reads as on and cannot act.', $key),
    resolution: sprintf('%s stops reading as on.', $key),
)], checked: [$key]);

$aboutTheRecord = 0;

foreach ($log->lines as $line) {
    if (array_key_exists('kept', $line['context']) || array_key_exists('lock', $line['context'])) {
        $aboutTheRecord++;
    }
}

fwrite(STDOUT, json_encode([
    'key' => $key,
    // The copy this boot decided from to the rename that replaced the record.
    'window_us' => (int) round(($times['rename'] - $times['boot']) * 1_000_000),
    // The lock being taken to the rename landing: the part of the window the lock covers.
    'hold_us' => (int) round(($times['rename'] - $times['lock']) * 1_000_000),
    // True when this boot's own key is in the record after its write, which is what a boot
    // whose write was replaced by a later one would report as false.
    'recorded' => array_key_exists($key, $audit->read()['findings']),
    'lines' => count($log->lines),
    'about_the_record' => $aboutTheRecord,
]).PHP_EOL);
