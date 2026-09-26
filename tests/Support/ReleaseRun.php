<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Support;

/**
 * What one run of a `bin/` script did: its exit code and the two streams, kept apart
 * on purpose.
 *
 * Written for `bin/release.php` — hence `plan()` — and used for the other scripts too,
 * because the same two rules hold for all of them: the report goes to stdout and a refusal
 * goes to stderr, and a test that asserts on the wrong one passes for the wrong reason — a
 * release that planned the wrong version still prints a plan. So the two are never merged,
 * and `plan()` reads the value of a named line out of a plan instead of matching its spacing.
 */
final class ReleaseRun
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output,
        public readonly string $error,
    ) {
    }

    /**
     * The value of one line of the plan, e.g. `plan('bump')` → `patch  (weighed: a
     * patch change)`. Empty when the plan never got as far as printing the line —
     * which is itself the answer to a precondition that refused first.
     */
    public function plan(string $key): string
    {
        $pattern = '/^\s+' . preg_quote($key, '/') . '\s{2,}(.+)$/m';

        if (preg_match($pattern, $this->output, $match) !== 1) {
            return '';
        }

        return trim($match[1]);
    }

    /** Something the run said on stdout — a note, or the weighing. */
    public function said(string $needle): bool
    {
        return str_contains($this->output, $needle);
    }

    /** Something the run refused with, or reported about itself, on stderr. */
    public function refused(string $needle): bool
    {
        return str_contains($this->error, $needle);
    }

    /** How many times something appears in one stream — for "exactly three bullets". */
    public function occurrences(string $needle, bool $onError = false): int
    {
        return substr_count($onError ? $this->error : $this->output, $needle);
    }

    /** The whole run, for the message of a failed assertion. */
    public function describe(): string
    {
        return sprintf(
            "exit %d\n--- stdout ---\n%s\n--- stderr ---\n%s",
            $this->exitCode,
            $this->output,
            $this->error,
        );
    }
}
