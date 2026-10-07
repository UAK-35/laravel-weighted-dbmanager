<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Paths that climb out of this repository, found in the files a commit would carry.
 *
 * WHY THIS EXISTS
 * ---------------
 * A relative path leaks a layout rather than a machine: it resolves in exactly one place — where
 * the checkout happened to sit when the path was written — and it reads as a path everywhere else.
 * That is the half of AGENTS.md's first rule nothing here read. An absolute path at least says
 * whose disk it is; a climb above the root says only "somewhere up there", and it survives every
 * guard this package has: it is not a link, so a docs guard has nothing to resolve, and it names no
 * machine, so `MachinePaths` — the reading of the other half of this rule — has nothing to fire at
 * either. A path like that is found by moving the checkout and fixed by hand, which is the kind of
 * fix that comes back.
 *
 * The difference matters because it is invisible in the reading — a climb reads as a path wherever
 * it is read, and nothing about it says the directory above this one is somebody else's repository
 * rather than this package's own `docs/`.
 *
 * WHAT IS A FINDING, AND WHAT IS NOT
 * ----------------------------------
 * The rule is a walk rather than a shape. Each path token is read from the depth of the file it was
 * written in, and it is a finding only when the walk goes above the root — which is what lets the
 * records keep their own links while a climb written at the root cannot be anything else:
 *
 *   `../RELEASING.md`          in `docs/`           one level up is the root
 *   `../../RELEASING.md`       in `.agents/`        two levels up is the root
 *   `docs/../tests/x.php`      anywhere             a climb walked back before it leaves
 *   `../..`                    at the root          the root, and then past it
 *   `$PROJECT_DIR$/../..`      anywhere             the marker *is* the root, so it leaves at once
 *   `/../../..`                in `tests/Support/`  written as a string, and still a climb
 *   `dirname(__DIR__, 2)`      in `tests/Support/`  two levels up is the root, so this one is not
 *   `dirname(__DIR__, 3)`      in `tests/Support/`  three levels up is past the root, so this one is
 *
 * The string form is why the reading has to start where the string starts rather than at a
 * separator: `__DIR__ . '/../..'` writes the same location, and a token that begins after a `/` is
 * the tail of a path that began earlier. Half a path cannot be walked, so a token preceded by a
 * separator or by the tail of a word is not read at all — which is also what keeps a URL out, since
 * every candidate inside a URL begins mid-path.
 *
 * A computed climb is read too. `dirname(__DIR__, n)` is the same location written in the other
 * language this repository is written in, and `n` is compared against the depth of the file holding
 * it. A guard that can be walked around by rewriting the same location is a guard that will be.
 *
 * WHAT IS DELIBERATELY NOT READ
 * -----------------------------
 * An absolute path — that is `MachinePaths`, the reading of the other half of section 0 — and a path
 * that is not a path at all: a namespace, an escaped namespace in JSON, an ellipsis standing for a
 * directory. None of those is this question, and a reading that reported one would be turned off
 * rather than fixed.
 *
 * @see RepoEscapes::files() for what "the files a commit would carry" means here.
 */
final class RepoEscapes
{
    /**
     * The files this guard is not allowed to report, because the shapes are spelled out in them: a
     * detector can only be shown to fire at a climb by having one in it. Each one is a hole in the
     * guard, so the test asserts that every file here still contains something the guard would
     * otherwise fire at — remove the samples and the exemption fails with them.
     *
     * @var array<string, string>
     */
    public const EXEMPT = [
        'tests/Support/RepoEscapes.php' => 'the patterns and the worked examples that define the shapes',
        'tests/Unit/Support/RepoEscapesTest.php' => 'the samples that prove the detector fires at all',
    ];

    /** The IDE's marker for the root of the project being edited. */
    private const MARKER = '$PROJECT_DIR$';

    /**
     * A relative path token: the marker where it is written, then segments separated by either
     * separator, with an optional leading one.
     *
     * A token is not read where it begins inside a word or after a separator, because that is the
     * middle of a path rather than the start of one — the lookbehind is what stops the reading at
     * the second half of a URL.
     */
    private const TOKEN = '~(?<![A-Za-z0-9_/\\\\])(?:\$PROJECT_DIR\$[\\\\/])?[\\\\/]?[A-Za-z0-9_.@+-]+(?:[\\\\/][A-Za-z0-9_.@+-]+)*~';

    /**
     * A climb computed from the directory of the file that writes it.
     *
     * `dirname(__DIR__)` is not matched on purpose: it is the directory above the file's own, which
     * only leaves the repository when there is nothing left to climb.
     */
    private const DIRNAME = '~dirname\s*\(\s*__DIR__\s*,\s*(\d+)\s*\)~';

    /**
     * Every file a commit would carry, as paths from the root, sorted.
     *
     * The list is `git ls-files`, so it is the repository's answer rather than a walk of the disk:
     * the tracked files, plus the untracked ones the ignore rules do not cover — which is what
     * `git add .` would take, so a file written a minute ago is read before it is committed. What
     * it leaves out is exactly what a commit leaves out: `vendor/`, `composer.phar`, and the
     * machine record, none of which is content this repository ships or vouches for.
     *
     * @return list<string>
     *
     * @throws RuntimeException when git cannot be asked — a scan that read nothing agrees with a
     *                          tree that leaked nothing, so the failure has to be loud.
     */
    public static function files(string $root): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');

