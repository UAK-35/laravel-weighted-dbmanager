<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Pgcat;

use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * The three things a silent schedule is checked against, each reported as the reading it is.
 *
 * WHY A VERDICT IS NOT ENOUGH
 *   `ScheduleBeat` answers whether the per-minute entry is producing runs, and its sentence names
 *   the cause that is nearly always the right one: the container's cron, or `schedule:work`, is not
 *   running the entry. It is not the only cause, and the ones it cannot see are the ones that make a
 *   stopped schedule look like a container that never started:
 *
 *   - **The store the runs' overlap mutexes live in.** `withoutOverlapping()` reaches it *before*
 *     the entry runs, and a store that does not answer throws there — so an installation whose
 *     store is unreachable logs an exception every minute and stamps no beat, which is a schedule
 *     that is dead while its cron is perfectly healthy. The throw is inside `schedule:run` rather
 *     than inside the entry, so the entries after it in the same run do not run either.
 *   - **How many entries run this package's command.** Two registrations — the installation's own,
 *     from the snippet the README used to hand out, beside the one the provider adds — are two runs
 *     a minute, and the framework's mutex cannot serialise them: each entry names its own mutex
 *     (`withoutOverlapping()` derives it from the expression and the command, and this package adds
 *     a per-container suffix to one of them). The flipper's own `flock` is what makes the second one
 *     `skipped`, so the cost is a doubled process rather than a doubled flip, and `schedule:list`
 *     describing two things where an operator expects one.
 *   - **The file the boot window is measured from.** `FlipWindow::bootedAt()` reports a boot stamp
 *     it cannot *read* exactly as it reports one that was never written — both are `null` — so a
 *     container whose stamp is unreadable is a container that was never stamped: the window is not
 *     judged, and the beat inside it is not judged either. A schedule that stopped in such a
 *     container is reported as one nobody asked about.
 *
 * WHAT THESE ARE, AND WHAT THEY ARE NOT
 *   Each is a reading — the store's name and whether it answered, the two counts, the file's
 *   existence with its mode and owner — attached to whatever verdict the beat reached, and none of
 *   them moves an exit code. That is deliberate rather than timid: "the schedule has stopped" and "a
 *   store did not answer once" are different claims, and the second is already a row of its own
 *   (`db:doctor`'s `store`), so a report that failed on it here would be one fact failing two rows
 *   for different reasons. What these do is make a silence diagnosable: an operator who reads
 *   `stale` gets the three readings beside it instead of three commands to run.
 *
 * NOTHING IS WRITTEN, AND AN UNARMED INSTALLATION IS NOT ASKED
 *   The mutex store is asked for a **read** of a key no run can hold — a report that a deploy
 *   pipeline prints claims nothing is written, and taking a trial lock to prove reachability would
 *   be exactly what that claim forbids. The store's own answer is the evidence, and a store that
 *   cannot be reached throws on the connect.
 *
 *   The read is the one thing here that leaves the process, so it is asked only where a run is
 *   allowed to reach out, and a caller that is not passes `$unasked`: `db:doctor` asks when it is
 *   judging a running installation and not when `--connection-only` has bounded it to what a build
 *   container can answer, and `db:scheduler-watchdog` does not ask at all — a healthcheck that runs
 *   every minute answers from the state file the flip wrote, and putting a store's connect timeout
 *   on that check is how a container gets restarted for its cache. Neither surface is silent about
 *   the omission: the reading says the store was not asked and which run was going to ask it, so
 *   "nothing to report" and "not asked" cannot be read as each other.
 *
 *   An installation whose per-minute entry is not scheduled reads nothing at all: `readings()` is
 *   empty, and the container's schedule is never resolved. That is a property rather than an
 *   optimisation, because resolving the schedule is what *registers* this package's entry — the
 *   provider does it `afterResolving` — so a report about an installation that never armed the
 *   schedule would otherwise hand it one it never asked for.
 *
 * KNOWN LIMITATIONS
 *   - **The readings are taken once, in the process that asked.** A store that answers here and
 *     fails a minute later is reported as reachable, which is all a reading can say.
 *   - **The readings are this user's.** Mode, owner and reachability are read as whoever ran the
 *     command, which is the schedule's own user only when the schedule runs as that user — the
 *     `/var/swrr` contract. An operator running `db:doctor` as root reads root's answer.
 *   - **The entry count is of the schedule this process built.** An installation that registers its
 *     own entry somewhere this process never evaluates — a second schedule in another service
 *     container — is counted by that container's own report and not by this one's.
 */
final class ScheduleCauses
{
    /** The store the entry's overlap mutexes live in, and whether it answered a read. */
    public const KEY_MUTEX_STORE = 'mutex_store';

    /** How many entries run this package's command, and how many carry the installation's name. */
    public const KEY_ENTRY = 'schedule_entry';

    /** The file the boot window is measured from: whether it is there, readable and writable. */
    public const KEY_BOOT_STAMP = 'boot_stamp';

    /**
     * The key the mutex store is asked for.
     *
     * A name no run can hold — {@see FlipSchedule::mutexName()} completes a run's own name with the
     * container's hostname — so this cannot collide with a mutex some run is holding, and it is
     * only ever read.
     */
    private const PROBE_KEY = 'framework/swrr-schedule-mutex-probe';

    /** @var list<array{key: string, reading: string}>|null */
    private ?array $readings = null;

    /**
     * @param bool          $armed    whether the per-minute entry is scheduled; false reads nothing
     * @param string        $name     the name the entry carries, `swrr.pgcat.schedule.name`
     * @param string        $bootFile the file the boot window is measured from
     * @param Schedule|null $schedule the schedule this boot built, or null when there is none to read
     * @param string|null   $failure  why no schedule could be built, when that is why there is none
     * @param string|null   $unasked  why this run does not ask the mutex store, or null to ask it
     */
    public function __construct(
        private readonly bool $armed,
        private readonly string $name,
        private readonly string $bootFile,
        private readonly ?Schedule $schedule = null,
        private readonly ?string $failure = null,
        private readonly ?string $unasked = null,
    ) {
    }

    /**
     * The readings, taken from the container that will run the entry.
     *
     * The schedule is resolved here and not passed in, because resolving it is what registers this
     * package's entry: a caller that had already resolved it — the console kernel does, when it
     * loads the application's own scheduling code — gets the schedule that was registered against,
     * and one that has not gets the same shape `schedule:run` would build. Both are the reading the
     * question is about.
     *
     * A container that cannot build a schedule (no cache binding, no console component) is not a
     * failure of this reading: the two schedule-derived readings say what they could not read, which
     * is the honest answer and one an operator can act on.
     */
    public static function fromContainer(
        Container $container,
        bool $armed,
        string $name,
        string $bootFile,
        ?string $unasked = null,
    ): self {
        if (! $armed) {
            return new self(false, $name, $bootFile);
        }

        try {
            return new self(true, $name, $bootFile, $container->make(Schedule::class), null, $unasked);
        } catch (Throwable $e) {
            return new self(true, $name, $bootFile, null, $e->getMessage(), $unasked);
        }
    }

    /**
     * What was read, in the order the questions are asked — empty when nothing was.
     *
     * Memoised, because two surfaces read one of these twice: the object goes into a JSON report
     * *and* into the sentence beside it, and a second read of a store is a second answer to one
     * question.
     *
     * @return list<array{key: string, reading: string}>
     */
    public function readings(): array
    {
        if ($this->readings !== null) {
            return $this->readings;
        }

        if (! $this->armed) {
            return $this->readings = [];
        }

        return $this->readings = [
            ['key' => self::KEY_MUTEX_STORE, 'reading' => $this->mutexStoreReading()],
            ['key' => self::KEY_ENTRY, 'reading' => $this->entryReading()],
            ['key' => self::KEY_BOOT_STAMP, 'reading' => $this->bootStampReading()],
        ];
    }

    /**
     * The readings as one sentence, for a row's detail or a healthcheck's line — empty when there
     * are none, so a caller can append it without asking whether there is anything to append.
     */
    public function sentence(): string
    {
        $readings = $this->readings();

        if ($readings === []) {
            return '';
        }

        return 'The schedule\'s own readings: '.implode('; ', array_column($readings, 'reading')).'.';
    }

    /**
     * The store this schedule's overlap mutexes live in, and whether it answered a read.
     *
     * Read off the *schedule's own mutex* rather than off the cache configuration, because the two
     * are not the same object: an installation that has decided its scheduled tasks share one store
     * calls `Schedule::useCache('file')`, which reaches the mutex object and leaves the application's
     * default alone. A report that read the default would name a store the entry never touches.
     *
     * A schedule whose mutex is the installation's own implementation — an `EventMutex` bound in the
     * container, which needs no store — is reported as just that: there is nothing here to ask, and
     * saying so is more useful than naming a store the mutex does not use.
     */
    private function mutexStoreReading(): string
    {
        if ($this->schedule === null) {
            return $this->noSchedule('the store its runs\' mutexes live in was not read');
        }

        $event = $this->schedule->events()[0] ?? null;

        // A schedule with no entry on it has no mutex to read, which is a state of its own: the
        // number of entries running the command is the next reading, and an empty schedule is what
        // it reports — this says only that there was nothing here to ask.
        if ($event === null) {
            return 'the schedule carries no entry yet, so the store its runs\' mutexes would live in '
                .'is not known';
        }

        $mutex = $event->mutex;

        if (! $mutex instanceof CacheEventMutex) {
            return 'the schedule\'s overlap mutex is not the cache-backed one, so no store is asked: an '
                .'installation that binds its own '.EventMutex::class.' decides this for itself';
        }

        // What the installation named, before the store is resolved: the resolved repository can name
        // the store more precisely, but a resolution that throws must leave a label behind for the
        // sentence that reports the throw.
        $label = $this->storeLabel(null, $mutex->store);

        if ($this->unasked !== null) {
            return sprintf(
                'the store its runs\' mutexes live in (%s) was not asked: %s',
                $label,
                $this->unasked,
            );
        }

        try {
            $repository = $mutex->cache->store($mutex->store);

            if ($repository instanceof CacheRepository) {
                $label = $this->storeLabel($repository->getName(), $mutex->store);
            }

            // The store, not the repository: `get()` is on the store contract, and the store is the
            // object a lock is taken on — a file store's directory, a Redis connection, a database
            // table. A store that cannot be reached throws here.
            $repository->getStore()->get(self::PROBE_KEY);
        } catch (Throwable $e) {
            return sprintf(
                'the store its runs\' mutexes live in (%s) did not answer: %s — the entry throws on '
                .'`withoutOverlapping()` before it reaches the flip, and because that throw is inside '
                .'`schedule:run` the entries after it do not run either, so a schedule can be dead '
                .'while its cron is healthy',
                $label,
                $e->getMessage(),
            );
        }

        return sprintf('the store its runs\' mutexes live in (%s) answered a read', $label);
    }

    /**
     * How many entries on the built schedule run `db:pgcat-flip`, and how many carry the name.
     *
     * The three shapes the two counts separate are {@see FlipSchedule::entries()}'s, and the
     * sentence for the third is the one worth having: two entries are two runs a minute, and the
     * framework cannot prevent it.
     */
    private function entryReading(): string
    {
        if ($this->schedule === null) {
            return $this->noSchedule('the entries that would run '.FlipSchedule::COMMAND.' were not counted');
        }

        $entries = FlipSchedule::entries($this->schedule, $this->name);
        $quoted = '`'.$this->name.'`';

        if ($entries['total'] === 0) {
            return sprintf(
                'no entry on the schedule runs %s, so a run of `schedule:run` would find nothing to '
                .'fire for this package — which is what a schedule built before the entry was armed '
                .'looks like',
                FlipSchedule::COMMAND,
            );
        }

        if ($entries['total'] === 1 && $entries['named'] === 1) {
            return sprintf('1 entry carries %s — the per-minute entry this package registers', $quoted);
        }

        if ($entries['total'] === 1) {
            return sprintf(
                '1 entry runs %s and none carries %s: the installation registered its own, and this '
                .'package added none beside it — that entry\'s name and log are the installation\'s',
                FlipSchedule::COMMAND,
                $quoted,
            );
        }

        return sprintf(
            '%d entries run %s, %d of them carrying %s: two registrations are two runs a minute, and '
            .'the framework\'s mutex cannot serialise them because each entry names its own — the '
            .'flipper\'s lock is what makes the second `skipped`, so the doubled work is a second '
            .'process rather than a second flip. Keep one',
            $entries['total'],
            FlipSchedule::COMMAND,
            $entries['named'],
            $quoted,
        );
    }

    /**
     * The file the window is measured from: there, readable, writable, and what it was found with.
     *
     * The distinction this exists for is between *absent* and *unreadable*, which the window itself
     * cannot make: `bootedAt()` answers `null` to both, and the silence that follows is the same
     * sentence for a container nobody stamped and for one whose stamp belongs to another account.
     * Only the second is a fault, and only here is it named as one.
     */
    private function bootStampReading(): string
    {
        if ($this->bootFile === '') {
            return 'no boot stamp path is configured (swrr.pgcat.boot_file is empty), so the window '
                .'is measured from nothing on every boot';
        }

        if (! file_exists($this->bootFile)) {
            return sprintf(
                'there is no boot stamp at %s, which is the file the window is measured from: a local '
                .'run, or an image whose entrypoint predates the stamp — the window is not judged and '
                .'neither is the beat inside it',
                $this->bootFile,
            );
        }

        $mode = PgcatConfigFlipper::facts($this->bootFile);

        if (! is_readable($this->bootFile)) {
            return sprintf(
                'the boot stamp %s is there and cannot be read by this process%s, so this boot is '
                .'judged as a container that never stamped a file: the window never closes, the beat '
                .'inside it is never judged, and a schedule that has stopped is reported as one '
                .'nobody asked about — grant it to the user the schedule runs as, or re-stamp it '
                .'where that user can read it',
                $this->bootFile,
                $mode === null ? '' : ' ('.$mode.')',
            );
        }

        return sprintf(
            'the boot stamp %s is readable%s%s',
            $this->bootFile,
            is_writable($this->bootFile) ? ' and writable' : ' and not writable',
            $mode === null ? '' : ' ('.$mode.')',
        );
    }

    /**
     * What the two schedule-derived readings say when the container built no schedule at all.
     *
     * Each names its own subject, because the two questions are different ones and a reader who sees
     * one absence should be able to tell that the other was asked and not answered the same way.
     */
    private function noSchedule(string $subject): string
    {
        return sprintf(
            'this container built no schedule to read, so %s (%s)',
            $subject,
            $this->failure ?? 'the container has no schedule binding',
        );
    }

    /**
     * The mutex store as prose: the resolved store's own name when it has one, the configured name
     * when it does not, and the installation's default when the schedule names no store at all.
     *
     * The resolved name is preferred because the two differ in the case worth reporting: an
     * installation that named no store is using whatever `cache.default` is, and "the default" tells
     * a reader nothing they can act on, while `redis` or `file` tells them where to look.
     */
    private function storeLabel(?string $resolved, ?string $configured): string
    {
        if (is_string($resolved) && $resolved !== '') {
            return $resolved;
        }

        return is_string($configured) && $configured !== ''
            ? $configured
            : 'the installation\'s default cache store';
    }
}
