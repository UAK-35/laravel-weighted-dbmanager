<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Pgcat;

use Uak35\WeightedDbManager\Support\ConfigValue;

/**
 * How long this container is allowed to get pgcat into a working shape.
 *
 * The window exists because a pooler that never comes up and a pooler that comes up
 * late look identical from inside the application, and only one of them is worth
 * waiting for. A container that cannot serve reads within a few minutes of start is
 * not going to: the flip has run on every one of those minutes, and one more pass
 * will not find a different answer. So the attempts are bounded, and the bound is the
 * thing `/health/db` reports against afterwards.
 *
 * WHERE THE CLOCK STARTS
 *   The container's own boot, stamped by `entrypoint.sh` into a file whose path both
 *   sides read from configuration — deliberately not "when this process started",
 *   which is a different moment for every Octane worker and every queue worker, and
 *   not "when the first flip ran", which cannot happen at all if the scheduler is the
 *   thing that is broken. The stamp is written before pgcat is started, so it is
 *   present even in the failure this window exists to catch.
 *
 * WHERE THE WINDOW COMES FROM
 *   `db-manager.swrr.pgcat.flip_window_seconds`, default {@see self::DEFAULT_SECONDS}.
 *   With no stamp to read the window is *not* judged: `closed()` is false and every
 *   derived field is null. That is deliberate rather than a fallback to "now", because
 *   a local run or a container whose entrypoint predates this would otherwise grow a
 *   10-minute deadline it was never meant to have, and start failing a health endpoint
 *   over a fact nobody recorded. Silence is the correct answer to "when did this
 *   container boot" when nothing knows.
 *
 * The clock is injected so a test does not wait eight minutes to see the window close.
 */
final class FlipWindow
{
    /**
     * Eight minutes.
     *
     * CHANGE THE WINDOW HERE, OR IN `config/db-manager.php`
     *   This is the authoritative default. The operator-facing copy is
     *   `swrr.pgcat.flip_window_seconds` in `config/db-manager.php` (the `'pgcat'` block
     *   of the `'swrr'` array), which reads `SWRR_PGCAT_FLIP_WINDOW_SECONDS` — set that
     *   env var to raise or lower it without touching code. Nothing else reads this
     *   number: the flipper, `db:pgcat-flip --status` and `/health/db` all go through
     *   this class, so changing it in one of those two places changes it everywhere.
     *
     * The reasoning behind the default, in the terms the number was chosen in:
     *   - A healthy container is up and reported healthy within 6–7 minutes of task
     *     start, so the window must not close before that. Eight clears it by a minute
     *     or two rather than by nothing.
     *   - The flip runs every minute, so eight is eight attempts — the 7 or 8 a failing
     *     container gets, the first landing only seconds after the pooler is even
     *     startable. A flip that is going to converge converges on the first or second
     *     attempt, so the later attempts cost nothing and buy the slow-but-working case
     *     its time.
     *   - By the end of it the container has either flipped to the writer-only mode — the
     *     database instance that is always up — or the pooler cannot be made to serve at
     *     all. One more attempt cannot tell those apart, which is exactly why the window
     *     is where the trying stops and the verdict begins.
     */
    public const DEFAULT_SECONDS = 480;

    /** @var \Closure(): float */
    private \Closure $clock;

