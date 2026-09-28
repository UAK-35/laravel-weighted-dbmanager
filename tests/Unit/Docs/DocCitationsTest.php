<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * The tests the records cite, checked against the tests this package declares.
 *
 * WHY THIS EXISTS
 * ---------------
 * A record that points at the test pinning a rule is how a reader checks the claim instead of
 * trusting it — and until now nothing read those pointers. Twenty-two of them had rotted: a
 * record that named a test the class does not declare, or a test that had been renamed when the
 * rule it pins grew a case, sent the reader to an empty search. Every one of those records was
 * otherwise accurate, which is what makes the drift quiet: the prose asserted a test, and the
 * suite asserted the behaviour, and nothing asserted the name the two met at.
 *
 * The same class of defect as a count stated in prose — a restatement nobody compares with the
 * code — with a name instead of a number, and it is guarded the same way: the corpus is read as
 * data, and what it names is compared with the classes that exist. See
 * [docs/prose-numbers.md](../../../docs/prose-numbers.md).
 *
 * Two things are checked, and the second is the one a grep cannot do:
 *
 *   1. every `test_…` name a record cites is declared by some class under `tests/`;
 *   2. where the record names the class — `FooTest::test_bar` — that class is one of the classes
 *      declaring it, because a name that resolves to the *wrong* class is worse than one that does
 *      not resolve: the reader finds a test, runs it, and learns about a different rule.
 *
 * The limit is the naming convention: a citation is recognised by its `test_` prefix, which is the
 * convention every class in this suite uses, and a method asserted by an attribute under another
 * name would not be read. Nothing is skipped silently — a file that cannot be read raises, and the
 * failures are reported together, one line per citation, because the repair is a sweep rather than
 * a single edit.
 */
final class DocCitationsTest extends TestCase
{
    /** The records read: the design records, and the README's own references. */
    private const CORPUS = ['README.md', 'RELEASING.md'];

    /** Where the declared tests are looked for. */
    private const TESTS = 'tests';

    /**
     * Every cited test name resolves, and every cited class is one that declares it.
     */
    public function test_every_test_a_record_cites_is_one_this_package_declares(): void
    {
        $declared = self::declaredTests();
        $citations = self::citations();

        $this->assertNotSame([], $declared, 'the classes under tests/ have to be readable for this to assert anything');

        $this->assertNotSame([], $citations, 'the records have to cite tests for this to assert anything');

        $problems = [];

        foreach ($citations as $citation) {
            [$record, $line, $name, $class] = $citation;

            if (! isset($declared[$name])) {
                $problems[] = sprintf('%s line %d cites %s, which no test class declares.', $record, $line, $name);

                continue;
            }

            if ($class !== null && ! in_array($class, $declared[$name], true)) {
                $problems[] = sprintf(
                    '%s line %d cites %s::%s, but %s is declared by %s.',
                    $record,
                    $line,
                    $class,
                    $name,
                    $name,
                    implode(', ', $declared[$name]),
                );
            }
        }

        $this->assertSame([], $problems, "a record cites a test this package does not declare:\n".implode("\n", $problems));
    }

    /**
     * The test methods this package declares, by name — and every class that declares one.
     *
     * A name rather than `Class::name` because a record is free to cite either; the class is
     * checked only where the record names one, so a citation that gives no class can still be
     * resolved, and one that gives the wrong class is caught.
     *
     * @return array<string, list<string>>
     */
    private static function declaredTests(): array
    {
        $declared = [];

        foreach (self::phpFiles(self::TESTS) as $file) {
            $source = (string) file_get_contents($file->getPathname());

            // `class` does not have to open the line: one test class in this suite is declared on
            // the line that closes its docblock (`*/class Foo …`), which PHP reads and a stricter
            // pattern would skip — silently, taking all of that file's names with it. A file that
            // declares tests and no class the guard can read raises instead, because a skipped
            // file here looks exactly like a set of names that do not exist.
            preg_match('/(?:^|\*\/)[ \t]*(?:final )?(?:abstract )?class (\w+)/m', $source, $class);

            preg_match_all('/function (test\w+)\(/', $source, $methods);

            if ($methods[1] !== [] && ! isset($class[1])) {
                throw new RuntimeException(
                    "{$file->getPathname()} declares tests and no class this guard can read, so its names would "
                    .'look like names that do not exist',
                );
            }

            foreach ($methods[1] as $method) {
                $declared[$method][] = $class[1];
            }
        }

        return $declared;
    }

    /**
     * Every `test_…` a record cites: the record, the line, the name, and the class when it names one.
     *
     * @return list<array{0: string, 1: int, 2: string, 3: string|null}>
     */
    private static function citations(): array
    {
        $citations = [];

        foreach (self::records() as $record) {
            foreach (explode("\n", (string) file_get_contents($record)) as $index => $line) {
                preg_match_all('/(?:([A-Z][A-Za-z0-9_]*Test)::)?\b(test_[a-z0-9_]+)\b/', $line, $matches, PREG_SET_ORDER);

                foreach ($matches as $match) {
                    $citations[] = [$record, $index + 1, $match[2], $match[1] === '' ? null : $match[1]];
                }
            }
        }

        return $citations;
    }

    /**
     * The records: every design record, plus the two documents at the root that cite tests.
     *
     * @return list<string>
     */
    private static function records(): array
    {
        $records = self::CORPUS;

        foreach (glob(dirname(__DIR__, 3).'/docs/*.md') ?: [] as $record) {
            $records[] = 'docs/'.basename($record);
        }

        sort($records);

        return $records;
    }

    /**
     * The PHP files under a directory, recursively, as paths relative to the package root.
     *
     * @return list<SplFileInfo>
     */
    private static function phpFiles(string $directory): array
    {
        $root = dirname(__DIR__, 3).'/'.$directory;

        if (! is_dir($root)) {
            throw new RuntimeException("the guard reads {$root}, and it is not there");
        }

        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }
}
