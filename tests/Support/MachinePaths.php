<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Support;

/**
 * Paths that only resolve on the machine they were written on, found in the files a commit would
 * carry.
 *
 * WHY THIS EXISTS
 * ---------------
 * `AGENTS.md` section 0 has three clauses about place — no directory above the root, no sibling
 * checkout, no home directory, other drive or network share — and only one of them was read. The
 * climb is the one `RepoEscapes` walks, and its own header names this half as the half it does not
 * read, because an absolute path is a different question: a climb leaks a *layout* ("somewhere up
 * there"), where an absolute path leaks a *machine* ("on my disk, under my account"). Neither is
 * visible in review, and the second one reads exactly like a path, which is why a line like
 * `cd E:\workspace\project` survives a diff: it is a path wherever it is read, and it resolves for
 * one person.
 *
 * Two of these have been found in this package and removed by hand: the `cd` lines the runbooks
 * opened with, naming a workspace on this disk, and the environment table of `HANDOFF.md`, which
 * recorded the path of this machine's interpreter. A hand edit is the kind of fix that comes back,
 * and nothing here would have caught it coming back — so the reading is `scan()` and `in()` below,
 * and the tree is held to it by `tests/Unit/Support/MachinePathsTest.php`.
 *
 * WHAT IS A FINDING, AND WHAT IS NOT
 * ----------------------------------
 * A path is a finding when it names a machine or the person sitting at it — not when it is merely
 * absolute, which is why `/usr/local/bin/php` is left alone and this rule is written around what a
 * path names rather than around how it is spelled. These name somebody:
 *
 *   C:/Users/<name>/project     a drive letter and the path after it
 *   E:\workspace\project        the same, back-slashed, which is how a Windows recipe writes it
 *   E:\\workspace\\project      the same again in PHP source, where each backslash is escaped
 *   /home/<name>/.gitconfig     an absolute home directory
 *   /Users/<name>/.gitconfig    the same, as macOS spells it
 *   /e/workspace/project        the same drive as a POSIX shell in this workspace reaches it —
 *   /mnt/e/workspace/project    `/e/...`, `/mnt/e/...` (WSL) and `/cygdrive/e/...` (Cygwin), which
 *                               is where a rewrite *out* of a drive letter lands, and which reads
 *                               as a path rather than as the leak it still is
 *   \\fileserver\share          a network share, which resolves on one network
 *
 * and these name nobody:
 *
 *   C:/Windows/System32/...     the same directory on every Windows install
 *   C:/Program Files/...        the install root of a program: the same tree on every machine that
 *                               has it installed. Two files here name one on purpose — the CA
 *                               bundle `bin/composer-ca.ps1` reads out of Git for Windows, and the
 *                               `gitconfig` the evidence table in `PUSHING.md` quotes — so the
 *                               tolerance is the reason those lines can stay as they are
 *   /home/runner/work/pkg       the same on every GitHub runner, for the same reason
 *   ~/.gitconfig                the portable way to write a home directory
 *   $PROJECT_DIR$/vendor/bin    the IDE's marker for the root of the checkout being edited
 *   C:\…                        an ellipsis standing for the rest of a path — a writer saying the
 *                               tail is not to be read — leaves nothing after the root, and a root
 *                               is nobody's: `C:\` is the same on every Windows machine
 *
 * A path is read a segment at a time, and a segment may carry a space — `Program Files` is
 * one directory — but only where the path continues past it, because that is the only place a
 * space can be told from the words that follow a path in a sentence: the reading of
 * `C:\bin\supervisorctl.exe carries real separators` ends at the `.exe`. That is also what
 * keeps the ellipsis above out of the drive rule without a second rule for it — the segment
 * after the separator would have to be a name, and `…` is not one.
 *
 * WHY THE LIST COMES FROM GIT
 * ---------------------------
 * `RepoEscapes::files()` is called rather than a second listing kept here: it is this package's one
 * answer to "the files a commit would carry" — every tracked file, plus every untracked one the
 * ignore rules do not cover, so a path copied out of a terminal a minute ago is read before it is
 * committed, and `vendor/`, the machine record and everything else this package has decided is
 * local are absent. Two listings could come to disagree about that decision without either one
 * noticing, which is the failure a second copy is bought with.
 *
 * It throws rather than reporting nothing when git cannot be asked, which is `files()`' answer to
 * the same question. A guard whose answer to "did you read anything?" is "no" is worth less than
 * no guard at all.
 */