        $process = new Process(
            ['git', '-c', 'core.quotepath=false', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'],
            $root,
        );
        $process->setTimeout(120);
        $process->run(null, ['GIT_TERMINAL_PROMPT' => '0', 'GIT_PAGER' => 'cat']);

        if (!$process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                'Could not list the files a commit would carry in %s: %s',
                $root,
                trim($process->getErrorOutput()) ?: 'git said nothing',
            ));
        }

        $found = array_values(array_filter(explode("\0", $process->getOutput()), static fn (string $path): bool => $path !== ''));

        if ($found === []) {
            throw new RuntimeException("git listed no files in {$root}, which is not a tree to read");
        }

        sort($found);

        return $found;
    }

    /**
     * What a scan of a repository finds, what it read, and what it set aside.
     *
     * `files` is returned rather than counted so a caller can prove the listing is the real one —
     * the files the samples live in have to be in it — and `exempt` is returned rather than dropped
     * so a caller can prove the exemptions are still earning their place.
     *
     * @return array{
     *     files: list<string>,
     *     found: list<array{file: string, line: int, path: string}>,
     *     exempt: list<array{file: string, line: int, path: string}>
     * }
     */
    public static function scan(string $root): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $files = self::files($root);
        $found = [];
        $exempt = [];

        foreach ($files as $file) {
            // A file that is tracked but gone from the working tree has nothing to leak.
            $contents = @file_get_contents($root . '/' . $file);

            if ($contents === false || self::binary($contents)) {
                continue;
            }

            // Where a relative path is relative to: the directory holding the file, counted from
            // the root, so `PUSHING.md` starts at 0 and a record in `docs/` starts at 1.
            $depth = substr_count($file, '/');

            foreach (self::in($contents, $depth) as $hit) {
                $record = ['file' => $file, 'line' => $hit['line'], 'path' => $hit['path']];

                isset(self::EXEMPT[$file]) ? $exempt[] = $record : $found[] = $record;
            }
        }

        return ['files' => $files, 'found' => $found, 'exempt' => $exempt];
    }

    /**
     * Every path in one text that climbs out of the repository, with the line it is on.
     *
     * `$depth` is how many directories below the root the file holding the text sits, because that
     * is what a relative path is relative to: the README is 0, `docs/env-types.md` is 1, and
     * `tests/Support/Docs.php` is 2.
     *
     * @return list<array{line: int, path: string}>
     */
    public static function in(string $text, int $depth): array
    {
        $found = [];

        // An array rather than a line number counter, so a text with a mixture of endings is split
        // the same way the rest of the suite splits one.
        foreach (array_values(preg_split('/\R/', $text) ?: []) as $index => $line) {
            foreach (self::matches($line, $depth) as $path) {
                $found[] = ['line' => $index + 1, 'path' => $path];
            }
        }

        return $found;
    }

    /**
     * Whether a file is text this can read: the same test git makes before it diffs one, asked of
     * `MachinePaths` rather than kept here. What a commit would carry and what a text is are one
     * decision each, and a file is text whichever of the two readings is asking.
     */
    private static function binary(string $contents): bool
    {
        return MachinePaths::binary($contents);
    }

    /**
     * The climbs in one line, in the order they are written.
     *
     * @return list<string>
     */
    private static function matches(string $line, int $depth): array
    {
        $found = [];

        if (preg_match_all(self::TOKEN, $line, $hits) > 0) {
            foreach ($hits[0] as $token) {
                if (self::leaves($token, $depth)) {
                    $found[] = $token;
                }
            }
        }

        if (preg_match_all(self::DIRNAME, $line, $hits) > 0) {
            // preg_match_all() leaves `$hits` without the capture group at all when there is
            // nothing to put in it, and the suite fails on the notice that reading it would raise.
            if ($hits[1] === []) {
                return $found;
            }

            foreach ($hits[1] as $index => $levels) {
                // `__DIR__` is the depth of the file's own directory, so `dirname(__DIR__, n)` is n
                // levels above it — and the root is where that count reaches zero.
                if ((int) $levels <= $depth) {
                    continue;
                }

                $found[] = $hits[0][$index];
            }
        }

        return $found;
    }

    /**
     * Whether a token walks above the root, read from the depth of the file that wrote it.
     *
     * A segment that is `..` climbs, an empty one or a `.` stands still, and anything else
     * descends. The walk stops the moment it is above the root, so nothing past the escape is
     * reasoned about — and the marker starts the walk at the root rather than at the file, because
     * a marker stands for the root wherever it is written.
     */
    private static function leaves(string $token, int $depth): bool
    {
        $fromRoot = str_starts_with($token, self::MARKER);
        $level = $fromRoot ? 0 : $depth;

        foreach (preg_split('~[\\\\/]~', $token) ?: [] as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            $level += $segment === '..' ? -1 : 1;

            if ($level < 0) {
                return true;
            }
        }

        return false;
    }
}
