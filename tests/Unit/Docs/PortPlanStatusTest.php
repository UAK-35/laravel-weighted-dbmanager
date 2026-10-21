<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Tests\Support\Handoff;
use Uak35\WeightedDbManager\Tests\Support\PortPlan;

/**
 * The port plan's status table, held to the tree it describes.
 *
 * WHY THIS EXISTS
 * ---------------
 *   `site-to-package-port-plan.md` states what each phase landed as, by commit, and that is the one
 *   sentence in the plan about this tree rather than about work to do. It is also the sentence
 *   nothing reads: the shas are seven characters of prose, so a rebase, a squash or a rewritten
 *   history turns a cell into a string that looks like a commit and names none — and no diff of the
 *   plan shows it, because the text is unchanged and the history moved under it. The same file can
 *   lose a phase from the table while keeping the phase above it, which is the drift in the other
 *   direction: work the plan describes and the reading does not.
 *
 *   So the table is read as data (`Tests\Support\PortPlan`, which raises rather than returning an
 *   empty reading) and compared in three directions:
 *
 *   1. every `## Phase N` heading the plan declares has a row in the status table, and every
 *      numerically numbered row names a phase the plan still declares;
 *   2. every commit the table names — and the one its own heading is taken at — resolves here;
 *   3. each of those commits is an ancestor of `HEAD`, because the column says *landed in* and a
 *      commit on a branch nobody merged has not landed anywhere this table can point at.
 *
 *   The second reading is asked of `Handoff::git()`, the one git invocation this suite has: colour
 *   off, no pager, no prompt, and no signature lines pasted into the output a test parses.
 *
 *   What this file does not do is judge the cells' prose. `Read it in` names classes, rows and records
 *   whose own guards hold them; a status table that describes a phase in the wrong words is a decision
 *   for whoever re-takes the section, not something a reader of the commit column can answer.
 */
final class PortPlanStatusTest extends TestCase
{
    public function test_every_phase_the_plan_declares_has_a_row_in_the_status_table(): void
    {
        $declared = PortPlan::phases();
        $rowed = [];

        foreach (PortPlan::rows() as $row) {
            $label = trim(str_replace('`', '', $row['Phase']));

            if (preg_match('/^\d+$/', $label) === 1) {
                $rowed[(int) $label] = $row;
            }
        }

        $missing = array_values(array_diff($declared, array_keys($rowed)));

        self::assertSame([], $missing, sprintf(
            'The plan declares %s, and its status table has no row for %s. A phase is added above the '
            .'table and read back in it — a phase with no row is work nothing in this file records as '
            .'landed or still open.',
            self::written($declared),
            self::written($missing),
        ));

        $stale = array_values(array_diff(array_keys($rowed), $declared));

        self::assertSame([], $stale, sprintf(
            'The status table has a row for %s, which the plan no longer declares as a phase. A row for '
            .'a phase that is not there is a reading of a plan that has moved on.',
            self::written($stale),
        ));
    }

    public function test_every_commit_the_status_names_is_one_this_tree_holds(): void
    {
        $problems = [];

        foreach (PortPlan::commits() as $commit) {
            $resolved = Handoff::git('rev-parse', '--verify', '--quiet', $commit['sha'].'^{commit}');

            if ($resolved['exit'] !== 0) {
                $problems[] = sprintf(
                    '%s names %s, which this repository does not have (%s).',
                    $commit['where'],
                    $commit['sha'],
                    trim($resolved['error']) === '' ? 'git: no such commit' : trim($resolved['error']),
                );

                continue;
            }

            $landed = Handoff::git('merge-base', '--is-ancestor', $commit['sha'], 'HEAD');

            if ($landed['exit'] !== 0) {
                $problems[] = sprintf(
                    '%s names %s, which is not an ancestor of HEAD (git exit %d) — the column says the '
                    .'work landed in this tree, and a commit this branch does not carry has not.',
                    $commit['where'],
                    $commit['sha'],
                    $landed['exit'],
                );
            }
        }

        self::assertSame([], $problems, sprintf(
            "the port plan's status table names a commit this tree does not hold:\n  - %s\nA rewritten "
            ."history makes every sha in the table stale at once; re-read the section at the commit each "
            .'phase is still reachable from, rather than deleting the cells.',
            implode("\n  - ", $problems),
        ));
    }

    /**
     * The numbers in a sentence a person reads: `1, 2 and 7`.
     *
     * @param list<int> $numbers
     */
    private static function written(array $numbers): string
    {
        $numbers = array_map(static fn (int $number): string => (string) $number, $numbers);

        if (count($numbers) <= 1) {
            return implode('', $numbers);
        }

        $last = array_pop($numbers);

        return implode(', ', $numbers).' and '.$last;
    }
}
