<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Console;

use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The envelope every `--json` report in this package is written in.
 *
 * WHY THIS IS ONE CLASS
 * ---------------------
 *   A pipeline does not branch on a rendered report; it branches on two facts — what happened, and
 *   what the process exited with — and it gets them from one object. Three commands in this package
 *   answer a question a pipeline asks (`db:pgcat-flip`, `db:probe-replicas`, `db:replica-status`),
 *   and each of them writing its own object would mean three key sets to keep in step and three
 *   spellings of "here is the verdict". The keys that are the *answer* therefore live here, once:
 *
 *     command     the command that produced the object, so a stored report says what it is
 *     kind        the verdict, from the command's own closed vocabulary
 *     exit_code   the code the process exits with — inside the object, so the two halves of the
 *                 answer travel together and a job reading a saved report never has to reconcile
 *                 it with a `$?` from somewhere else
 *     reason      the sentence a refusal would otherwise have printed down a human channel
 *     error       the failure, when the verdict is one
 *
 *   Everything else is the command's own evidence, and the command declares it: a class constant of
 *   `key => the value an absent one takes`, so the object's shape is stated in one place per command
 *   rather than assembled by however many branches remembered to add a key. Every declared key is
 *   written on every route, `null` or an empty list where the route has nothing for it, which is the
 *   rule the health payload's `counts` follows: a consumer that has to ask whether a field exists is
 *   a consumer that can be written wrong once and stay wrong.
 *
 * WHY THE ORDER IS FIXED
 * ----------------------
 *   `command`, `kind` and `exit_code` first, then the two sentences, then the evidence — the same
 *   five positions in every report the package writes. JSON object order carries no meaning, so this
 *   is a rule about reading: a person looking at a failing CI step's output sees the verdict and the
 *   code without scrolling, and the tests can assert the key list as a list.
 *
 * WHAT IT REFUSES
 * ---------------
 *   A report naming a key the command did not declare throws rather than printing it. That is the
 *   one failure this class can catch on its own, and it is the quiet one: a mistyped evidence key
 *   (`replica` for `replicas`) would otherwise produce an object that is missing a fact the route
 *   meant to publish, and nothing anywhere would say so. A command that declares one of the five
 *   core keys as its own evidence throws too — those five are written by this class and a second
 *   writer would be two answers to one question.
 */
final class JsonEnvelope
{
    /**
     * The keys every report carries, in the order they are written, with the value an absent one
     * takes. `command` and `exit_code` are supplied by `write()`; the other three come from the
     * route's report when it has them.
     */
    public const CORE = [
        'command' => null,
        'kind' => null,
        'exit_code' => null,
        'reason' => null,
        'error' => null,
    ];

    /**
     * Every command in this package that writes a report: the key its run verdict is written in,
     * and the README table its closed vocabulary is documented in.
     *
     * WHY THE VOCABULARY NEEDS ONE PLACE AS WELL
     * ------------------------------------------
     *   The five keys above are one half of this package's report contract; the other half is the
     *   verdict that fills one of them — a word from a vocabulary the command closes, so that a
     *   scheduler can branch on it. Each command documented its own words and each command's test
     *   read its own table back, which binds a table to a command and leaves the question the
     *   doctor's record named unanswered: does any word appear on two reports, and is it the same
     *   word for the same thing? Answering that meant holding four documents in your head at once.
     *   This is the one place that answers it, and it is the place a command writes its report
     *   through, so the answer is declared rather than derived from the tables it names.
     *
     *   The key is per report, and this is the whole of it: the flip, the sweep and the
     *   distribution name the run's verdict `kind`, and `db:doctor` names it `verdict` — the decision
     *   [command-json-envelope.md](../../docs/command-json-envelope.md) records as candidate F,
     *   whose reason is that one word at two scopes on the doctor's page is the thing a rename would
     *   put two names on. Declaring it here rather than leaving it as an exception is what makes
     *   this register complete: a consumer reads one document to learn what each of the package's
     *   reports calls its verdict.
     *
     *   Where the exit code lives is the one thing the package's reports do not differ on, and it is
     *   `write()` below: every report carries the code the process exits with inside the object, so a
     *   saved report answers what happened and what to branch on without a second artefact.
     */
    public const REPORTS = [
        'db:pgcat-flip' => ['key' => 'kind', 'table' => '### The JSON report: one object for a pipeline'],
        'db:probe-replicas' => ['key' => 'kind', 'table' => '### Probing: `db:probe-replicas`'],
        'db:replica-status' => ['key' => 'kind', 'table' => '### Reading the distribution: `db:replica-status`'],
        'db:doctor' => ['key' => 'verdict', 'table' => '### The preflight as data: db:doctor --json'],
        'db:pgcat-window-flip' => ['key' => 'kind', 'table' => '### The reader-window tasks: `db:pgcat-window-flip`'],
    ];

