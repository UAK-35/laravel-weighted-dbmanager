<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Pgcat;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Pgcat\VariantShape;

/**
 * The shape of what a flip swaps to: what each configured variant declares, held against the pool
 * names the pinned connection is written with.
 *
 * The class reads three files and a connection, so every case here is a small installation laid out
 * in the temp directory — the three paths written with the sections a case is about, and the
 * connection's own names passed in as the array `VariantShape::namedPools()` returns. Nothing here
 * opens a socket: the one reading that asks a resolver is injected, with the strict allowlist the
 * boot's own prune test uses, because the question is what the reading does with an answer rather
 * than whether this machine's DNS answers.
 *
 * The load-bearing assertions are the negative ones. A connection naming a pool no variant declares
 * is *not judged* — `test_nothing_is_compared_when_no_variant_declares_a_pool_the_connection_names`
 * pins that, and it is the line that keeps an installation whose database name and pool names are
 * simply different words from being failed for it — and a pool whose address does not resolve is a
 * warning rather than a failure, because resolution is a fact about the host that asks.
 */
final class VariantShapeTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir.'/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($dir);
        }

        $this->tempDirs = [];

        parent::tearDown();
    }

    /**
     * What one connection is written with. The write pool is the connection's own `database` — the
     * field pgcat addresses a pool by — and the read pools are its read entries' own, with the
     * entry that names the write pool and the entry that names none left out: neither is a second
     * pool for the pooler to hold.
     */
    public function test_the_pools_a_connection_is_written_with_are_its_database_and_its_read_entries(): void
    {
        $this->assertSame(
            ['write' => 'writer_db_1', 'read' => ['reader_db_1', 'reader_db_2']],
            VariantShape::namedPools([
                'database' => 'writer_db_1',
                'read' => [
                    ['host' => '127.0.0.1', 'database' => 'reader_db_1'],
                    ['host' => '127.0.0.1', 'database' => 'reader_db_2'],
                    ['host' => '127.0.0.1', 'database' => 'writer_db_1'],
                    ['host' => '127.0.0.1'],
                ],
            ]),
        );

        // A connection that writes to a different database than it reads from is written with the
        // write block's own name — the copy Laravel merges for a write is what a query lands with.
        $this->assertSame(
            ['write' => 'writer_db_2', 'read' => ['reader_db_1']],
            VariantShape::namedPools([
                'database' => 'writer_db_1',
                'write' => ['database' => 'writer_db_2'],
                'read' => [['database' => 'reader_db_1']],
            ]),
        );

        // A direct connection names no pool: its `database` is a database, its read entries carry no
        // name of their own, and there is nothing here for a variant to be held to.
        $this->assertSame(
            ['write' => 'app', 'read' => []],
            VariantShape::namedPools(['database' => 'app', 'read' => [['host' => '10.1.0.1']]]),
        );
    }

    /**
     * The shape a flip can serve: the write pool in every file, the read pools in the readers
     * variant, and the writer-only variant declaring none of them.
     */
    public function test_a_consistent_installation_has_no_problems_and_says_what_it_compared(): void
    {
        $shape = $this->shape();

        $this->assertSame([], $shape->problems());
        $this->assertSame([], $shape->failures());
        $this->assertSame([], $shape->warnings());
        $this->assertTrue($shape->isShaped());
        $this->assertTrue($shape->judged());

        $summary = $shape->summary();

        $this->assertStringContainsString('3 configured variants parse', $summary);
        $this->assertStringContainsString('write pool "writer_db_1"', $summary);
        $this->assertStringContainsString('none of the 2 read pools connection "pools" weights', $summary);
        $this->assertStringContainsString('"reader_db_1", "reader_db_2"', $summary);
    }

    /**
     * The defect the reading exists for, from the side a flip would take it: a writer-only variant
     * that still declares a reader is a file whose whole purpose is undone, and the sentence names
     * the file and the pool so an operator has both.
     */
    public function test_the_writer_only_variant_declaring_a_read_pool_is_a_failure(): void
    {
        $shape = $this->shape(noReaders: "[pools.writer_db_1]\n[pools.reader_db_1]\n");

        $problem = $this->onlyProblem($shape, VariantShape::PROBLEM_READER_IN_WRITER_ONLY);

        $this->assertSame(VariantShape::SEVERITY_FAIL, $problem['severity']);
        $this->assertSame('no_readers_path', $problem['setting']);
        $this->assertSame('reader_db_1', $problem['pool']);
        $this->assertSame('the writer-only variant', $problem['role']);
        $this->assertStringContainsString('swrr.pgcat.no_readers_path [', $problem['sentence']);
        $this->assertStringContainsString('declares pool "reader_db_1"', $problem['sentence']);
        $this->assertStringContainsString('leave the readers out', $problem['sentence']);
        $this->assertFalse($shape->isShaped());
    }

    /**
     * The other direction, on the file in place: the pools the connection is written with are the
     * ones a flip has to keep serving, and a target that has lost one of them is a write with no
     * pool to reach — the state a pool commented out of a rendered file leaves behind.
     */
    public function test_a_file_that_declares_no_pool_the_connection_writes_through_is_a_failure(): void
    {
        $shape = $this->shape(
            inPlace: "[pools.reader_db_1]\n[pools.reader_db_2]\n",
            readers: "[pools.writer_db_1]\n[pools.reader_db_1]\n[pools.reader_db_2]\n",
        );

        $problem = $this->onlyProblem($shape, VariantShape::PROBLEM_WRITE_POOL);

        $this->assertSame(VariantShape::SEVERITY_FAIL, $problem['severity']);
        $this->assertSame('config_path', $problem['setting']);
        $this->assertSame('writer_db_1', $problem['pool']);
        $this->assertStringContainsString('declares no pool "writer_db_1"', $problem['sentence']);
        $this->assertStringContainsString('connection "pools" writes through', $problem['sentence']);
        $this->assertStringContainsString('the file pgcat is running on', $problem['sentence']);

        // The clause differs for a source, because a flip to it is what the failure is about.
        $source = $this->shape(readers: "[pools.reader_db_1]\n[pools.reader_db_2]\n");
        $sourced = $this->onlyProblem($source, VariantShape::PROBLEM_WRITE_POOL);

        $this->assertSame('readers_path', $sourced['setting']);
        $this->assertStringContainsString('a flip to this file would leave every write with no pool to reach', $sourced['sentence']);
    }

    /**
     * A readers variant that has lost a pool the connection weights: every read to it would land on
     * a pool the file the flip wrote does not declare, which is the failure the pool probe then
     * finds on a running pooler.
     */
    public function test_a_readers_variant_missing_a_read_pool_is_a_failure(): void
    {
        $shape = $this->shape(readers: "[pools.writer_db_1]\n[pools.reader_db_1]\n");

        $problem = $this->onlyProblem($shape, VariantShape::PROBLEM_READ_POOL);

        $this->assertSame('readers_path', $problem['setting']);
        $this->assertSame('reader_db_2', $problem['pool']);
        $this->assertStringContainsString('declares no pool "reader_db_2"', $problem['sentence']);
        $this->assertStringContainsString('weights in its read list', $problem['sentence']);
    }

    /**
     * A target that declares some of the connection's read pools and not all of them is neither of
     * the two shapes, which is what a half-rendered variant — a pool commented out, a template
     * rendered twice under two names — looks like from the outside.
     */
    public function test_a_file_in_place_that_is_half_the_readers_variant_is_a_failure(): void
    {
        $shape = $this->shape(inPlace: "[pools.writer_db_1]\n[pools.reader_db_1]\n");

        $problem = $this->onlyProblem($shape, VariantShape::PROBLEM_MIXED_READ_POOLS);

        $this->assertSame('config_path', $problem['setting']);
        $this->assertStringContainsString('declares 1 of the 2 read pools connection "pools" weights', $problem['sentence']);
        $this->assertStringContainsString('"reader_db_1" declared, "reader_db_2" missing', $problem['sentence']);
        $this->assertStringContainsString('neither the readers variant nor the writer-only one', $problem['sentence']);

        // The writer-only shape is not a mixture: none of them is what that file is for.
        $this->assertSame(
            [],
            $this->problemsOfKind($this->shape(inPlace: "[pools.writer_db_1]\n"), VariantShape::PROBLEM_MIXED_READ_POOLS),
        );
    }

    /**
     * A path this reading cannot read is not one of its problems, and the silence is legible rather
     * than convenient: "the file is not there" is `pgcat files`'s finding and the rehearsal's own
     * `read source` step, and what is left here is what a file that *is* there declares. The count in
     * the summary is what says a file was not read, so a variant that is missing cannot read as a
     * shape that was judged and held.
     *
     * The target's absence is the same rule from the other side and worth pinning separately, because
     * a flip *creates* the file it swaps into — the rehearsal's `rename` step reports exactly that —
     * so an installation whose `config_path` is not there yet is one a flip is about to make sense of.
     */
    public function test_a_path_this_reading_cannot_read_is_skipped_and_counted_rather_than_reported(): void
    {
        $dir = $this->tempDir();

        $missingSource = $this->shape(paths: ['readers_path' => $dir.'/not-there.toml']);

        $this->assertSame([], $missingSource->problems());
        $this->assertStringContainsString('2 configured variants parse', $missingSource->summary(), 'what it read is what it says it read');

        $missingTarget = $this->shape(paths: ['config_path' => $dir.'/not-there.toml']);

        $this->assertSame([], $missingTarget->problems());
        $this->assertStringContainsString('2 configured variants parse', $missingTarget->summary());
        $this->assertTrue($missingTarget->judged(), 'the two sources were still read and still held to the connection');
    }

    /**
     * A `[pools…]` section with no name this package can read — a truncated header, a section with
     * no pool below it, a name that is not there. It is reported rather than passed over as a file
     * with one pool fewer in it: the reader and the flip would both be reading a file pgcat will not
     * start on, and the failure is silent everywhere else.
     */
    public function test_a_pool_section_no_name_can_be_read_out_of_is_a_failure(): void
    {
        $shape = $this->shape(
            readers: "[pools.writer_db_1]\n[pools.reader_db_1\n[pools]\n[pools.]\n# [pools.reader_db_2] is commented out\n",
        );

        $problems = $this->problemsOfKind($shape, VariantShape::PROBLEM_HEADER);

        $this->assertCount(3, $problems, 'the three unreadable sections, and not the commented one');

        foreach (['[pools.reader_db_1', '[pools]', '[pools.]'] as $index => $line) {
            $problem = $problems[$index];

            $this->assertSame(VariantShape::SEVERITY_FAIL, $problem['severity']);
            $this->assertSame('readers_path', $problem['setting']);
            $this->assertStringContainsString('— "'.$line.'" —', $problem['sentence'], 'the sentence quotes the line it could not read');
            $this->assertStringContainsString('[pools.<name>]', $problem['sentence']);
        }

        // A well-formed file of any shape has none of them: the sub-tables, a quoted name, and a
        // trailing comment are all sections this reader can name.
        $this->assertSame(
            [],
            $this->problemsOfKind($this->shape(
                readers: "[pools.writer_db_1]\n[pools.writer_db_1.shards.0]\nservers = [[\"lpr-db-01\", 5432]]\n[pools.\"reader.db\"]  # quoted name\n",
            ), VariantShape::PROBLEM_HEADER),
        );
    }

    /**
     * The prune reading: a pool whose addresses do not resolve from here is a pool the container's
     * boot removes before supervisord starts, so a flip to a file declaring it puts that failure
     * back. It is a warning rather than a failure — resolution is a fact about the host that asks —
     * and the sentence names the pool and the addresses so an operator can act on it either way.
     *
     * The resolver is the strict allowlist: one address on it, and everything else unresolved, so
     * the reading is driven rather than taken from this machine's DNS.
     */
    public function test_a_pool_whose_addresses_do_not_resolve_is_reported_and_not_failed(): void
    {
        $shape = $this->shape(
            inPlace: "[pools.writer_db_1]\n[pools.writer_db_1.shards.0]\nservers = [[\"lpr-db-01\", 5432]]\n"
                ."[pools.reader_db_1]\n[pools.reader_db_1.shards.0]\nservers = [[\"gone-db-01\", 5432], [\"gone-db-02\", 5432]]\n"
                ."[pools.reader_db_2]\n",
        );

        $this->assertSame([], $shape->failures(), 'a resolver answer is not a shape failure');
        $this->assertTrue($shape->isShaped());

        $problem = $this->onlyProblem($shape, VariantShape::PROBLEM_UNRESOLVED_POOL);

        $this->assertSame(VariantShape::SEVERITY_WARN, $problem['severity']);
        $this->assertSame('config_path', $problem['setting']);
        $this->assertSame('reader_db_1', $problem['pool']);
        $this->assertStringContainsString('whose addresses do not resolve from here (gone-db-01, gone-db-02)', $problem['sentence']);
        $this->assertStringContainsString('the boot prunes a pool it cannot resolve', $problem['sentence']);
    }

    /**
     * The line the prune reading draws in the other direction. One address that resolves is a pool
     * the prune leaves in place — narrowed, not gone — so reporting it would be reporting a fault
     * that is not there; and a pool whose servers are written some other way declares no address
     * this reader knows, which is an answer of its own rather than a guess.
     */
    public function test_a_pool_with_an_address_that_resolves_is_not_reported_for_a_prune(): void
    {
        $shape = $this->shape(
            inPlace: "[pools.writer_db_1]\n[pools.writer_db_1.shards.0]\nservers = [[\"lpr-db-01\", 5432]]\n"
                ."[pools.reader_db_1]\n[pools.reader_db_1.shards.0]\nservers = [[\"gone-db-01\", 5432], [\"lpr-db-01\", 5432]]\n"
                ."[pools.reader_db_2]\n[pools.reader_db_2.shards.0]\nservers = [\"gone-db-01:5432\"]\n",
        );

        $this->assertSame([], $shape->warnings());
    }

    /**
     * The line that keeps the whole reading from failing a working installation: a pool name the
     * connection is written with is judged only where a configured variant declares it, because
     * Laravel's `database` is a database name on every connection and a pool name only where a
     * pooler is in front. Nothing is compared here, and the reading says so rather than passing —
     * "not judged" and "judged, and well" must not be the same silence.
     */
    public function test_nothing_is_compared_when_no_variant_declares_a_pool_the_connection_names(): void
    {
        $direct = $this->shape(pools: ['write' => 'app', 'read' => []]);
        $renamed = $this->shape(pools: ['write' => 'other_db_9', 'read' => []]);

        foreach ([$direct, $renamed] as $shape) {
            $this->assertSame([], $shape->problems(), 'a name no variant declares is not a shape problem');
            $this->assertFalse($shape->judged());
            $this->assertStringContainsString('there was nothing to compare', $shape->summary());
        }

        $this->assertStringContainsString(
            'no configured variant declares a pool connection "pools" is written with',
            $direct->summary(),
        );
        $this->assertStringContainsString('"other_db_9"', $renamed->summary());

        // A connection with no name of its own at all is the other half of the same sentence.
        $unnamed = $this->shape(pools: ['write' => '', 'read' => []]);

        $this->assertFalse($unnamed->judged());
        $this->assertStringContainsString('names no pool of its own', $unnamed->summary());
    }

    /**
     * An empty path is the arming's problem rather than this reading's, and it is not reported here:
     * `pgcat files` and `pgcat gate` own "the flipper is armed without the paths a flip needs", and a
     * third sentence about the same unset key would be one installation described twice. What the
     * reading does with it is skip the file — it declares nothing, so nothing is held against it.
     */
    public function test_an_empty_path_is_the_arming_rows_problem_and_not_this_readings(): void
    {
        $shape = $this->shape(paths: ['no_readers_path' => '']);

        $this->assertSame([], $shape->problems());
        $this->assertTrue($shape->judged(), 'the other two files were still read');
        $this->assertStringContainsString('2 configured variants parse', $shape->summary());

        // And the files that *are* there are still judged, so an empty path cannot be the way an
        // installation silences the reading: the two variants it can read are held to the
        // connection's names, and the one it cannot is counted rather than passed over.
        $carryingIt = $this->shape(paths: ['readers_path' => '']);

        $this->assertSame([], $carryingIt->problems());
        $this->assertStringContainsString('2 configured variants parse', $carryingIt->summary());
    }

    /**
     * The installation under test: three files laid out in a temp directory, written as the case
     * asks, and the connection's own pool names. The default is the shape a flip can serve — the
     * write pool in every file, both read pools in the readers variant and neither in the
     * writer-only one — so a case changes only the half it is about.
     *
     * @param array<string, string> $paths     path overrides, e.g. a source that is not there
     * @param array{write: string, read: list<string>}|null $pools what the connection is written with
     */
    private function shape(
        string $inPlace = "[pools.writer_db_1]\n[pools.reader_db_1]\n[pools.reader_db_2]\n",
        string $readers = "[pools.writer_db_1]\n[pools.reader_db_1]\n[pools.reader_db_2]\n",
        string $noReaders = "[pools.writer_db_1]\n",
        array $pools = ['write' => 'writer_db_1', 'read' => ['reader_db_1', 'reader_db_2']],
        array $paths = [],
    ): VariantShape {
        $dir = $this->tempDir();

        $written = [
            'config_path' => $dir.'/pgcat.toml',
            'readers_path' => $dir.'/pgcat-readers.toml',
            'no_readers_path' => $dir.'/pgcat-no-readers.toml',
        ];

        foreach (['config_path' => $inPlace, 'readers_path' => $readers, 'no_readers_path' => $noReaders] as $setting => $body) {
            file_put_contents($written[$setting], $body);
        }

        return new VariantShape(
            [...$written, ...$paths],
            $pools,
            'pools',
            // The strict allowlist: one address resolves, and every other name does not — the
            // fixture discipline the boot's own prune test uses, so no case depends on this
            // machine's resolver.
            static fn (string $address): bool => $address === 'lpr-db-01',
        );
    }

    /**
     * The one problem of a kind, asserted to be the only one so a case cannot pass on a reading that
     * found something else as well.
     *
     * @return array{setting: string, role: string, kind: string, path: string, pool: string, severity: string, sentence: string}
     */
    private function onlyProblem(VariantShape $shape, string $kind): array
    {
        $problems = $this->problemsOfKind($shape, $kind);

        $this->assertCount(1, $problems, 'the case is about one problem of this kind: '.implode(' | ', array_column($shape->problems(), 'sentence')));

        return $problems[0];
    }

    /**
     * @return list<array{setting: string, role: string, kind: string, path: string, pool: string, severity: string, sentence: string}>
     */
    private function problemsOfKind(VariantShape $shape, string $kind): array
    {
        return array_values(array_filter(
            $shape->problems(),
            static fn (array $problem): bool => $problem['kind'] === $kind,
        ));
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/variant-shape-'.bin2hex(random_bytes(6));

        if (!mkdir($dir, 0o777, true) && !is_dir($dir)) {
            $this->fail("Could not create the temp directory {$dir}");
        }

        $this->tempDirs[] = $dir;

        return $dir;
    }
}