final class MachinePaths
{
    /**
     * The two files this guard may not report, because the shapes are spelled out in them: a
     * detector can only be shown to fire at a path by having one in it. Each one is a hole in the
     * guard, so the test asserts that every file here still contains something the guard would
     * otherwise fire at — remove the samples and the exemption fails with them.
     *
     * @var array<string, string>
     */
    public const EXEMPT = [
        'tests/Support/MachinePaths.php' => 'the patterns and the worked examples that define the shapes',
        'tests/Unit/Support/MachinePathsTest.php' => 'the samples that prove the detector fires at all',
    ];

    /**
     * One name in a path.
     *
     * Every quantifier here is possessive, which changes nothing about what these patterns match and
     * everything about what they cost: a text whose runs of name characters are followed by no
     * separator at all — a minified line, a table of escapes — is otherwise walked exponentially,
     * and a guard that takes minutes on one file is a guard that gets skipped rather than fixed.
     */
    private const NAME = '[A-Za-z0-9_.-]++';

    /**
     * A name with the space a directory name may carry — `Program Files` is one directory.
     *
     * It is never the last segment, which is the whole point of writing it separately: a space can
     * only be told from the words that follow a path in a sentence where the path continues past
     * it, so `C:\bin\supervisorctl.exe carries real separators` ends at the `.exe` while
     * `C:/Program Files/Git/etc/gitconfig` does not end at `Program`.
     */
    private const SPACED = '[A-Za-z0-9_.-]++(?: [A-Za-z0-9_.-]++)*+';

    /** One separator, or the two a backslash becomes when it is written in PHP source. */
    private const SEPARATOR = '[\\\\/]{1,2}+';

    /**
     * A drive letter and the path after it, as Windows writes it either way round.
     *
     * The drive letter is matched only where it is not the tail of a word, which is what keeps
     * `https://` out: the `s` before its colon is preceded by a `p`, so no scheme is ever read as a
     * drive. It is not matched after a `%` either, because `%s:\n%s` — a format specifier followed
     * by an escape — is the one shape in this suite's own source that a single letter and a colon
     * would otherwise be read from, and `%` is in the path no further: a literal path is not
     * written with a variable in the middle of it.
     *
     * A separator is one or two characters because the same path in PHP source is written with
     * each backslash escaped, and `E:\\workspace` is the leak in exactly the form the person who
     * copied it was looking at.
     */
    private const DRIVE = '~(?<![A-Za-z0-9%])([A-Za-z]:'.self::SEPARATOR.'(?:'.self::SPACED.self::SEPARATOR.')*'.self::NAME.')~';

    /**
     * The same drive, spelled the way a POSIX shell reaches it.
     *
     * `/e/...` is `E:\...` in MSYS and Git Bash, `/mnt/e/...` is the same in WSL, and
     * `/cygdrive/e/...` is the same in Cygwin — three spellings of one location, and the shape a
     * rewrite *out* of a drive letter produces without looking wrong: `E:/workspace` and
     * `/e/workspace` both read as paths, and only one of them is in the drive rule.
     *
     * Two things beyond the root are what keep this from firing at every slash in the tree: the
     * segment right after the root has to be a single letter, which is what makes it a mount rather
     * than a directory, and there has to be a segment after that, which is what makes it a path
     * rather than prose (`/a/b`). A container path is excluded by the same rule: `/mnt/laravel/html`
     * has a name after `/mnt/` rather than a letter.
     */
    private const MOUNT = '~(?<![A-Za-z0-9_.:/-])((?:/mnt|/cygdrive)?/[A-Za-z]/(?:'.self::SPACED.'/)+'.self::NAME.')~';

    /**
     * An absolute home directory, with the name it belongs to.
     *
     * The leading `/home` or `/Users` has to be the start of the path rather than something inside a
     * longer one, which is what excludes `https://host/home/name` — the `/` there is preceded by a
     * letter. That is the whole reason this pattern has a lookbehind at all.
     */
    private const HOME = "~(?<![A-Za-z0-9_.:/-])(/(?:Users|home)/[^\\s\"'`()<>|*?,;]*+)~";