    /**
     * @param string        $bootFile the file `entrypoint.sh` stamps the container's boot time into
     * @param int|null      $seconds  the window, or null/absent for {@see self::DEFAULT_SECONDS}
     * @param \Closure|null $clock    returns the current unix time in seconds, fractional allowed
     */
    public function __construct(
        private readonly string $bootFile,
        private readonly ?int $seconds = null,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * @param array<string, mixed> $config the `db-manager.swrr.pgcat` block
     */
    public static function fromConfig(array $config, ?\Closure $clock = null): self
    {
        return new self(
            bootFile: ConfigValue::string($config['boot_file'] ?? null),
            seconds: ConfigValue::int($config['flip_window_seconds'] ?? null, self::DEFAULT_SECONDS),
            clock: $clock,
        );
    }

    /**
     * The window in seconds. A configured zero or negative is refused and replaced by the
     * default: "no window" would silently turn off the one check that notices the flip never
     * ran, and an operator who wants that should turn `swrr.pgcat.enabled` off, which says so.
     */
    public function seconds(): int
    {
        return $this->seconds !== null && $this->seconds > 0 ? $this->seconds : self::DEFAULT_SECONDS;
    }

    public function bootFile(): string
    {
        return $this->bootFile;
    }

    public function now(): float
    {
        return ($this->clock)();
    }

    /**
     * When this container booted, or null when nothing recorded it.
     *
     * The stamp is a bare unix timestamp - `date +%s` - so there is one format to agree on
     * rather than two. A file that exists but does not hold a positive number is reported as
     * absent rather than guessed at.
     *
     * This is the one reading both the window and the diagnostics use: `stampProblem()` reads
     * the same result and says *why* there is no boot time, so a readable-but-unwritable file
     * cannot be reported as one the package could not use, and an empty file cannot pass.
     */
    public function bootedAt(): ?float
    {
        return $this->readStamp()['booted'];
    }

    /**
     * The one read of the configured stamp, classified.
     *
     * `state` is null when a usable positive timestamp was read, and one of the STAMP_*
     * constants otherwise. `bootFile === ''` is *unconfigured* rather than missing: it is the
     * documented opt-out, so it reads as "nothing recorded" without being a fault.
     *
     * A `file_get_contents()` that fails after `is_readable()` said yes is reported as
     * `unreadable` rather than allowed to warn or throw: a race or an ACL is a diagnostic, and
     * a boot must never be stopped by one.
     *
     * @return array{state: string|null, raw: string|null, booted: float|null}
     */
    private function readStamp(): array
    {
        if ($this->bootFile === '') {
            return ['state' => null, 'raw' => null, 'booted' => null];
        }

        if (!is_file($this->bootFile)) {
            return ['state' => self::STAMP_MISSING, 'raw' => null, 'booted' => null];
        }

        if (!is_readable($this->bootFile)) {
            return ['state' => self::STAMP_UNREADABLE, 'raw' => null, 'booted' => null];
        }

        $raw = @file_get_contents($this->bootFile);

        if ($raw === false) {
            return ['state' => self::STAMP_UNREADABLE, 'raw' => null, 'booted' => null];
        }

        if (trim($raw) === '') {
            return ['state' => self::STAMP_EMPTY, 'raw' => $raw, 'booted' => null];
        }

        // Whole seconds on disk; anything after the first whitespace-separated token is
        // ignored, so a stamp with a trailing newline or a comment survives.
        $first = preg_split('/\s+/', trim($raw))[0] ?? '';

        if (!is_numeric($first)) {
            return ['state' => self::STAMP_UNPARSEABLE, 'raw' => $raw, 'booted' => null];
        }

        $booted = (float) $first;

        return $booted > 0
            ? ['state' => null, 'raw' => $raw, 'booted' => $booted]
            : ['state' => self::STAMP_UNPARSEABLE, 'raw' => $raw, 'booted' => null];
    }

    /**
     * Why the configured stamp is not a usable boot time, or null when it is.
     *
     * The four states are the plan's: a path that is absent or not a regular file (`missing`), a
     * file this process cannot read (`unreadable`), a file whose contents are empty or only
     * whitespace (`empty`), and non-empty contents without a usable timestamp (`unparseable`).
     * A readable stamp is valid whether or not the application may write it, so writability is
     * never part of the answer.
     *
     * `reason` is a fragment the caller puts in its own sentence; it never says the package will
     * create or re-stamp the file, because the application must not.
     *
     * @return array{state: string, reason: string}|null
     */
    public function stampProblem(): ?array
    {
        $reading = $this->readStamp();

        if ($reading['state'] === null) {
            return null;
        }

        return [
            'state' => $reading['state'],
            'reason' => match ($reading['state']) {
                self::STAMP_MISSING => 'the file is not there, or the path does not name a regular file',
                self::STAMP_UNREADABLE => 'the file exists but this process cannot read it',
                self::STAMP_EMPTY => 'the file is empty or holds only whitespace, so no boot time was recorded',
                default => 'the contents do not start with a positive unix timestamp',
            },
        ];
    }

    public function deadline(): ?float
    {
        $booted = $this->bootedAt();

        return $booted === null ? null : $booted + $this->seconds();
    }

    /**
     * Whether the window has closed. False when there is no stamp: see the class docblock.
     */
    public function closed(): bool
    {
        $deadline = $this->deadline();

        return $deadline !== null && $this->now() >= $deadline;
    }

    public function remaining(): ?float
    {
        $deadline = $this->deadline();

        return $deadline === null ? null : round($deadline - $this->now(), 3);
    }

    /**
     * Whether a given moment falls inside this container's window.
     *
     * This is how "did the flip converge in time" is answered: the flipper records when it
     * last reached a usable mode, and the answer is that moment being inside these bounds.
     * Asked afterwards it does not change, which is why a container whose window closed
     * stays judged and a container that converged stays passed.
     */
    public function contains(float $moment): bool
    {
        $booted = $this->bootedAt();

        return $booted !== null && $moment >= $booted && $moment <= $booted + $this->seconds();
    }

    /**
     * The whole window as one block, for `--status`, `--json` and `/health/db`.
     *
     * `source` is the field that stops a reader mistaking "no window was judged" for "the
     * window passed": `stamped` means the container's boot time was read, `no_stamp` means
     * nothing recorded it, so `closed` is false because there is nothing to compare against
     * rather than because time is on the container's side.
     *
     * @return array{
     *   boot_file: string,
     *   source: string,
     *   booted_at: string|null,
     *   booted_at_unix: float|null,
     *   window_seconds: int,
     *   deadline: string|null,
     *   deadline_unix: float|null,
     *   remaining_seconds: float|null,
     *   closed: bool,
     * }
     */
    public function toArray(): array
    {
        $booted = $this->bootedAt();
        $deadline = $this->deadline();

        return [
            'boot_file' => $this->bootFile,
            'source' => $booted === null ? self::SOURCE_NO_STAMP : self::SOURCE_STAMPED,
            'booted_at' => $booted === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int) $booted),
            'booted_at_unix' => $booted,
            'window_seconds' => $this->seconds(),
            'deadline' => $deadline === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int) $deadline),
            'deadline_unix' => $deadline,
            'remaining_seconds' => $this->remaining(),
            'closed' => $this->closed(),
        ];
    }

    public const SOURCE_STAMPED = 'stamped';

    public const SOURCE_NO_STAMP = 'no_stamp';

    /**
     * The four ways a configured stamp can be unusable, as `stampProblem()` reports them and as
     * the audit finding's `state` context carries them. A readable stamp is valid whether or not
     * the application may write it, so there is no `unwritable` state.
     */
    public const STAMP_MISSING = 'missing';

    public const STAMP_UNREADABLE = 'unreadable';

    public const STAMP_EMPTY = 'empty';

    public const STAMP_UNPARSEABLE = 'unparseable';
}
