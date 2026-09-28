<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Console;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Uak35\WeightedDbManager\Console\JsonEnvelope;
use Uak35\WeightedDbManager\Tests\Support\Readme;

/**
 * The envelope itself: the five keys every report leads with, and the two ways a report can be
 * written wrong.
 *
 * The commands that use it are tested over their own matrices — every route, every kind, every
 * code — and what is left for this file is the part they share: that the keys are written in the
 * order a reader expects and with the values a command did not supply, that a per-key default is
 * what fills an absent key rather than a guess, and that the two mistakes a command can make in
 * its own report are refused rather than printed. Those two are quiet failures by nature: a
 * mistyped evidence key (`replica` for `replicas`) would publish less than the route meant to
 * while every command test stayed green, because the object would still be well-formed JSON.
 *
 * No application is booted. The envelope writes an array to an output and answers the code it was
 * given, and nothing about that needs a container.
 *
 * The register is this file's other half: which commands write a report, the key each one's verdict
 * is read by, and the words two of them may share. Both of those are read back out of the package —
 * the tables out of `README.md`, the commands out of `src/` — so the declaration and the package
 * cannot disagree about which reports exist or about what they spell.
 */
final class JsonEnvelopeTest extends TestCase
{
    /**
     * The list a reader binds to, in the order it is written. It is asserted as a list rather than
     * as a set because the order is the envelope's whole point: a failing CI step's output shows
     * the verdict and the code before it shows anything else, whatever command produced it.
     */
    public function test_every_report_leads_with_the_same_five_keys(): void
    {
        $this->assertSame(
            ['command', 'kind', 'exit_code', 'reason', 'error'],
            array_keys(JsonEnvelope::CORE),
        );

        $output = new BufferedOutput();

        $this->assertSame(3, JsonEnvelope::write($output, 'db:test', ['evidence' => null], [], 3));

        // Read once: `fetch()` empties the buffer, and a report is one object rather than a stream.
        $report = $this->report($output);

        $this->assertSame(
            ['command', 'kind', 'exit_code', 'reason', 'error', 'evidence'],
            array_keys($report),
            'the envelope first, then the command\'s own evidence',
        );

        $this->assertSame([
            'command' => 'db:test',
            'kind' => null,
            'exit_code' => 3,
            'reason' => null,
            'error' => null,
            'evidence' => null,
        ], $report, 'the code is inside the object, and a route that has nothing for a key writes null');
    }

    /**
     * An absent key takes the value its command declared — `null` for a fact, an empty list for a
     * list — and a key the route did supply keeps the route's value, empty or not.
     */
    public function test_an_absent_key_takes_the_default_the_command_declared(): void
    {
        $output = new BufferedOutput();

        JsonEnvelope::write(
            $output,
            'db:test',
            ['rows' => [], 'fact' => null, 'counts' => ['failed' => 0]],
            ['rows' => [], 'counts' => ['failed' => 2]],
            0,
        );

        $report = $this->report($output);

        $this->assertSame([], $report['rows'], 'a route that supplied an empty list keeps it');
        $this->assertNull($report['fact'], 'a fact a route did not supply is null, not absent');
        $this->assertSame(['failed' => 2], $report['counts'], 'a default is a shape, not an override');
    }

    /**
     * A report naming a key its command did not declare is a mistake in the command, and it is
     * refused: the alternative is an object that is missing a fact the route meant to publish, with
     * nothing anywhere saying so.
     */
    public function test_a_report_naming_an_undeclared_key_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/reported \[replica\]/');

