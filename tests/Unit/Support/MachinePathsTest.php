<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Tests\Support\MachinePaths;

/**
 * AGENTS.md section 0 against the tree it describes: no home directory, no other drive, no network
 * share — nothing here names the machine it was written on.
 *
 * WHY THIS EXISTS
 * ---------------
 *   The rule has three clauses about place, and one of them was already read: `RepoEscapesTest`
 *   walks every path in the tree and refuses the ones that climb above the root. Its own header
 *   names the other half as the half it does not read, and this is that half. The two failures are
 *   different questions — a climb leaks a *layout*, an absolute path leaks a *machine* — and they
 *   fail differently too: a climb stops resolving when the checkout moves, and an absolute path
 *   resolves for ever, for one person. Neither shows up in a diff, because both read as paths.
 *
 *   So the paths are read out of the files a commit would carry — tracked plus the untracked the
 *   ignore rules do not cover, through `RepoEscapes::files()` — and a path is reported when it
 *   names a drive, an account or a network rather than a directory every machine of its kind has.
 *   The reading is `tests/Support/MachinePaths.php`, and the tolerated shapes are written down in
 *   it rather than left to the eye: `C:/Windows/…`, a program's install root, a CI runner's home,
 *   a tilde, the IDE's marker, and an ellipsis standing for the rest of a path.
 *
 * THREE THINGS ARE ASSERTED RATHER THAN ONE
 * -----------------------------------------
 *   That nothing in the tree names a machine; that the detector fires at all — the samples below,
 *   which is the only way a reading of nothing is told apart from a reading that works; and that a
 *   file exempted for holding those samples still has to hold one, because an exemption is a hole
 *   in the guard and a hole nobody is standing in is just a hole.
 */
final class MachinePathsTest extends TestCase
{
    public function test_no_path_in_the_tree_names_the_machine_it_was_written_on(): void
    {
        $scan = MachinePaths::scan(self::root());

        $reported = array_map(
            static fn (array $hit): string => sprintf('%s:%d  %s', $hit['file'], $hit['line'], $hit['path']),
            $scan['found'],
        );

        // A scan that read nothing agrees with a repository that leaked nothing, so the listing is
        // checked for substance before the findings are: the files the samples live in have to be
        // in it, which is only true of a listing that came from the real working tree.
        self::assertSame(
            [],
            array_values(array_diff(array_keys(MachinePaths::EXEMPT), $scan['files'])),
            'The scan did not read the files the samples live in, so it is not reading this tree.',
        );

        self::assertSame([], $reported, sprintf(
            "AGENTS.md says the work is this folder and nothing outside it, and these paths name the machine they were written on — each one resolves for whoever wrote it and for nobody else:\n  - %s",
            implode("\n  - ", $reported),
        ));
    }

    public function test_every_exemption_is_still_earned(): void
    {
        $scan = MachinePaths::scan(self::root());

        $exempt = array_values(array_unique(array_column($scan['exempt'], 'file')));
        $declared = array_keys(MachinePaths::EXEMPT);

        sort($exempt);
        sort($declared);

        // Each exemption carries the reason it was granted, and a reason is only worth writing down
        // once it can be checked: a file that no longer holds a sample is reported with the reason
        // it was exempted for, which is the one that gets removed.
        self::assertSame($declared, $exempt, sprintf(
            'These files are exempt from the machine-path reading and no longer hold a sample it would fire at, so the exemption is hiding nothing but is hiding it from a reading that no longer needs it: %s',
            implode(', ', array_values(array_diff($declared, $exempt))) ?: '(none)',
        ));

        foreach (MachinePaths::EXEMPT as $file => $reason) {
            self::assertNotSame('', trim($reason), "The exemption for {$file} does not say why it was granted.");
        }
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('machines')]
    public function test_a_path_that_names_a_machine_is_reported(string $text, array $expected): void
    {
        self::assertSame(
            $expected,
            array_column(MachinePaths::in($text), 'path'),
            sprintf('The paths that name a machine in [%s] are not the ones this reports.', $text),
        );
    }

    #[DataProvider('nobody')]
    public function test_a_path_that_names_nobody_is_left_alone(string $text): void
    {
        self::assertSame(
            [],
            MachinePaths::in($text),
            sprintf('[%s] is reported as naming a machine, and it names nobody.', $text),
        );
    }

    /**
     * The report a failure prints is `file:line`, so a path found in the wrong place would send the
     * reader to the wrong line of a file they are already looking at.
     */
    public function test_each_path_keeps_the_line_it_was_written_on(): void
    {
        self::assertSame(
            [['line' => 1, 'path' => '/home/umar/.gitconfig']],
            MachinePaths::in("cp /home/umar/.gitconfig .\n"),
        );

        self::assertSame(
            [['line' => 2, 'path' => 'C:/Users/umar/project']],
            MachinePaths::in("first\nsecond cd C:/Users/umar/project\n"),
        );

        self::assertSame(
            [['line' => 2, 'path' => 'E:\\_F_DRV\\_PHP']],
            MachinePaths::in("first\r\nsecond E:\\_F_DRV\\_PHP\r\n"),
        );
    }