    /**
     * The verdict words two reports may legitimately share; every other word a registered table
     * documents belongs to one command alone.
     *
     * WHY A WORD MAY BE SHARED AT ALL
     * -------------------------------
     *   A word that means one thing on two reports is not a collision, it is the same repair — and a
     *   consumer that already handles it should not have to learn a second spelling for it.
     *   `unbound` is that word: the container has no weighted manager, which is one fact about one
     *   installation whether a sweep or a flip is the thing that went looking for it. The flip
     *   reaches it through its own vocabulary, so the dependency it names can be the flipper rather
     *   than the manager, and both are the same row of the same preflight.
     *
     *   The window flip is the second kind of sharing, and the one that widened this constant past a
     *   single word: it is a second entry point to the same flip, so the outcomes it shares with
     *   `db:pgcat-flip` are genuinely the flip's — a mode that applied (`flipped`), a mode already
     *   on record (`no_change`), the lock held elsewhere (`skipped`), a step that did not work
     *   (`failed`), flipping turned off (`disabled`), a request refused before it started
     *   (`refused`). Each is one fact with one meaning, decided the same way, whether the time of
     *   day or the boot window is what asked for it; a pipeline that already branches on `flipped`
     *   should not have to learn a second spelling for it because a second entry point produced it.
     *
     *   Nothing else is shared, and that is kept apart on purpose: `no_read_list` and `no_replicas`
     *   are different sentences about different subjects, and naming them one word would be a
     *   consumer's problem rather than a tidiness. This constant is what keeps the rule checkable
     *   rather than remembered — `JsonEnvelopeTest` reads every registered table and refuses a word
     *   two of them share unless it is declared here, so a second shared spelling arrives as a
     *   decision instead of as a coincidence a reader notices later.
     */
    public const SHARED_KINDS = [
        'unbound' => 'the container has no weighted manager, or — for the flip — no flipper',
        'disabled' => 'flipping is off, or pgcat cannot front this connection\'s driver',
        'refused' => 'a flag, or a combination of them, was refused before anything was read',
        'no_change' => 'the mode on record already matched, so there was nothing to do',
        'flipped' => 'a flip applied',
        'skipped' => 'another flipper instance held the lock',
        'failed' => 'a step a flip needs did not work',
    ];

    /**
     * Write one report — this command's envelope, then its evidence — and answer the exit code.
     *
     * The write is raw: a machine-readable channel must not have an angle bracket in a path or a
     * supervisor's message read as a console tag and dropped on the way out. It is pretty-printed,
     * because a failing CI step's output is read by a person as often as by a script, and `jq` does
     * not care either way.
     *
     * @param array<string, mixed> $evidence the keys this command's report carries, each with the
     *                                       value an absent one takes
     * @param array<string, mixed> $report   the route's own evidence, `kind` among it
     */
    public static function write(
        OutputInterface $output,
        string $command,
        array $evidence,
        array $report,
        int $exitCode,
    ): int {
        self::assertDeclared($command, $evidence, $report);

        $payload = self::CORE;

        $payload['command'] = $command;
        $payload['exit_code'] = $exitCode;

        foreach ($evidence as $key => $absent) {
            $payload[$key] = $report[$key] ?? $absent;
        }

        foreach (['kind', 'reason', 'error'] as $key) {
            $payload[$key] = $report[$key] ?? null;
        }

        $output->writeln(
            (string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
            OutputInterface::OUTPUT_RAW,
        );

        return $exitCode;
    }

    /**
     * A command's evidence may not take a core key, and a report may not carry a key the command
     * has not declared.
     *
     * Both are mistakes in the command rather than in a run, so they are found by the suite that
     * writes every route rather than by an operator reading one object.
     *
     * @param array<string, mixed> $evidence
     * @param array<string, mixed> $report
     */
    private static function assertDeclared(string $command, array $evidence, array $report): void
    {
        foreach (array_keys($evidence) as $key) {
            if (array_key_exists($key, self::CORE)) {
                throw new InvalidArgumentException(
                    "{$command} declares [{$key}] as evidence, and it is one of the envelope's own keys — "
                    .'the envelope writes it, and two writers would be two answers to one question',
                );
            }
        }

        foreach (array_keys($report) as $key) {
            if (! array_key_exists($key, self::CORE) && ! array_key_exists($key, $evidence)) {
                throw new InvalidArgumentException(
                    "{$command} reported [{$key}], which its evidence does not declare — an undeclared key "
                    .'would be written by nobody, so the route would publish less than it meant to',
                );
            }
        }
    }
}
