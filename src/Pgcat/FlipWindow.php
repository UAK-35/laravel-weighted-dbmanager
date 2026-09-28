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
     * The stamp is a bare unix timestamp — `date +%s` — so there is one format to agree on
     * rather than two. A file that exists but does not hold a positive number is reported as
     * absent rather than guessed at.
     */
    public function bootedAt(): ?float
    {
        if ($this->bootFile === '' || !is_file($this->bootFile)) {
            return null;
        }

        $raw = @file_get_contents($this->bootFile);

        if ($raw === false || trim($raw) === '') {
            return null;
        }

        // Whole seconds on disk; anything after the first whitespace-separated token is
        // ignored, so a stamp with a trailing newline or a comment survives.
        $first = preg_split('/\s+/', trim($raw))[0] ?? '';

        if (!is_numeric($first)) {
            return null;
        }

        $booted = (float) $first;

        return $booted > 0 ? $booted : null;
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
}