    /**
     * A path that names a machine, and what is reported for it.
     *
     * Every one of these is written the way the leak reaches the tree rather than the way it looks
     * once it is there: a recipe is a drive path, PHP source escapes each backslash, and the shell
     * this package is tested from reaches the same drive as `/e/...`.
     *
     * @return array<string, array{string, list<string>}>
     */
    public static function machines(): array
    {
        return [
            'a drive letter' => ['cd C:/Users/umar/project', ['C:/Users/umar/project']],
            'a back-slashed drive letter' => ['cd C:\\Users\\umar\\project', ['C:\\Users\\umar\\project']],
            'the same path in PHP source' => ["'dir' => 'E:\\\\_F_DRV\\\\_PHP',", ['E:\\\\_F_DRV\\\\_PHP']],
            'a Linux home directory' => ['cp /home/umar/.gitconfig .', ['/home/umar/.gitconfig']],
            'a macOS home directory' => ['cp /Users/umar/.gitconfig .', ['/Users/umar/.gitconfig']],
            'a network share' => ['cat \\\\fileserver\\share\\notes', ['\\\\fileserver\\share\\notes']],
            'the MSYS spelling of a drive' => ['cd /e/_WORKS/lpr/work/LPR', ['/e/_WORKS/lpr/work/LPR']],
            'the WSL spelling of the same location' => ['export DataDir=/mnt/e/_WORKS/lpr/work', ['/mnt/e/_WORKS/lpr/work']],
            'the Cygwin spelling of it' => ['cp /cygdrive/d/tools/sqlite3 /usr/local/bin', ['/cygdrive/d/tools/sqlite3']],
            'a Windows path that is on no install root' => ['C:\\bin\\supervisorctl.exe carries real separators', ['C:\\bin\\supervisorctl.exe']],
            'its escaped form' => ["'C:\\\\bin\\\\supervisorctl.exe'", ['C:\\\\bin\\\\supervisorctl.exe']],
            'a placeholder in a usage line' => ['bin\\composer-link.cmd C:\\path\\to\\pkg --dry-run', ['C:\\path\\to\\pkg']],
            'one the writer left half-written still names its drive' => ['E:\\_F_DRV\\… out of it', ['E:\\_F_DRV']],
        ];
    }

    /**
     * A path that names nobody, and why it names nobody.
     *
     * The other half of the reading is what it must not fire at. Each of these is a shape a real leak
     * hides behind: an absolute path that belongs to every machine of its kind rather than to one of
     * them, an absolute-looking name that is not a path at all, and a path the reader is meant to
     * finish. A detector that fired on any of them would report the repository rather than a machine.
     *
     * @return array<string, array{string}>
     */
    public static function nobody(): array
    {
        return [
            'a URL' => ['see https://example.test/docs'],
            'a URL whose path starts with a home directory' => ['see https://example.test/home/umar'],
            'the Windows directory' => ['core.sshCommand = C:/Windows/System32/OpenSSH/ssh.exe'],
            'the Windows directory, back-slashed' => ['core.sshCommand = C:\\Windows\\System32\\OpenSSH\\ssh.exe'],
            'a program install root' => ["- 'C:\\\\Program Files\\\\Git\\\\mingw64\\\\etc\\\\ssl\\\\certs\\\\ca-bundle.crt',"],
            'the install root beside a home directory' => ['from both C:/Program Files/Git/etc/gitconfig and ~/.gitconfig'],
            'a CI runner' => ['cd /home/runner/work/pkg/pkg'],
            'a tilde' => ['cp ~/.gitconfig .'],
            'the IDE marker' => ['$PROJECT_DIR$/vendor/bin/phpstan'],
            'an ellipsis for a directory' => ['tried supervisorctl on C:\\\\…, … and 78 more PATH entries'],
            'an ellipsis right after the root' => ['cd C:\\…'],
            'a container path' => ['docker run -v /mnt/laravel/html:/var/www image'],
            'a mount with nothing named after it' => ['cd /e/workspace'],
            'a namespace in PHP source' => ['namespace Uak35\\WeightedDbManager\\Tests;'],
            'an escaped namespace in JSON' => ['"Uak35\\\\WeightedDbManager\\\\": "src/"'],
            'a path that is absolute and belongs to nobody' => ['/usr/local/bin/php'],
        ];
    }

    /**
     * The package root: four levels up from `tests/Unit/Support/`, which is where this file lives.
     */
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
