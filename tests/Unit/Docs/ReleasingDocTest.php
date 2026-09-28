<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Tests\Support\ReleasingDoc;

/**
 * The rails table in RELEASING.md, held to the two things it claims about itself: every rail
 * names the test that proves it, and every test it names is one this package has.
 *
 * Both directions matter, and for different reasons. A row with an empty test column is a rail
 * that was added or edited without evidence — the table's own header now says a row without one
 * is a rail nobody has driven, so the table would be contradicting itself. A row naming a test
 * that does not exist is worse than an empty one: it reads as proof, and the reader who trusts
 * it has no way to find out that the test was renamed two releases ago.
 *
 * Neither is checked by running the named tests — that is the suite's job, and a doc guard that
 * tried would be a second test runner. What is checked is that the names resolve to methods a
 * class in `tests/` declares, which is the property a rename breaks.
 */
final class ReleasingDocTest extends TestCase
{
    public function test_every_rail_names_the_test_that_proves_it(): void
    {
        $rails = ReleasingDoc::rails();

        $unproved = [];

        foreach ($rails as $rail) {
            if (preg_match('/`test_[A-Za-z0-9_]+`/', $rail['proved_by']) !== 1) {
                $unproved[] = sprintf('line %d (%s)', $rail['source'], $rail['rail']);
            }
        }

        $this->assertSame(
            [],
            $unproved,
            "These rails name no test, so nothing in the suite would notice if the rail stopped working:\n  - "
            .implode("\n  - ", $unproved),
        );
    }

    public function test_every_test_the_file_names_is_one_this_package_declares(): void
    {
        $declared = ReleasingDoc::declaredTests();

        $missing = [];

        foreach (ReleasingDoc::namedTests() as $named) {
            if (! isset($declared[$named['name']])) {
                $missing[] = sprintf(
                    'RELEASING.md line %d names %s, which no test class under tests/ declares.',
                    $named['source'],
                    $named['name'],
                );
            }
        }

        $this->assertSame(
            [],
            $missing,
            "RELEASING.md names tests that are not in this package — a rename leaves the row quoting a name nobody runs:\n  - "
            .implode("\n  - ", $missing),
        );
    }

    /**
     * The guard's own premise, asserted rather than assumed: a reader that found no rails, or
     * no tests to look them up in, would pass both tests above by knowing nothing. The parser
     * throws in those cases, which is the point — this says so out loud, so a table that is
     * emptied or a `tests/` directory that is moved is a failure rather than a green run.
     */
    public function test_the_reader_refuses_to_report_agreement_with_a_file_it_could_not_read(): void
    {
        $this->assertNotSame([], ReleasingDoc::rails(), 'RELEASING.md has no rails table to read.');
        $this->assertNotSame([], ReleasingDoc::declaredTests(), 'No test methods were read from tests/.');
        $this->assertNotSame([], ReleasingDoc::namedTests(), 'RELEASING.md names no test at all.');
    }
}
