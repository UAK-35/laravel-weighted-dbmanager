<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Support\WritableFile;

/**
 * One helper, two questions: hand a file this package wrote back to the next account, and
 * say whether a path can be written at all.
 *
 * The first is the reason the class exists — a boot or a flip is routinely not the same
 * account as the one that wrote the file, and a record or state file left under the writer's
 * umask is one the next process refuses. The second replaced two copies of the same reading
 * in `BootAudit` and `PgcatConfigFlipper`.
 *
 * The mode is the claim only where the host has one: on Windows `chmod()` is the read-only
 * attribute, so the tests assert writability there and the permission only on POSIX.
 */
final class WritableFileTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @chmod($file, 0o666);
            @unlink($file);
        }

        $this->files = [];
    }

    public function test_a_read_only_file_is_handed_back_writable_with_its_contents(): void
    {
        $file = $this->file('the record');

        $this->assertTrue(chmod($file, 0o444));
        clearstatcache(true, $file);
        $this->assertFalse(is_writable($file), 'the fixture has to be the state the helper repairs');

        $this->assertTrue(WritableFile::handBack($file));
        $this->assertSame('the record', (string) file_get_contents($file), 'a mode change is not an edit');

        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame(0o666, fileperms($file) & 0o777, 'and every account that can reach it may rewrite it');
        }
    }

    public function test_a_file_that_is_already_writable_is_left_alone_and_answered_true(): void
    {
        $file = $this->file('already writable');

        $this->assertTrue(WritableFile::handBack($file));
        $this->assertSame('already writable', (string) file_get_contents($file));
    }

    public function test_a_path_with_nothing_at_it_is_answered_false_and_not_created(): void
    {
        // The ordinary case rather than a fault: `BootAudit` removes the record when the
        // merged one has nothing left to remember, so its release runs over a missing path.
        $path = sys_get_temp_dir() . '/swrr-writable-file-' . bin2hex(random_bytes(6)) . '.json';

        $this->assertFalse(WritableFile::handBack($path));
        $this->assertFileDoesNotExist($path, 'handing back a mode must never be what creates a file');
    }

    public function test_can_write_reads_the_file_when_there_is_one(): void
    {
        $file = $this->file('a state file');

        $this->assertTrue(WritableFile::canWrite($file));

        $this->assertTrue(chmod($file, 0o444));
        clearstatcache(true, $file);

        if (!is_writable($file)) {
            $this->assertFalse(WritableFile::canWrite($file), 'a file this account may not write is not somewhere a write lands');
        }
    }

    public function test_can_write_reads_the_directory_a_missing_file_would_be_written_into(): void
    {
        $directory = sys_get_temp_dir() . '/swrr-writable-file-' . bin2hex(random_bytes(6));

        $this->assertTrue(mkdir($directory, 0o777));

        try {
            $this->assertTrue(WritableFile::canWrite($directory . '/record.json'));

            $this->assertFalse(
                WritableFile::canWrite($directory . '/absent/record.json'),
                'a directory that is not there cannot take the file either — a state path is not created by a flip',
            );
        } finally {
            @rmdir($directory);
        }
    }

    private function file(string $contents): string
    {
        $path = sys_get_temp_dir() . '/swrr-writable-file-' . bin2hex(random_bytes(6)) . '.json';

        file_put_contents($path, $contents);

        $this->files[] = $path;

        return $path;
    }
}
