<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Tests\Support\ReleaseRepo;

/**
 * What `--dry-run` promises, held to the repository rather than to the sentence it ends on.
 *
 * The command's own words are `--weigh --dry-run — show the plan, change nothing`, and it
 * closes by saying so: *Dry run: nothing was written, committed or tagged.* The suite read
 * that promise only the way the script makes it — a test asserting the sentence, and tests
 * asserting the tree *after an apply*, where the files are supposed to have moved. Neither
 * can fail when a plan writes something and then says it did not, because neither looks at
 * the repository on both sides of the same run.
 *
 * So these tests read the repository before and after one dry run and ask for the two
 * readings to be identical: HEAD and the branch under it, every ref with the object it points
 * at, the index, the bytes of every tracked file on disk, what git reports as staged,
 * unstaged or untracked, and the reflog — which is where a ref created and deleted during a
 * run would still be visible.
 *
 * The reading is not taken on trust. `the_reading_notices_a_release_that_landed` runs the same
 * command for real, in a fixture built the same way, and asserts the snapshot *moves*, so a
 * helper that returned a constant, or a snapshot that read nothing, cannot make these tests
 * pass.
 */
final class DryRunTest extends TestCase
{
    private const FIXED = "### Fixed\n\n- A ported defect, fixed.\n";

    private const ADDED = "### Added\n\n- A command nobody could run before.\n";

    /**
     * The guarantee on the tree a release is normally cut from: clean, tagged, with one
     * `### Added` entry for the plan to weigh.
     */
    public function test_a_dry_run_leaves_the_working_tree_the_refs_and_head_untouched(): void
    {
        $repo = ReleaseRepo::make(self::ADDED);
        $repo->tag('v1.0.0');

        $before = self::snapshot($repo);

        $run = $repo->release('--weigh', '--dry-run');

        $this->assertSame(0, $run->exitCode, $run->describe());

        // The plan was reached and weighed, so the snapshot below is a reading of a run that
        // got all the way to the end rather than of one refused at the door — which would
        // keep the tree still for the wrong reason.
        $this->assertSame('1.1.0  (tag v1.1.0)', $run->plan('next version'), $run->describe());
        $this->assertTrue($run->said('Dry run: nothing was written, committed or tagged.'), $run->describe());
        $this->assertFalse(
            $run->said('committed and tagged'),
            'The apply half ran during a dry run.' . PHP_EOL . $run->describe(),
        );

        $after = self::snapshot($repo);

        $this->assertSame($before['HEAD'], $after['HEAD'], 'HEAD moved.' . PHP_EOL . $run->describe());
        $this->assertSame($before['refs'], $after['refs'], 'A ref appeared, moved or went away.' . PHP_EOL . $run->describe());
        $this->assertSame($before['files'], $after['files'], 'A tracked file was rewritten.' . PHP_EOL . $run->describe());
        $this->assertSame($before['status'], $after['status'], 'The working tree is not what it was.' . PHP_EOL . $run->describe());
        $this->assertSame($before, $after, 'Something else in the repository moved.' . PHP_EOL . $run->describe());

        // And the files a release *writes* were not created at all. Named rather than left to
        // the untracked reading, because the inventory is written before the tag and would be
        // the first half of a release with no version.
        $this->assertFalse($repo->exists('files.tsv'), $run->describe());
        $this->assertFalse($repo->exists('methods.tsv'), $run->describe());
        $this->assertFalse($repo->exists('surface.tsv'), $run->describe());
    }

    /**
     * The same guarantee where a write is most likely: the tree the script has just been told
     * to work around. A dirty tree is the one state the dry run does not refuse, so the run
     * that continues past the warning is the one to hold to "ignored for this dry run" — the
     * edit is neither released, nor committed, nor swept up in anything else.
     */
    public function test_a_dry_run_on_a_dirty_tree_leaves_the_edit_exactly_where_it_was(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v0.1.0');
        $repo->write('src/Thing.php', str_replace(
            "return 'thing';",
            "return 'thing changed';",
            $repo->read('src/Thing.php'),
        ));

        $before = self::snapshot($repo);

        $run = $repo->release('--weigh', '--dry-run');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->said('Working tree is dirty (1 changed file(s)) — ignored for this dry run.'),
            $run->describe(),
        );
        $this->assertSame('main  (dirty)', $run->plan('branch'), $run->describe());

        $after = self::snapshot($repo);

        // The premise, so the identity below is not a reading of a tree that was clean anyway.
        $this->assertStringContainsString('M src/Thing.php', $after['status'], $run->describe());
        $this->assertSame($before, $after, 'A dry run on a dirty tree changed it.' . PHP_EOL . $run->describe());
    }

    /**
     * The control, in the sense this repository uses the word elsewhere: the same reading has
     * to *see* a release that landed, or a snapshot that returned a constant would satisfy
     * both tests above.
     *
     * The tree reading is deliberately not among the three that must move. A release commits
     * what it writes, so `git status` is clean on both sides of it — which is exactly why the
     * tracked bytes are hashed here rather than left to git, and why the promotion and the
     * inventory show up in `files` while the status reading does not move at all.
     */
    public function test_the_reading_notices_a_release_that_landed(): void
    {
        $repo = ReleaseRepo::make(self::ADDED);
        $repo->tag('v1.0.0');

        $before = self::snapshot($repo);

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(0, $run->exitCode, $run->describe());

        $after = self::snapshot($repo);

        $this->assertNotSame($before['HEAD'], $after['HEAD'], 'The release committed nothing.');
        $this->assertNotSame($before['refs'], $after['refs'], 'The release tagged nothing.');
        $this->assertNotSame($before['files'], $after['files'], 'The release rewrote no tracked file.');
        $this->assertNotSame($before, $after, $run->describe());

        $this->assertStringContainsString('v1.1.0', $repo->tags(), $run->describe());
        $this->assertTrue($repo->exists('files.tsv'), $run->describe());
    }

    /**
     * The repository as one comparable value: the commit HEAD names, the branch it is on,
     * every ref with the object it points at, the index's staged blobs, the bytes of every
     * tracked file, what git reports as staged, unstaged or untracked, and the reflog.
     *
     * The bytes are hashed here rather than asked of `git status`, because git compares a file
     * against its stat record and a rewrite inside one clock tick can look untouched — and a
     * plan that runs in well under a second is exactly the case where that would hide a write.
     * For the same reason the untracked reading is `--untracked-files=all`: a file the run
     * leaves behind is the likeliest trace of one, and the default setting hides a new file
     * inside a directory that is already untracked.
     *
     * @return array<string, mixed>
     */
    private static function snapshot(ReleaseRepo $repo): array
    {
        $files = [];

        foreach (preg_split('/\R/', trim($repo->git('ls-files'))) ?: [] as $file) {
            if ($file !== '') {
                $files[$file] = (string) hash_file('sha256', $repo->path($file));
            }
        }

        ksort($files);

        return [
            'HEAD' => trim($repo->git('rev-parse', 'HEAD')),
            'branch' => trim($repo->git('symbolic-ref', '--short', 'HEAD')),
            'refs' => $repo->git('show-ref'),
            'index' => $repo->git('ls-files', '--stage'),
            'files' => $files,
            'status' => $repo->git('status', '--porcelain', '--untracked-files=all', '--', '.'),
            'reflog' => $repo->git('reflog', 'show', '--all', '--format=%H %gs'),
        ];
    }
}
