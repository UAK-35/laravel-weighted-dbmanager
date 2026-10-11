<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Uak35\WeightedDbManager\Tests\Support\ReleaseRepo;

require_once dirname(__DIR__, 3) . '/bin/surface.php';

/**
 * Who writes each derived file, held to the one declaration in `bin/surface.php`.
 *
 * WHY THIS EXISTS
 * ---------------
 *   The list names the files derived from the record. It did not name the commands that write them,
 *   so "which command do I run to refresh this?" was answerable only by reading three programs:
 *   `bin/release.php` wrote all four, `bin/inventory.php` the record and the page, `bin/api.php` the
 *   page alone. A fifth artefact could then be added to the list and be written by no command a
 *   reader would reach for — which is the failure the list itself was introduced to end, one artefact
 *   further out: a derived file left describing the release before it, with every part of the
 *   machinery working as documented.
 *
 *   So each row carries `written_by`, each command reads its own rows (`derivedBy()`), and this test
 *   holds the declaration to what the commands do rather than to what it says. The measurement is the
 *   one that cannot be argued with: every derived file is put out of step — a row forged into each of
 *   the three records, a marker written into the page — a command is run, and each file is then asked
 *   whether it was repaired. A file the command's rows do not name must still be holding its forgery
 *   afterwards, which is what makes this a test of who writes what rather than of whether the command
 *   ran.
 *
 *   The ref case is the one scope rule the measurement has to carry: `--at=REF` writes a *record*,
 *   and the page is rendered from the record on disk, so a run describing another tree's rows leaves
 *   the page alone. That is a row's `from` field deciding, so the expectation is read off the same
 *   declaration rather than written down beside it.
 */
final class DerivedWritersTest extends TestCase
{
    private const ADDED = "### Added\n\n- A command nobody could run before.\n";

    /** The marker each file is dirtied with, and the word its absence means repaired. */
    private const DIRT = [
        'files.tsv' => 'src/Forged.php',
        'methods.tsv' => 'forged',
        'surface.tsv' => 'FORGED_SURFACE_ROW',
        'API.md' => 'forged page marker',
    ];

    /**
     * The declaration is closed over its own vocabulary: every writer a row claims is a command in
     * `derivedWriters()`, every command there writes at least one artefact, and every artefact names
     * at least one writer. Without the last, an artefact could be declared, written by nobody, and
     * read as covered — which is the state this test is here to catch rather than the one it fixes.
     */
    public function test_every_row_and_every_writer_agree_about_who_writes_what(): void
    {
        $root = ReleaseRepo::make()->root();
        $writers = derivedWriters();
        $rows = derivedFiles($root);

        $this->assertNotSame([], $writers, 'the writers table has to name commands for this to assert anything');
        $this->assertNotSame([], $rows, 'the list has to have rows for this to assert anything');

        $claimed = [];

        foreach ($rows as $key => $artefact) {
            $this->assertNotSame([], $artefact['written_by'], "{$key} names no writer, so no command would refresh it");

            foreach ($artefact['written_by'] as $writer) {
                $this->assertArrayHasKey($writer, $writers, "{$key} names {$writer}, which is not a command in derivedWriters()");
                $claimed[$writer] = true;
            }

        }

        foreach ($writers as $writer => $command) {
            $this->assertArrayHasKey($writer, $claimed, "{$command} is declared a writer and writes no artefact");
            $this->assertSame(
                array_values(array_filter(
                    array_keys($rows),
                    static fn (string $key): bool => in_array($writer, $rows[$key]['written_by'], true),
                )),
                array_keys(derivedBy($writer, $root)),
                "derivedBy() and the rows disagree about what {$command} writes",
            );
        }
    }

    /** A name the table does not carry is refused, not answered with an empty list that writes nothing. */
    public function test_a_writer_that_is_not_declared_is_refused(): void
    {
        $root = ReleaseRepo::make()->root();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bin/surface.php');

        derivedBy('bin/weighing.php', $root);
    }

    /**
     * `bin/api.php` writes the page and nothing else: the record it renders is the one on disk, and a
     * command that rewrote it would be publishing a second, unweighed record under a stale stamp.
     */
    public function test_api_php_writes_the_page_and_leaves_the_record_alone(): void
    {
        $repo = self::dirtied(commit: false);

        $run = $repo->scriptRun('api.php');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertWrote('api', $repo);
    }