    /**
     * A UNC share, which resolves on the network it was written on and nowhere else.
     *
     * Two backslashes that are not themselves escaped, which is what separates a share from the
     * doubled backslash of a namespace in JSON or in a PHP string — `"Uak35\\Weighted\\"` is
     * preceded by a letter at every pair, so it is not read as a host. The run is taken as one or
     * two pairs, because the same share written inside a PHP string doubles each backslash again
     * and a reading that insisted on exactly one pair would miss it.
     *
     * A host and a share are both required to be more than one character, which is what keeps the
     * escapes out of this suite's own regular expressions: `\s`, `\d` and the two backslashes of a
     * namespace are two backslashes and a letter each, and a share is never named with one.
     *
     * Written as a nowdoc so that what is read here is what is written: a run of backslashes inside
     * a PHP string is escaped once by the language and again by the regular expression, and the one
     * place that needs no counting is the one place the pattern can be seen.
     */
    private const SHARE = <<<'REGEX'
        ~(?<![A-Za-z0-9_])((?:\\\\){1,2}+[A-Za-z0-9][A-Za-z0-9_.-]*+\\?+[A-Za-z0-9_.-]{2,}+[^\s"'`()<>|*?,;]*)~
        REGEX;

    /**
     * Every machine-local path in one text, with the line it is on.
     *
     * @return list<array{line: int, path: string}>
     */
    public static function in(string $text): array
    {
        $found = [];

        // An array rather than a line number counter, so a text with a mixture of endings is split
        // the same way the rest of the suite splits one.
        foreach (array_values(preg_split('/\R/', $text) ?: []) as $index => $line) {
            foreach (self::matches($line) as $path) {
                $found[] = ['line' => $index + 1, 'path' => $path];
            }
        }

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
        $files = RepoEscapes::files($root);
        $found = [];
        $exempt = [];

        foreach ($files as $file) {
            // A file that is tracked but gone from the working tree has nothing to leak.
            $contents = @file_get_contents($root.'/'.$file);

            if ($contents === false || self::binary($contents)) {
                continue;
            }

            foreach (self::in($contents) as $hit) {
                $record = ['file' => $file, 'line' => $hit['line'], 'path' => $hit['path']];

                isset(self::EXEMPT[$file]) ? $exempt[] = $record : $found[] = $record;
            }
        }

        return ['files' => $files, 'found' => $found, 'exempt' => $exempt];
    }

    /**
     * Whether a file is text this can read: the same test git makes before it diffs one.
     *
     * `RepoEscapes` asks this question too, and asks it here rather than keeping its own copy:
     * "what a commit would carry" and "what a text is" are one decision each, and a file is text
     * whichever of the two readings is asking.
     */
    public static function binary(string $contents): bool
    {
        return str_contains(substr($contents, 0, 8000), "\0");
    }

    /**
     * The machine-local paths in one line, in the order they are written.
     *
     * @return list<string>
     */
    private static function matches(string $line): array
    {
        $found = [];

        foreach ([self::DRIVE, self::HOME, self::SHARE, self::MOUNT] as $pattern) {
            if (preg_match_all($pattern, $line, $hits) === 0) {
                continue;
            }

            // preg_match_all() leaves `$hits` without the capture group at all when there is
            // nothing to put in it, and the suite fails on the notice that reading it would raise.
            if ($hits[1] === []) {
                continue;
            }

            foreach ($hits[1] as $path) {
                if (!self::tolerated($path)) {
                    $found[] = $path;
                }
            }
        }

        return $found;
    }

    /**
     * Whether a path names nobody, which is the only thing that stops it being a finding.
     *
     * Each of these is the same path on every machine of its kind, so it identifies nobody — and
     * each is written down here rather than left out of the patterns, so that the exception is
     * visible where the rule is.
     */
    private static function tolerated(string $path): bool
    {
        $path = str_replace('\\', '/', $path);

        // A doubled separator is how PHP source writes one — the string `C:\\ProgramData` is
        // written `C:\\\\ProgramData` there — so the two spellings are collapsed before the path is
        // asked what it names. Collapsing cannot tolerate a path that names somebody: it is the run
        // of separators that goes, not a segment.
        $path = preg_replace('~/{2,}~', '/', $path) ?? $path;

        // A mount spelling is the same directory as the drive-letter spelling of it, so it is
        // tolerated by the same rule rather than a second one that could drift from it:
        // `/c/Windows/...`, `/mnt/c/Windows/...` and `/cygdrive/c/Windows/...` all become
        // `c:/Windows/...` here, where the one pattern already knows what to do with it.
        $path = preg_replace('~^/(?:mnt/|cygdrive/)?([A-Za-z])/~', '$1:/', $path) ?? $path;

        return preg_match('~^[A-Za-z]:/(?:Windows|Program Files|Program Files \(x86\)|ProgramData)(?:/|$)~i', $path) === 1
            || preg_match('~^/(?:Users|home)/runner(?:/|$)~i', $path) === 1;
    }
}
