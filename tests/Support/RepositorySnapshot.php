<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Support;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A repository read as one comparable value, so a promise about *not* writing to it can be
 * measured rather than asserted in the script's own words.
 *
 * WHY THIS EXISTS
 * ---------------
 *   `bin/release.php` says what it did — *Dry run: nothing was written, committed or tagged.* —
 *   and a test that reads that sentence is a test of the sentence. So the release suite reads the
 *   repository instead, on both sides of one run, and asks for the two readings to be identical:
 *   `tests/Unit/Release/DryRunTest.php` for the plan, `tests/Unit/Release/ExitPathsTest.php` for
 *   every exit that is not the apply.
 *
 *   Both files hold the same promise, so they have to take the same reading — two readings would
 *   be two promises, and a run that slipped past one of them would look covered. That is why the
 *   reading lives here rather than in either test: it is the definition of the promise, and the
 *   tests are what it is applied to.
 *
 * WHAT IS READ
 * ------------
 *   Every file under the fixture's root except `.git`, by the hash of its bytes and by its size
 *   and mtime — a directory walk rather than `git ls-files`, so an untracked file a run leaves
 *   behind is in the reading by construction, and so is the reading of a directory that is not a
 *   repository at all (the `is not a git repository` refusal's own fixture is one). Beside the
 *   files, when `.git` is a directory: the commit `HEAD` names, the branch under it, every ref
 *   with the object it points at, the index's staged blobs, what git reports as staged, unstaged
 *   or untracked, and the reflog — which is where a ref created and deleted during a run would
 *   still be visible.
 *
 *   The bytes are hashed rather than asked of `git status`, because git compares a file against
 *   its stat record and a rewrite inside one clock tick can look untouched — and a plan that runs
 *   in well under a second is exactly the case where that would hide a write. The size-and-mtime
 *   reading beside the hash is the same argument one step further: a rewrite that happened to
 *   produce identical content is still a write. Neither is exact on a filesystem whose clock
 *   cannot tell two writes apart, which is why the hash is the reading that carries the claim.
 *
 *   The untracked reading is `--untracked-files=all` rather than git's default, because a file a
 *   run leaves behind is the likeliest trace of one and the default setting hides a new file
 *   inside a directory that is already untracked.
 *
 * WHAT IS NOT READ
 * ----------------
 *   `.git`'s own files, past the refs, the index and the reflog above: git rewrites its index's
 *   stat cache on `git status`, and a run that only *asked* about the tree would then look like a
 *   run that wrote to it. The honest reading of git's internals is the one git reports about
 *   them, which is what the four readings above are.
 */
final class RepositorySnapshot
{
    /**
     * @param array<string, mixed> $reading the readings, in the order `differences()` reports them
     */
    private function __construct(private readonly array $reading)
    {
    }

    /**
     * Read the fixture as it is now.
     *
     * Taken twice around one run, and compared. The reader never writes: every git command here
     * is a query, and the file walk only opens files for reading.
     */
    public static function of(ReleaseRepo $repo): self
    {
        $files = [];
        $written = [];

        foreach (self::tree($repo->root()) as $relative) {
            $path = $repo->path($relative);
            $stat = @stat($path);

            $files[$relative] = (string) hash_file('sha256', $path);
            $written[$relative] = $stat === false ? 'missing' : $stat['size'] . '@' . $stat['mtime'];
        }

        $reading = [
            'files' => $files,
            'written' => $written,
        ];

        // A directory the release rail refused *because* it is not a repository has no refs and no
        // index to read, and asking for them would be an exception rather than a reading. The files
        // above are the whole of what that state has, and they are enough: the promise broken there
        // is a rewritten CHANGELOG.
        if (!is_dir($repo->path('.git'))) {
            return new self($reading);
        }

        return new self([
            'HEAD' => trim($repo->git('rev-parse', 'HEAD')),
            'branch' => trim($repo->git('symbolic-ref', '--short', 'HEAD')),
            'refs' => $repo->git('show-ref'),
            'index' => $repo->git('ls-files', '--stage'),
            'status' => $repo->git('status', '--porcelain', '--untracked-files=all', '--', '.'),
            'reflog' => $repo->git('reflog', 'show', '--all', '--format=%H %gs'),
        ] + $reading);
    }

    /** The commit `HEAD` names. */
    public function head(): string
    {
        return (string) $this->reading['HEAD'];
    }

    /** Every ref with the object it points at, as git prints them. */
    public function refs(): string
    {
        return (string) $this->reading['refs'];
    }

    /**
     * Every file under the root by the hash of its bytes, `.git` aside.
     *
     * @return array<string, string>
     */
    public function files(): array
    {
        return $this->reading['files'];
    }

    /** What git reports as staged, unstaged or untracked. */
    public function status(): string
    {
        return (string) $this->reading['status'];
    }

    /**
     * What moved between this reading and another, as the names of the readings that differ.
     *
     * A map is named file by file rather than whole: *the files moved* is true of every release
     * ever cut and tells a reader nothing, while `files[CHANGELOG.md]` says which of them the run
     * touched — and whether it appeared rather than changed.
     *
     * @return list<string>
     */
    public function differences(self $other): array
    {
        $moved = [];

        foreach ($this->reading as $reading => $value) {
            $theirs = $other->reading[$reading] ?? null;

            if ($value === $theirs) {
                continue;
            }

            if (!is_array($value)) {
                $moved[] = $reading;

                continue;
            }

            $theirs = is_array($theirs) ? $theirs : [];

            foreach ($value as $path => $entry) {
                if (!array_key_exists($path, $theirs) || $theirs[$path] !== $entry) {
                    $moved[] = $reading . '[' . $path . ']';
                }
            }

            foreach ($theirs as $path => $entry) {
                if (!array_key_exists($path, $value)) {
                    $moved[] = $reading . '[' . $path . ']';
                }
            }
        }

        return $moved;
    }

    /**
     * Every file under a root, relative to it and in a fixed order, `.git` and its subtree aside.
     *
     * The paths are `/`-separated on every platform, because they are compared with the paths of
     * another reading rather than opened: a Windows reading of the same tree must be the same value
     * as a POSIX one.
     *
     * @return list<string>
     */
    private static function tree(string $root): array
    {
        $directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);

        $withoutGit = new RecursiveCallbackFilterIterator(
            $directory,
            static fn (SplFileInfo $file): bool => $file->getFilename() !== '.git',
        );

        $paths = [];

        foreach (new RecursiveIteratorIterator($withoutGit) as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }

            $paths[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        }

        sort($paths);

        return $paths;
    }
}