    /**
     * A plain `bin/inventory.php` refreshes the record and the page rendered from it — both rows name
     * it — which is the pair a hand run used to leave half-written.
     */
    public function test_a_plain_inventory_run_writes_the_record_and_the_page(): void
    {
        $repo = self::dirtied(commit: false);

        $run = $repo->scriptRun('inventory.php');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertWrote('inventory', $repo);
    }

    /** A run against a ref writes the record for that tree and leaves the page the checkout ships. */
    public function test_a_run_against_a_ref_writes_the_record_and_leaves_the_page(): void
    {
        $repo = self::dirtied(commit: false);

        $run = $repo->scriptRun('inventory.php', '--at=v1.0.0');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertWrote('inventory', $repo, ref: true);
    }

    /** The release writes every derived file — because every row names it — in the commit it tags. */
    public function test_the_release_writes_every_file_its_rows_declare(): void
    {
        $repo = self::dirtied(commit: true);

        // The forgery is a removal the notes do not account for — that rail is another test's — so
        // the run is told to release anyway; what is being measured here is the four files.
        $run = $repo->release('--weigh', '--yes', '--skip-ci', '--allow-silent-notes');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertWrote('release', $repo);
    }

    /**
     * A package with a record, a page and a tag, with every derived file put out of step: three rows
     * forged into the record and a marker written into the page.
     *
     * `$commit` is the release's precondition rather than a detail — a release refuses a dirty tree —
     * so the case that drives the release commits the forgery it is about to watch being repaired.
     */
    private static function dirtied(bool $commit): ReleaseRepo
    {
        $repo = ReleaseRepo::make(self::ADDED);
        $repo->tag('v1.0.0');
        $repo->refreshInventory();
        $repo->script('api.php');
        $repo->commit('chore: the record and the page at v1.0.0');

        // One row per file, in the shape that file holds, so the record stays readable and the
        // forgery is a row rather than a corruption: what is being watched is a rewrite, not a repair.
        $repo->write('files.tsv', $repo->read('files.tsv') . "Forged\tsrc/Forged.php\tForged\n");
        $repo->write('surface.tsv', $repo->read('surface.tsv') . "constant\tFORGED_SURFACE_ROW\tsrc/Forged.php\n");
        $repo->inventPublicMethod('Fixture\\Forged', 'forged', 'src/Forged.php');
        $repo->write('API.md', "# A page for another release\n\nforged page marker\n");

        if ($commit) {
            $repo->commit('chore: a record and a page describing nothing');
        }

        return $repo;
    }

    /**
     * The measurement: every file the command's rows declare has been repaired, and every derived file
     * they do not declare still holds its forgery. `$ref` is the record-only scope a run against a ref
     * has, read off the declaration's `from` rather than restated here.
     */
    private function assertWrote(string $writer, ReleaseRepo $repo, bool $ref = false): void
    {
        $declared = [];

        foreach (derivedBy($writer, $repo->root()) as $artefact) {
            if ($ref && $artefact['from'] === 'record') {
                continue;
            }

            foreach ($artefact['files'] as $path) {
                $declared[] = basename($path);
            }
        }

        // Every derived file this test knows how to dirty has to be one the declaration knows about,
        // and vice versa: an artefact added to the list without a marker here would be measured by
        // nothing, and this assertion is what makes that a failing test rather than a quiet gap.
        $union = array_unique(array_merge($declared, array_keys(self::DIRT)));
        $expected = array_keys(self::DIRT);

        sort($union);
        sort($expected);

        $this->assertSame(
            $expected,
            $union,
            'the declaration and the files this test dirties have to be the same set, or the assertion below proves nothing',
        );

        foreach (self::DIRT as $file => $marker) {
            $repaired = !str_contains($repo->read($file), $marker);

            $this->assertSame(
                in_array($file, $declared, true),
                $repaired,
                sprintf(
                    '%s was %s by %s, and the declaration %s it',
                    $file,
                    $repaired ? 'repaired' : 'left as it was',
                    derivedWriters()[$writer],
                    in_array($file, $declared, true) ? 'names it' : 'does not name it',
                ),
            );
        }
    }
}