        JsonEnvelope::write(new BufferedOutput(), 'db:test', ['replicas' => []], ['replica' => []], 0);
    }

    /**
     * And a command cannot declare one of the envelope's own keys as its evidence: five of the keys
     * are this class's, and a second writer would be two answers to one question.
     */
    public function test_a_command_cannot_take_one_of_the_envelopes_own_keys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/declares \[kind\] as evidence/');

        JsonEnvelope::write(new BufferedOutput(), 'db:test', ['kind' => null], ['kind' => 'flipped'], 0);
    }

    /**
     * The write is raw, and that is a decision with a failure mode: a console tag in a value — a
     * path with angle brackets, a supervisor's own output — would be read as formatting and dropped
     * on the way out of a machine-readable channel. A decorated output is the one that does it, so a
     * decorated output is what this asserts against.
     */
    public function test_a_value_is_written_through_a_decorated_output_unchanged(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);

        JsonEnvelope::write($output, 'db:test', ['note' => null], ['note' => '<fg=red>a tag</>'], 0);

        $this->assertSame('<fg=red>a tag</>', $this->report($output)['note']);
    }

    /**
     * Every command that writes a report is in the register, with the key its verdict is read by
     * and the table its words are documented in — and the register is complete, because
     * completeness is the half that rots.
     *
     * Both directions are read out of the package rather than restated here. The table an entry
     * names is parsed with the column it names, so a heading that moved or a column no table has is
     * reported by `Readme` instead of agreeing with an empty list, and the commands are found by
     * reading `src/Console/Commands/` for the two ways a report is written in this package: through
     * the envelope, or by hand in the one case that does. A fourth report command therefore cannot
     * be written without an entry, and an entry cannot name a command that writes no report.
     */
    public function test_the_register_names_every_command_that_writes_a_report(): void
    {
        foreach (JsonEnvelope::REPORTS as $command => $report) {
            $this->assertNotSame(
                [],
                Readme::table($report['table'], $report['key']),
                sprintf('%s declares [%s] as the key its verdict is read by, so its documented table holds one', $command, $report['key']),
            );
        }

        // A set rather than a list: which commands the register names is the contract, the order
        // they sit in is not — a reader looks an entry up by its command.
        $this->assertEqualsCanonicalizing(
            array_keys(JsonEnvelope::REPORTS),
            self::commandsThatWriteAReport(),
            'every command that writes a report is registered, and the register names no command that writes none',
        );
    }

    /**
     * One word, one meaning: the only verdict words two reports may share are the ones the register
     * declares as shared.
     *
     * This is the check `docs/command-json-envelope.md` had been carrying as a limitation — nothing
     * compared the vocabularies, because no artefact held the set of words they used — and it
     * becomes a comparison the moment the artefact exists. The words are read out of the documented
     * tables, every pair of reports is intersected, and the intersections must be exactly the
     * declared shared words in both directions: a second word two commands happen to spell the same
     * way fails here, and so does a declared shared word that no two commands really share.
     */
    public function test_a_word_two_reports_share_is_one_the_register_declares(): void
    {
        $words = [];

        foreach (JsonEnvelope::REPORTS as $command => $report) {
            foreach (Readme::table($report['table'], $report['key']) as $row) {
                $words[$command][] = Readme::plain($row[$report['key']]);
            }
        }

        $shared = [];

        foreach (array_keys($words) as $one) {
            foreach (array_keys($words) as $other) {
                if ($one >= $other) {
                    continue;
                }

                $shared = array_merge($shared, array_intersect($words[$one], $words[$other]));
            }
        }

        $this->assertEqualsCanonicalizing(
            array_keys(JsonEnvelope::SHARED_KINDS),
            array_values(array_unique($shared)),
            'a word two reports share is declared as shared, and a declared shared word is one two reports really share',
        );
    }

    /**
     * The commands whose own source writes a report: through the envelope, or by hand in the one case
     * that does. Read out of `src/` rather than listed here, because the failure this is built on is a
     * report command being written and the register never being told.
     *
     * @return list<string>
     */
    private static function commandsThatWriteAReport(): array
    {
        $commands = [];

        foreach (glob(dirname(__DIR__, 3).'/src/Console/Commands/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);

            if (! str_contains($source, 'JsonEnvelope::write(') && ! str_contains($source, 'OUTPUT_RAW')) {
                continue;
            }

            if (preg_match("/\\\$signature = '([^\\s']+)/", $source, $matches) === 1) {
                $commands[] = $matches[1];
            }
        }

        return $commands;
    }

    /**
     * The report an envelope wrote, decoded — and, with `JSON_THROW_ON_ERROR`, an assertion in
     * itself: output that is not one JSON object fails here rather than in every reader.
     *
     * @return array<string, mixed>
     */
    private function report(BufferedOutput $output): array
    {
        $decoded = json_decode(trim($output->fetch()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded, 'a report is one object');

        return $decoded;
    }
}
