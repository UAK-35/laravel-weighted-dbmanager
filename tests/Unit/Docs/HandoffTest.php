<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Tests\Support\Handoff;
use Uak35\WeightedDbManager\Tests\Support\NumberWords;
use Uak35\WeightedDbManager\Tests\Support\SkillDescriptions;

/**
 * HANDOFF.md against the tree it describes.
 *
 * WHY THIS EXISTS
 * ---------------
 *   The note is the only record here that describes a moment rather than a rule, and it was the
 *   only one nothing read: everything else in this package is compared with the code by a test,
 *   and a snapshot that nobody checks is one that rots silently and is trusted anyway — which is
 *   worse than one that is missing, because the reader has no reason to doubt it. It had in fact
 *   already rotted in two places, both invisible in a diff: the command in its opening block did
 *   not run (bare `--dry-run` is refused — it needs a bump), and the paths it said the dist
 *   tarball holds back were wrong (`bin/*.php` is not a pattern `.gitattributes` writes).
 *
 * THE REGISTER, WHICH IS THE WHOLE DESIGN
 * ---------------------------------------
 *   A note cannot name its own `HEAD` — the commit that lands the note moves it — so holding every
 *   number against the checkout would make the file uncommittable rather than checked, and a guard
 *   that fails the first time anybody commits is a guard that gets deleted. The register is
 *   therefore the commit section 2 names as `HEAD`: what the note says about a *state* is asked of
 *   git about *that* commit, and stays true after the branch moves on.
 *
 *   What is left is what the note says about the tree *now*, and that is what these tests hold:
 *   the paths it names, the commits and tags it names, the one command name it carries, the files
 *   it describes, the version lane it claims is still open, the derivation it names for the size of
 *   the pending set — the one claim in it no number could be held to — and, through the gitignored
 *   `.agents/machine.local.json`, the environment table of section 5. That record belongs to the
 *   machine rather than to the package, so a checkout that has none (a fresh clone, CI's runner)
 *   skips those comparisons instead of running them against nothing.
 *
 *   A push does not fail them; a rename, a lost commit, a superseded tag or a regenerated
 *   inventory does.
 */
final class HandoffTest extends TestCase
{
    /**
     * What the package's own reader makes of a changelog: `unreleasedSection()` finds the section
     * and `changelogSignal()` weighs it, exactly as `bin/release.php` does. Run in a child process
     * because `bin/weighing.php` includes `bin/surface.php` with a bare `require`, and loading it
     * into the test runner is how two files that declare the same functions collide.
     */
    private const SIGNAL = <<<'PHP'
        require $argv[1];

        $section = unreleasedSection(file_get_contents($argv[2]));

        if ($section === null) {
            fwrite(STDERR, 'The changelog has no ## Unreleased section.');

            exit(1);
        }

        $signal = changelogSignal($section['body']);

        echo json_encode(
            ['severity' => $signal['severity'], 'evidence' => $signal['evidence']],
            JSON_THROW_ON_ERROR,
        );
        PHP;

    public function test_it_says_when_it_was_taken(): void
    {
        $taken = Handoff::taken();

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $taken);

        self::assertSame(
            $taken,
            gmdate('Y-m-d', (int) strtotime($taken)),
            sprintf('HANDOFF.md says it was taken on [%s], which is not a date.', $taken),
        );
    }

    public function test_every_path_it_names_is_one_this_repository_has(): void
    {
        $paths = Handoff::paths();

        self::assertGreaterThanOrEqual(
            20,
            count($paths),
            sprintf(
                'HANDOFF.md names %d paths in this package, which is fewer than a note that walks a reader '.
                'through the tree can explain: the reader that finds them may be reading less than it used to.',
                count($paths),
            ),
        );

        $missing = [];

        foreach ($paths as $claim) {
            if (file_exists(Handoff::root().'/'.$claim['path'])) {
                continue;
            }

            // A path git ignores is not one a checkout is meant to have, and the note says so
            // about the machine record itself: "gitignored, so a clone without the file skips
            // that comparison". An ignored path is therefore absent by design rather than
            // stale, and the exclude rules — not the filesystem — are what let this answer for
            // a fresh clone, which is the checkout this guard has to hold on CI.
            if (Handoff::git('check-ignore', '-q', '--', $claim['path'])['exit'] === 0) {
                continue;
            }

            $missing[] = sprintf('%s (line %d)', $claim['path'], $claim['source']);
        }

        self::assertSame(
            [],
            $missing,
            "HANDOFF.md names paths this package does not have:\n  - ".implode("\n  - ", $missing),
        );
    }

    public function test_the_files_it_calls_pending_are_the_ones_the_tree_it_describes_does_not_carry(): void
    {
        $head = Handoff::basis()['head']['sha'];

        foreach (Handoff::pending() as $file) {
            self::assertFileExists(
                Handoff::root().'/'.$file['path'],
                sprintf('HANDOFF.md line %d calls %s pending, and this package has no such file.', $file['source'], $file['path']),
            );
        }

        $committed = [];

        foreach (Handoff::pending() as $file) {
            if (Handoff::git('cat-file', '-e', $head.':'.$file['path'])['exit'] === 0) {
                $committed[] = $file['path'];
            }
        }

        self::assertSame(
            [],
            $committed,
            sprintf(
                "HANDOFF.md section 1 calls these files pending, and the commit it describes (%s) already carries them —\n".
                "so the state the note describes has been committed, and section 1 has to say what is pending now:\n  - %s",
                $head,
                implode("\n  - ", $committed),
            ),
        );
    }

    /**
     * The other direction, and the one that rots: every file this checkout has that the register
     * does not carry at all has to be named in section 1.
     *
     * WHY THIS EXISTS
     * ---------------
     *   The test above asks whether what section 1 names is pending — that a path is there, and
     *   that the commit it describes does not carry it. Nothing asked the converse, so a section
     *   that named one pending file and forgot the other three was green: the list was *sound* and
     *   not *complete*, which is the direction that harms a reader. A file nobody names is work the
     *   next thread cannot see, and it is not red anywhere else either — an untracked test file
     *   still runs, and an untracked source file is still analysed if a path glob reaches it.
     *
     *   The comparison is against the register, not against whatever is uncommitted now: the paths
     *   read are the ones a commit would *add* — untracked, plus anything staged as new — and those
     *   are absent from the register whatever the branch has done since.
     *
     *   On a checkout with nothing pending this reads nothing and passes, which is the state a
     *   clone and CI's runner are in. That is the shape of the claim rather than a hole in it:
     *   section 1 is a snapshot of one machine's working tree, so a clean checkout has nothing to
     *   disagree with — and nothing for this to excuse either, because the machine that wrote the
     *   note is the machine it fails on.
     */
    public function test_every_file_the_register_does_not_carry_is_named_in_section_1(): void
    {
        $uncarried = Handoff::uncarried();
        $listed = array_column(Handoff::pending(), 'path');
        $missing = array_values(array_diff($uncarried, $listed));

        self::assertSame([], $missing, sprintf(
            "This checkout holds %d file(s) that the commit section 2 names as `HEAD` does not carry, and section 1\n".
            "names %d of them. A file nothing names is work the next thread cannot see:\n  - %s\n\n".
            'Either name it in the table, or take it out of the tree — a file that is not work is one .gitignore should carry.',
            count($uncarried),
            count($listed),
            implode("\n  - ", $missing),
        ));
    }

    /**
     * The three blocks the note renders rather than writes: section 1's table, section 2's table and
     * section 2's listing, each compared with what git says at the register the note names.
     *
     * WHY THE NOTE DOES NOT WRITE THEM ITSELF
     * ---------------------------------------
     *   Each of those blocks is a set of files or a set of commits, and a number over it — how many
     *   paths the register does not carry, how many commits no remote has, how they break down, which
     *   commits the listing shows. Every one is a question git answers, and copying the answer in by
     *   hand is how a note states a number the tree does not have: the count of uncommitted paths is
     *   a fact about one working tree, and the register row moves the moment anything is committed.
     *
     *   So the blocks are derived — `bin/handoff.php` writes them from the same `rendered()` this
     *   reads — and this holds the file to what that derivation says. The reading is taken at the
     *   register the note itself names rather than at `HEAD`, which is the note's design: it
     *   describes a commit older than the one that lands it, so rendering at `HEAD` would report a
     *   fresh note stale the moment it was committed. A block out of step here is repaired by running
     *   the renderer, not by editing the number.
     */
    public function test_the_generated_blocks_are_what_the_renderer_writes(): void
    {
        foreach (Handoff::blocks() as $name) {
            self::assertSame(
                Handoff::rendered($name),
                Handoff::block($name),
                sprintf(
                    "HANDOFF.md's `%s` block is not what git says it is. The block is an output rather than a claim:\n".
                    'run `php bin/handoff.php` and it follows the tree, so neither a set of files nor a count in '.
                    'it can be stated by whoever re-takes the note.',
                    $name,
                ),
            );
        }
    }

    /**
     * The renderer itself, driven the way a re-take drives it.
     *
     * The comparison above holds the blocks to the derivation; this holds the command a session runs
     * to the same answer, so the two cannot disagree about whether the note is current — a renderer
     * that wrote one thing while the guard read another would make every re-take a coin toss.
     */
    public function test_the_renderer_finds_the_note_in_step(): void
    {
        $run = Handoff::run([PHP_BINARY, Handoff::root().'/bin/handoff.php', '--check']);

        self::assertSame(0, $run['exit'], sprintf(
            "bin/handoff.php --check reports the note out of step with the register it names:\n%s%s",
            $run['output'],
            $run['error'],
        ));

        self::assertStringContainsString('all in step with git', $run['output']);
    }

    /**
     * Where the size of the pending set comes from, now that the note does not state it.
     *
     * WHY THIS SHAPE AND NOT A COMPARISON OF NUMBERS
     * ---------------------------------------------
     *   This test used to read three counts out of section 1 and compare them with `git status`, and
     *   it was red on every checkout but the one that wrote the note: a count of uncommitted paths is
     *   a fact about one working tree, so any file added, deleted or reverted afterwards left the
     *   sentence disagreeing with the tree, and the repair was a person re-reading git and editing
     *   three words. A number no derivation can hold is not a claim this package binds, so the
     *   sentence names the derivation instead — the command the note's own opening block tells a
     *   reader to run — and this holds it to the two things a committed file *can* be asked for: that
     *   it names that command, and that it does not state a count again.
     *
     *   The count check is a vocabulary check rather than a pattern, because the way the number would
     *   come back is as a word — `Twelve paths differ`, `nine tracked paths changed`, `three new
     *   files` — and `NumberWords` is the package's list of the words a record writes numbers with. It
     *   reads the words in front of every noun the guard counts, because that is where the note put its
     *   three counts, and it reads three of them rather than one because the note's own phrasing puts
     *   an adjective between the number and the noun: a check that saw only `tracked` would report
     *   agreement with a count it had never read. Which nouns those are is `Handoff::countsNoun()`'s —
     *   the pair table the count readings are built from — so the noun this catches is the noun a
     *   reading counts, in either of its forms, rather than a list kept here beside it. Case is the
     *   record's (`NumberWords` folds it), and a number word nowhere near those nouns — `one working
     *   tree` — is prose rather than a count.
     */
    public function test_section_1_names_the_derivation_for_the_pending_set_rather_than_a_count(): void
    {
        $sentence = Handoff::pendingSize();

        self::assertStringContainsString(
            '`git status --short`',
            $sentence['text'],
            sprintf(
                "HANDOFF.md line %d no longer names the command the size of the pending set is read from.\n".
                'A count of uncommitted paths cannot be held in a committed file, so the sentence has to name the '.
                'derivation a reader runs instead.',
                $sentence['source'],
            ),
        );

        $words = preg_split('/[^A-Za-z-]+/', $sentence['text'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $counted = [];

        foreach ($words as $index => $word) {
            if (! Handoff::countsNoun($word)) {
                continue;
            }

            foreach (array_slice($words, max(0, $index - 3), min(3, $index)) as $before) {
                if (NumberWords::knows($before)) {
                    $counted[] = $before.' '.$word;
                }
            }
        }

        self::assertSame(
            [],
            $counted,
            sprintf(
                "HANDOFF.md line %d states the pending set as a count again (%s).\n".
                'A count of uncommitted paths is a fact about one working tree — red on every checkout but the one '.
                'that wrote the note — so the sentence names the derivation (`git status --short`) and leaves the '.
                'number to the command.',
                $sentence['source'],
                implode(', ', $counted),
            ),
        );
    }

    /**
     * Every count reading in this guard, held to the singular and the plural it has to accept.
     *
     * WHY THIS IS ITS OWN TEST
     * ------------------------
     *   A count reading is a pattern around a noun, and the noun has two forms: the singular a note
     *   writes when it holds exactly one of the thing, and the plural it writes for every other count.
     *   A pattern spelling only the plural is green against every note this package has today and blind
     *   on the note that holds one — the one a reader would most want read — so the failure is invisible
     *   in a diff of the record and lands on whoever writes the first such note. That is not a claim a
     *   note makes but the shape of the reading that reads it, which is why it is held here: the forms
     *   are declared beside the readings (`Handoff::countedNouns()`), the readings build their patterns
     *   from that declaration, and each pair is held to both of its forms.
     *
     *   The other half is the reading that does not exist yet. A pattern that spells its own noun passes
     *   every assertion above it, because the pair held here is not the pair it reads, so the guard's own
     *   source is read for the two shapes a hand-written noun takes: a form written into a pattern —
     *   `'/^paths$/'`, which weighs a count of many and is blind to a count of one — and a pair written out
     *   by hand, `path|paths` or the `entr(?:y|ies)` the Unreleased totals used to carry, which is a second
     *   spelling of a pair the table already decides. That is what makes the next reading unable to ship
     *   without the singular rather than merely unlikely to.
     *
     *   A reading that counts without a noun is held through the vocabulary it takes its number from:
     *   `NumberWords` is what spells the word a word reading matches, so the word for one has to be in
     *   it, while a reading that matches digits (`(\d+)`) reads a count of one by construction.
     */
    public function test_every_count_reading_accepts_the_singular_and_the_plural_it_counts(): void
    {
        $counted = Handoff::countedNouns();

        self::assertNotSame(
            [],
            $counted,
            'The guard declares no counted noun at all, so every count its readings take is one nothing holds.',
        );

        foreach ($counted as $noun => $pair) {
            self::assertNotSame(
                $pair['singular'],
                $pair['plural'],
                sprintf(
                    'The guard counts [%s] in one form, so a reading of it cannot tell a count of one from a count of many.',
                    $noun,
                ),
            );

            $pattern = '/^'.Handoff::counted($noun).'$/';

            foreach ([$pair['singular'], $pair['plural']] as $form) {
                self::assertSame(
                    1,
                    preg_match($pattern, $form),
                    sprintf(
                        'The pattern the readings build for [%s] does not read [%s], which is the %s form of a count of it: a '.
                        'reading built from this pair cannot be read through by the note that holds that count.',
                        $noun,
                        $form,
                        $form === $pair['singular'] ? 'singular' : 'plural',
                    ),
                );
            }
        }

        self::assertTrue(
            NumberWords::knows('one'),
            'The readings that take their number from words cannot read a count of one: `NumberWords` no longer spells [one], '.
            'and a word it does not know is a count no word reading can take.',
        );

        $spelled = self::nounsSpelledByHand(self::guardSources());

        self::assertSame(
            [],
            $spelled,
            sprintf(
                "A count reading in the guard spells its own noun rather than building it from the pair:\n  - %s\n\n".
                'A pattern built from `Handoff::counted()` reads both forms because the pair it is built from has both; one '.
                "written out beside the table reads the form it names and no other, which is a reading able to weigh a count\n".
                'of many and blind to a count of one — the note a reader would most want read. Declare the noun in '.
                '`Handoff::COUNTED`, the singular and the plural, and build the pattern from the pair.',
                implode("\n  - ", $spelled),
            ),
        );
    }

    /**
     * The scan, held to the two directions it can be useless in.
     *
     * WHY THIS IS A TEST AND NOT A PARAGRAPH
     * -------------------------------------
     *   The hold above is only as good as the scan behind it, and a scan fails in two directions: it can
     *   report nothing, which leaves a reading that spells only the plural green — the failure the whole
     *   hold exists for — or it can report everything, which fails an innocent reading until somebody
     *   deletes the hold rather than fixing it. Both directions are measured here, on lines the test
     *   composes rather than finds, because the teeth were measured by hand into section 5 of the note
     *   and a control nobody re-measures is one that rots with the reading that wrote it.
     *
     *   Each line is built from the declaration's own forms through variables, because the guard's own
     *   source is what the live scan reads: a form written into *this* file is a form this file would be
     *   refused for. That is not a way around the scan — a noun inside a variable is exactly what it
     *   leaves alone, which is one of the three lines below that has to come back empty.
     */
    public function test_the_scan_refuses_every_shape_a_reading_can_ship_with(): void
    {
        $counted = Handoff::countedNouns();
        $entry = $counted['entry'];
        $path = $counted['path'];
        $box = 'box';

        $cases = [
            // A note cannot be read through these: a form on its own, and both shapes of a pair.
            'a plural spelled into a pattern of its own' => [
                sprintf("if (preg_match('/^%s$/i', %s) !== 1) {", $path['plural'], '$word'),
                "Handoff::counted('path')",
            ],
            'a pair written out by hand beside the table' => [
                sprintf("if (preg_match('/^%s$/', %s) === 1) {", $entry['singular'].'|'.$entry['plural'], '$span'),
                $entry['singular'].'|'.$entry['plural'],
            ],
            'a pair for a noun the table does not declare' => [
                sprintf("preg_match('/^(%s)$/', %s)", $box.'|'.$box.'es', '$name'),
                $box.'|'.$box.'es',
            ],
            // ... and these are readings: the door, a key, and a variable.
            'a pattern the pair was built around' => [
                sprintf("if (preg_match('/^(\d+) %s$/', %s) !== 1) {", "self::counted('entry')", '$row'),
                null,
            ],
            'a form that runs into the key it names' => [
                sprintf("if (preg_match('/^\s*%s:\s*$/', %s) === 1) {", $path['plural'], '$line'),
                null,
            ],
            'a form inside a variable' => [
                sprintf("if (preg_match('/^src/', %s) === 1) {", '$file'),
                null,
            ],
        ];

        foreach ($cases as $shape => [$line, $names]) {
            $findings = self::findingsIn($line);

            if ($names === null) {
                self::assertSame([], $findings, sprintf(
                    "The scan refuses a line it has no business refusing — %s:\n  %s\n\n".
                    'A scan that reports everything fails an innocent reading, and a hold that does that is deleted rather '.
                    'than fixed.',
                    $shape,
                    $line,
                ));

                continue;
            }

            self::assertNotSame([], $findings, sprintf(
                "The scan read nothing in a line no note could be read through — %s:\n  %s\n\n".
                'This is the direction the whole hold exists for: a reading that spells the form it names and no other.',
                $shape,
                $line,
            ));

            self::assertStringContainsString($names, implode(' ', $findings), sprintf(
                "The scan refused %s without saying what to do about it:\n  %s",
                $shape,
                implode("\n  ", $findings),
            ));
        }
    }

    /**
     * What the scan makes of one line of source, read as if it were the reader's — the controls above are
     * lines rather than files, and a scan is only as good as what it says about the shape it is handed.
     *
     * @return list<string>
     */
    private static function findingsIn(string $line): array
    {
        return self::nounsSpelledByHand(['tests/Support/Handoff.php' => $line."\n"]);
    }

    /**
     * Every place the guard's own source spells a noun a count reading matches, as `file:line  finding`.
     *
     * THE TWO SHAPES THAT ARE FINDINGS
     * -------------------------------
     *   A declared form written into a pattern — `'/^paths$/'` — is a reading that weighs a count of
     *   many and is blind to a count of one, and the doors the readings are meant to go through
     *   (`counted('path')`) are cut out of the line before it is searched for, so a pattern *built* from
     *   the pair is not read as one that spells it. A pair written out by hand — `path|paths`, or the
     *   `entr(?:y|ies)` the Unreleased totals used to carry — reads both forms and is refused all the
     *   same: it is a second spelling of a pair the table already decides, and when the noun is one
     *   nobody declared it is a reading of a count the guard holds nowhere at all.
     *
     *   The pair shapes are the three ways English writes a plural for a noun a pattern could count —
     *   `s`, as in `path|paths`; `es`, as in `batch|batches`; and the `y` a plural turns into `ies` — so
     *   an alternation that is not a noun pair (`true|false`) is left to the reading it belongs to. Prose
     *   is not read: a docblock saying which forms a noun has is the decision written down, while only a
     *   pattern is what a note is read through, so comment lines are stepped over and a line's own
     *   trailing comment is cut before anything is looked for.
     *
     * The source is handed in rather than read here, so that the same scan can be asked about a line the
     * controls above compose: what it reads in the guard's files and what it reads in a shape somebody
     * handed it are the same reading, which is the only way the controls hold the live scan.
     *
     * @param array<string, string> $sources
     *
     * @return list<string>
     */
    private static function nounsSpelledByHand(array $sources): array
    {
        $found = [];

        foreach ($sources as $file => $source) {
            foreach (explode("\n", $source) as $index => $line) {
                $trimmed = ltrim($line);

                if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '//')) {
                    continue;
                }

                $comment = strpos($line, '//');
                $code = $comment === false ? $line : substr($line, 0, $comment);

                foreach (array_merge(self::nounsInPatterns($code), self::nounPairs($code)) as $finding) {
                    $found[] = sprintf('%s:%d  %s', $file, $index + 1, $finding);
                }
            }
        }

        return $found;
    }

    /**
     * The guard's own source, keyed by the path a finding names it with: the reader that takes the
     * note's claims, and every file of the note's own suite named after it.
     *
     * The suite is read as a directory rather than as a list written down here, so the next test of the
     * note is scanned without anybody adding it to this list, and the files are read from disk rather
     * than held as text, so what is scanned is what a commit would carry. A file that is not there is a
     * failure rather than an empty scan: one that read nothing and found nothing would report agreement
     * with a reading it never read.
     *
     * @return array<string, string>
     */
    private static function guardSources(): array
    {
        $suite = array_map(
            static fn (string $path): string => 'tests/Unit/Docs/'.basename($path),
            glob(Handoff::root().'/tests/Unit/Docs/Handoff*.php') ?: [],
        );

        self::assertNotSame(
            [],
            $suite,
            'No file of the note\'s own suite is named after the reader, so the half of this guard that reads the guard\'s '.
            'source is scanning nothing.',
        );

        $sources = [];

        foreach (array_merge(['tests/Support/Handoff.php'], $suite) as $path) {
            $source = @file_get_contents(Handoff::root().'/'.$path);

            self::assertIsString(
                $source,
                sprintf('%s is not there to be read for a count reading spelled by hand — move this list with the file.', $path),
            );

            $sources[$path] = $source;
        }

        return $sources;
    }

    /**
     * The noun-and-plural alternations in one line of code, as the line writes them — both arms, in
     * the order they appear, for each pair whose second arm is the plural of its first.
     *
     * @return list<string>
     */
    private static function nounPairs(string $code): array
    {
        if (preg_match_all('/([a-z]+)\|([a-z]+)/', $code, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $pairs = [];

        foreach ($matches as $match) {
            if (self::isPluralOf($match[1], $match[2])) {
                $pairs[] = $match[1].'|'.$match[2];
            }
        }

        return $pairs;
    }

    /**
     * The declared forms a line spells into a pattern of its own, each as the instruction it should have
     * followed instead.
     *
     * WHAT MAKES A FORM A FINDING HERE
     * -------------------------------
     *   Only a line that matches text is read at all, and only a regex — a reading of the note is a
     *   `preg_match` or a `preg_match_all`, while a `str_contains` asks whether a word is there rather
     *   than weighing a count of it. Two kinds of word come out of the line before it is searched: the
     *   doors (`counted('path')`, `countsNoun($word)`), because a form named *to* the door is the one way
     *   of writing it that reads both forms; and a variable, because a noun inside a name (`$paths`,
     *   `$file`) is not a pattern. What is left is a form standing alone in what the line matches with.
     *
     *   A form running straight into a colon or a dot is left alone as well: that is a noun naming a key
     *   or a path rather than counting the thing — `phpstan.neon.dist`'s `paths:` row is the one pattern
     *   in the guard that spells one so, and it holds no count. Anywhere else, a declared form in a
     *   pattern is a reading that reads the form it names and no other.
     *
     * @return list<string>
     */
    private static function nounsInPatterns(string $code): array
    {
        if (preg_match('/\b(?:preg_match|preg_match_all)\s*\(/', $code) !== 1) {
            return [];
        }

        $bare = preg_replace([
            '/\b(?:counted|countsNoun)\([^)]*\)/',
            '/\$[A-Za-z_][A-Za-z0-9_]*/',
        ], '', $code) ?? $code;

        $found = [];

        foreach (self::countedForms() as $form => $noun) {
            if (preg_match('/\b'.preg_quote($form, '/').'\b(?![:.])/', $bare) === 1) {
                $found[] = sprintf(
                    "the form [%s], spelled into the pattern rather than built from the pair (Handoff::counted('%s'))",
                    $form,
                    $noun,
                );
            }
        }

        return $found;
    }

    /**
     * Every form the guard declares a noun in, keyed by the form, so that a form found in a pattern is
     * reported with the noun it counts and with the door it should have gone through.
     *
     * @return array<string, string>
     */
    private static function countedForms(): array
    {
        $forms = [];

        foreach (Handoff::countedNouns() as $noun => $pair) {
            $forms[$pair['singular']] = $noun;
            $forms[$pair['plural']] = $noun;
        }

        return $forms;
    }

    /**
     * Whether the second of two arms is the plural of the first — the three shapes a hand-written pair
     * takes, so that an alternation which is not one (`true|false`, or a `[^}]*|[^/]+` inside a pattern
     * spanning more than a word) is left to the reading it belongs to.
     */
    private static function isPluralOf(string $singular, string $plural): bool
    {
        return $plural === $singular.'s'
            || $plural === $singular.'es'
            || (str_ends_with($singular, 'y') && $plural === substr($singular, 0, -1).'ies');
    }

    public function test_the_commits_it_describes_are_the_ones_it_names(): void
    {
        foreach (['HEAD' => Handoff::basis()['head'], 'origin/dev' => Handoff::basis()['origin']] as $label => $commit) {
            $run = Handoff::git('show', '-s', '--format=%s', $commit['sha'].'^{commit}');

            self::assertSame(
                0,
                $run['exit'],
                sprintf(
                    'HANDOFF.md names %s as %s, and this repository has no such commit — section 2 has to be '.
                    're-taken, because every number in it is read against that commit.',
                    $commit['sha'],
                    $label,
                ),
            );

            self::assertSame(
                $commit['subject'],
                trim($run['output']),
                sprintf(
                    'HANDOFF.md line %d gives %s the subject [%s], and the commit reads [%s].',
                    $commit['source'],
                    $commit['sha'],
                    $commit['subject'],
                    trim($run['output']),
                ),
            );
        }
    }

    public function test_the_unpushed_count_and_mix_are_the_commits_between_the_two_it_names(): void
    {
        $basis = Handoff::basis();
        $range = $basis['origin']['sha'].'..'.$basis['head']['sha'];

        $count = Handoff::git('rev-list', '--count', $range);
        $actual = (int) trim($count['output']);

        self::assertSame(0, $count['exit'], sprintf('The commits between the two the note names could not be counted: %s', $count['error']));

        self::assertSame(
            Handoff::unpushed(),
            $actual,
            sprintf(
                'HANDOFF.md section 2 says %d commits are unpushed; the commits between %s and %s are %d. A commit '.
                'or a push is what moved it, and the number is a claim about the pair the note names.',
                Handoff::unpushed(),
                $basis['origin']['sha'],
                $basis['head']['sha'],
                $actual,
            ),
        );

        $tally = [];

        foreach (Handoff::outputLines(Handoff::git('log', '--format=%s', $range)['output']) as $subject) {
            if (preg_match('/^([A-Za-z]+)(?:\([^)]*\))?!?:/', $subject, $matches) === 1) {
                $type = strtolower($matches[1]);
                $tally[$type] = ($tally[$type] ?? 0) + 1;
            }
        }

        $mix = Handoff::mix();
        ksort($tally);
        ksort($mix);

        self::assertSame(
            $mix,
            $tally,
            sprintf(
                "HANDOFF.md breaks the unpushed commits down as %s; the commits between the two it names are %s.",
                self::written($mix),
                self::written($tally),
            ),
        );
    }

    public function test_the_commits_it_lists_are_the_most_recent_ones_between_them(): void
    {
        $basis = Handoff::basis();
        $range = $basis['origin']['sha'].'..'.$basis['head']['sha'];
        $listing = Handoff::listing();

        self::assertCount(
            $listing['claimed'],
            $listing['commits'],
            sprintf(
                'HANDOFF.md says it lists the %d most recent unpushed commits and lists %d, so the sentence and '.
                'the listing disagree even before the tree is asked.',
                $listing['claimed'],
                count($listing['commits']),
            ),
        );

        $log = Handoff::outputLines(Handoff::git('log', '--format=%h%x1f%s', $range)['output']);

        self::assertGreaterThanOrEqual(
            count($listing['commits']),
            count($log),
            'HANDOFF.md lists more unpushed commits than the two it names have between them.',
        );

        foreach ($listing['commits'] as $position => $listed) {
            [$sha, $subject] = array_pad(explode("\x1f", $log[$position], 2), 2, '');

            self::assertSame(
                ['sha' => $listed['sha'], 'subject' => $listed['subject']],
                ['sha' => $sha, 'subject' => $subject],
                sprintf(
                    'HANDOFF.md line %d lists %s — %s at position %d of the unpushed commits, and the commit '.
                    'there is %s — %s.',
                    $listed['source'],
                    $listed['sha'],
                    $listed['subject'],
                    $position + 1,
                    $sha,
                    $subject,
                ),
            );
        }
    }

    public function test_the_tag_it_calls_latest_is_still_the_newest(): void
    {
        $known = Handoff::outputLines(Handoff::git('tag', '--list', 'v*')['output']);

        $unknown = array_values(array_diff(Handoff::tags(), $known));

        self::assertSame(
            [],
            $unknown,
            sprintf(
                'HANDOFF.md names these tags, and this repository has none of them: %s.',
                implode(', ', $unknown),
            ),
        );

        $newest = self::newest($known);

        self::assertSame(
            Handoff::latest(),
            $newest,
            sprintf(
                'HANDOFF.md says %s is the latest tag, and %s is — so the lane the note describes has been '.
                'released past, and section 3 has to be re-taken before the next tag is cut.',
                Handoff::latest(),
                $newest,
            ),
        );

        self::assertNotContains(
            Handoff::absent(),
            $known,
            sprintf(
                'HANDOFF.md says there is no %s, and this repository has that tag — which is the sentence '.
                'that lets the next version be a prerelease, so the note is now describing the wrong lane.',
                Handoff::absent(),
            ),
        );
    }

    public function test_each_tag_it_names_is_on_the_branch_it_describes(): void
    {
        $head = Handoff::basis()['head']['sha'];
        $off = [];

        foreach (Handoff::tags() as $tag) {
            if (Handoff::git('merge-base', '--is-ancestor', $tag, $head)['exit'] !== 0) {
                $off[] = $tag;
            }
        }

        self::assertSame(
            [],
            $off,
            sprintf(
                'HANDOFF.md calls these tags the ones on the branch %s, and they are not behind that commit: %s.',
                $head,
                implode(', ', $off),
            ),
        );
    }

    public function test_the_tarball_it_describes_is_the_one_gitattributes_writes(): void
    {
        $shipped = Handoff::ships();
        $held = Handoff::withheld();
        $scripts = array_values(array_map(
            static fn (string $path): string => 'bin/'.basename($path),
            glob(Handoff::root().'/bin/*.php') ?: [],
        ));

        $attributes = self::attributes(array_values(array_unique(array_merge(
            array_column($shipped, 'path'),
            array_column($held, 'path'),
            $scripts,
        ))));

        $wrong = [];

        foreach ($shipped as $claim) {
            if (($attributes[$claim['path']] ?? '') !== 'unspecified') {
                $wrong[] = sprintf(
                    'line %d calls %s shippable, and .gitattributes reads [%s]',
                    $claim['source'],
                    $claim['path'],
                    $attributes[$claim['path']] ?? 'nothing',
                );
            }
        }

        foreach ($held as $claim) {
            if (($attributes[$claim['path']] ?? '') !== 'set') {
                $wrong[] = sprintf(
                    'line %d says %s is held back, and .gitattributes reads [%s]',
                    $claim['source'],
                    $claim['path'],
                    $attributes[$claim['path']] ?? 'nothing',
                );
            }
        }

        self::assertSame([], $wrong, "HANDOFF.md describes a dist tarball this package does not build:\n  - ".implode("\n  - ", $wrong));

        $named = array_values(array_intersect(array_column($shipped, 'path'), $scripts));
        $actual = array_values(array_filter(
            $scripts,
            static fn (string $script): bool => ($attributes[$script] ?? '') === 'unspecified',
        ));

        sort($named);
        sort($actual);

        self::assertSame(
            $named,
            $actual,
            sprintf(
                "HANDOFF.md says these scripts in bin/ reach a consumer: %s. The scripts `.gitattributes` does not ".
                'hold back are: %s. Nothing else is checked about them, so the two lists have to be the same list.',
                implode(', ', $named) ?: '(none)',
                implode(', ', $actual) ?: '(none)',
            ),
        );
    }

    public function test_the_analysis_runs_at_the_level_over_the_paths_it_names(): void
    {
        $claim = Handoff::phpstan();
        $configured = self::phpstanNeon();

        self::assertSame(
            $claim['level'],
            $configured['level'],
            sprintf(
                'HANDOFF.md line %d says the analysis runs at level [%s], and phpstan.neon.dist says [%s].',
                $claim['source'],
                $claim['level'],
                $configured['level'],
            ),
        );

        $claimed = $claim['paths'];
        $paths = $configured['paths'];
        sort($claimed);
        sort($paths);

        self::assertSame(
            $claimed,
            $paths,
            sprintf(
                'HANDOFF.md line %d says the analysis covers %s, and phpstan.neon.dist covers %s.',
                $claim['source'],
                implode(', ', $claimed) ?: '(nothing)',
                implode(', ', $paths) ?: '(nothing)',
            ),
        );
    }

    public function test_the_skills_it_names_are_the_ones_that_are_indexed(): void
    {
        $known = array_keys(SkillDescriptions::skills());
        $named = Handoff::skills();
        sort($known);
        sort($named);

        self::assertSame(
            $known,
            $named,
            sprintf(
                "HANDOFF.md names these skills: %s. This package has: %s — a skill added, renamed or removed is a ".
                're-take, because a fresh thread reads that row to find out what is here.',
                implode(', ', $named),
                implode(', ', $known),
            ),
        );

        $listed = array_map(
            static fn (array $row): string => $row['skill'],
            SkillDescriptions::indexed(),
        );

        self::assertSame(
            [],
            array_values(array_diff($named, $listed)),
            sprintf(
                'HANDOFF.md calls these the skills this package has, and .agents/README.md does not list them: %s.',
                implode(', ', array_values(array_diff($named, $listed))),
            ),
        );
    }

    public function test_the_command_it_quotes_is_declared_where_it_says(): void
    {
        $command = Handoff::command();
        $path = Handoff::root().'/'.$command['file'];

        self::assertFileExists(
            $path,
            sprintf('HANDOFF.md line %d says the flip is a command declared in %s, which is not a file here.', $command['source'], $command['file']),
        );

        $raw = (string) file_get_contents($path);

        self::assertSame(
            1,
            preg_match('/protected \$signature\s*=\s*\'([^\']+)\'/', $raw, $matches),
            sprintf('%s declares no `protected $signature`, so the command the note quotes has no name to hold it to.', $command['file']),
        );

        self::assertSame(
            $command['name'],
            (string) strtok(trim($matches[1]), " \t\n"),
            sprintf(
                'HANDOFF.md line %d quotes `%s`, and %s declares `%s`.',
                $command['source'],
                $command['name'],
                $command['file'],
                (string) strtok(trim($matches[1]), " \t\n"),
            ),
        );
    }

    public function test_the_checks_it_says_the_list_names_are_the_checks_it_lists(): void
    {
        $run = Handoff::run([PHP_BINARY, Handoff::root().'/bin/checks.php', '--list']);

        self::assertSame(
            0,
            $run['exit'],
            sprintf('php bin/checks.php --list exited %d: %s%s', $run['exit'], $run['output'], $run['error']),
        );

        $names = Handoff::outputLines($run['output']);

        self::assertSame(
            Handoff::claimedChecks(),
            count($names),
            sprintf(
                'HANDOFF.md says the list names %d checks, and it names %d: %s.',
                Handoff::claimedChecks(),
                count($names),
                implode(', ', array_map(static fn (string $line): string => (string) strtok($line, ' '), $names)),
            ),
        );
    }

    public function test_the_unreleased_section_is_the_one_it_describes(): void
    {
        $claim = Handoff::unreleased();
        $head = Handoff::basis()['head']['sha'];

        $show = Handoff::git('show', $head.':CHANGELOG.md');

        self::assertSame(0, $show['exit'], sprintf('The changelog the note describes (%s:CHANGELOG.md) could not be read: %s', $head, $show['error']));

        $file = tempnam(sys_get_temp_dir(), 'swrr-handoff-');

        self::assertIsString($file, 'A temporary file could not be made for the changelog the weighing is asked about.');

        file_put_contents($file, $show['output']);

        try {
            $run = Handoff::run([PHP_BINARY, '-r', self::SIGNAL, Handoff::root().'/bin/weighing.php', $file]);
        } finally {
            @unlink($file);
        }

        self::assertSame(
            0,
            $run['exit'],
            sprintf('The weighing could not be asked about the changelog the note describes: %s%s', $run['output'], $run['error']),
        );

        $signal = json_decode($run['output'], true);

        self::assertIsArray($signal, sprintf('The weighing answered nothing readable: %s', $run['output']));

        self::assertSame(
            $claim['severity'],
            $signal['severity'],
            sprintf(
                'HANDOFF.md says the Unreleased notes weigh as [%s], and the package\'s own changelogSignal() reads them as [%s].',
                $claim['severity'],
                $signal['severity'],
            ),
        );

        $counts = [];

        foreach ($signal['evidence'] as $line) {
            if (preg_match('/^### (\w+) — (\d+) entr/', $line, $matches) === 1) {
                $counts[strtolower($matches[1])] = (int) $matches[2];
            }
        }

        // A heading the section does not carry counts zero rather than being absent from the
        // comparison: `bin/release.php` promotes the notes and writes a bare `## Unreleased`,
        // leaving the headings to the first change, so the section a release leaves behind has
        // none of them and a note taken in that state has to be able to say so. The teeth are
        // untouched — a heading that *is* there is reported with its count, so a note that calls
        // it empty fails, and a heading the note does not name (say `### Security`) is a key it
        // does not have.
        foreach (['added', 'fixed', 'changed'] as $heading) {
            $counts[$heading] ??= 0;
        }

        $claimed = ['added' => $claim['added'], 'fixed' => $claim['fixed'], 'changed' => $claim['changed']];
        ksort($counts);
        ksort($claimed);

        self::assertSame(
            $claimed,
            $counts,
            sprintf(
                'HANDOFF.md says the Unreleased section carries %s; the weighing counts %s. A heading the note '.
                'does not mention is the drift this half is for, because the note says those counts are the '.
                'weighing\'s and not counted by hand.',
                self::written($claimed),
                self::written($counts),
            ),
        );

        self::assertSame(
            array_sum($counts),
            $claim['total'],
            sprintf(
                'HANDOFF.md says the Unreleased section carries %d entries, and the headings it lists add up to %d.',
                $claim['total'],
                array_sum($counts),
            ),
        );
    }

    public function test_the_records_are_out_of_step_with_the_tree_it_describes(): void
    {
        $head = Handoff::basis()['head']['sha'];
        $run = Handoff::run([PHP_BINARY, Handoff::root().'/bin/inventory.php', '--check', '--at='.$head]);

        self::assertSame(
            1,
            $run['exit'],
            sprintf(
                "HANDOFF.md section 1 says the three records are out of step with the commit it describes (%s), and\n".
                "php bin/inventory.php --check --at=%s exited %d — so either the records describe a tree the note\n".
                "does not, or the state the note was written in is gone and it has to be re-taken.\n%s%s",
                $head,
                $head,
                $run['exit'],
                $run['output'],
                $run['error'],
            ),
        );

        foreach (['files.tsv', 'methods.tsv', 'surface.tsv'] as $record) {
            self::assertStringContainsString(
                $record,
                $run['error'],
                sprintf('The records are out of step at %s without naming %s, which is the record the note lists.', $head, $record),
            );
        }
    }

    public function test_the_toolchain_rows_are_the_facts_the_machine_record_holds(): void
    {
        $machine = self::machineRecord();
        $php = Handoff::php();

        // The row names the record's key rather than a path, and `Handoff::php()` resolves the
        // path through that record — so what is compared here is the row's pointer against the
        // record, whose own `php.exe` is on both sides by construction. What is still the row's own
        // claim is below: the version, and whether `php` answers on PATH.
        self::assertSame($machine['php']['exe'], $php['exe'], sprintf(
            'HANDOFF.md line %d resolves PHP to [%s], and the record the row names says [%s].',
            $php['source'],
            $php['exe'] ?? 'nothing',
            $machine['php']['exe'],
        ));

        self::assertSame($machine['php']['version'], $php['version'], sprintf(
            'HANDOFF.md line %d puts the PHP version at [%s], and the machine record says [%s].',
            $php['source'],
            $php['version'],
            $machine['php']['version'],
        ));

        self::assertSame($machine['php']['onPath'], $php['onPath'], sprintf(
            'HANDOFF.md line %d says PHP is%s on PATH, and the machine record says it is%s.',
            $php['source'],
            $php['onPath'] ? '' : ' not',
            $machine['php']['onPath'] ? '' : ' not',
        ));

        $composer = Handoff::composer();

        self::assertSame($machine['composer']['version'], $composer['version'], sprintf(
            'HANDOFF.md line %d leads the Composer row with [%s], and the machine record says [%s].',
            $composer['source'],
            $composer['version'],
            $machine['composer']['version'],
        ));

        self::assertSame(basename($machine['composer']['phar']), $composer['phar'], sprintf(
            'HANDOFF.md line %d names [%s], and the machine record points at [%s].',
            $composer['source'],
            $composer['phar'],
            $machine['composer']['phar'],
        ));

        self::assertSame($machine['composer']['gitignored'], $composer['gitignored'], sprintf(
            'HANDOFF.md line %d says composer.phar is%s gitignored, and the machine record says it is%s.',
            $composer['source'],
            $composer['gitignored'] ? '' : ' not',
            $machine['composer']['gitignored'] ? '' : ' not',
        ));

        $search = Handoff::search();

        self::assertSame($machine['search']['rgOnPath'], $search['available'], sprintf(
            'HANDOFF.md line %d says rg is%s on PATH, and the machine record says it is%s.',
            $search['source'],
            $search['available'] ? '' : ' not',
            $machine['search']['rgOnPath'] ? '' : ' not',
        ));

        self::assertSame($machine['search']['rgVersion'], $search['version'], sprintf(
            'HANDOFF.md line %d puts ripgrep at [%s], and the machine record says [%s].',
            $search['source'],
            $search['version'],
            $machine['search']['rgVersion'],
        ));

        $style = Handoff::style();

        self::assertSame($machine['style']['command'], $style['command'], sprintf(
            'HANDOFF.md line %d names the style command [%s], and the machine record says [%s].',
            $style['source'],
            $style['command'],
            $machine['style']['command'],
        ));

        self::assertSame($machine['style']['preset'], $style['preset'], sprintf(
            'HANDOFF.md line %d puts the style gate at [%s], and the machine record says [%s].',
            $style['source'],
            $style['preset'],
            $machine['style']['preset'],
        ));
    }

    public function test_the_git_row_is_the_facts_the_machine_record_holds(): void
    {
        $machine = self::machineRecord();
        $identity = Handoff::gitIdentity();

        self::assertSame($machine['git']['origin'], $identity['origin'], sprintf(
            'HANDOFF.md line %d quotes origin as [%s], and the machine record says [%s].',
            $identity['source'],
            $identity['origin'],
            $machine['git']['origin'],
        ));

        self::assertSame($machine['git']['commitGpgSign'], $identity['commitGpgSign'], sprintf(
            'HANDOFF.md line %d says commit.gpgsign is [%s], and the machine record says [%s].',
            $identity['source'],
            var_export($identity['commitGpgSign'], true),
            var_export($machine['git']['commitGpgSign'], true),
        ));

        self::assertSame($machine['git']['userSigningKey'], $identity['userSigningKey'], sprintf(
            'HANDOFF.md line %d signs with [%s], and the machine record says [%s].',
            $identity['source'],
            $identity['userSigningKey'],
            $machine['git']['userSigningKey'],
        ));

        self::assertSame($machine['git']['hooksPath'], $identity['hooksPath'], sprintf(
            'HANDOFF.md line %d says the hooks path is [%s], and the machine record says [%s].',
            $identity['source'],
            $identity['hooksPath'] ?? 'unset',
            $machine['git']['hooksPath'] ?? 'unset',
        ));
    }

    public function test_the_packaging_rows_are_the_facts_the_machine_record_holds(): void
    {
        $machine = self::machineRecord();
        $ships = array_column(Handoff::ships(), 'path');
        $holds = array_column(Handoff::withheld(), 'path');
        $carries = $machine['tarball']['carries'];
        $leftOut = $machine['tarball']['leavesOut'];

        sort($ships);
        sort($holds);
        sort($carries);
        sort($leftOut);

        self::assertSame($carries, $ships, sprintf(
            "The machine record says a consumer's tarball carries:\n  - %s\nHANDOFF.md says:\n  - %s",
            implode("\n  - ", $carries),
            implode("\n  - ", $ships),
        ));

        self::assertSame($leftOut, $holds, sprintf(
            "The machine record says the tarball holds back:\n  - %s\nHANDOFF.md says:\n  - %s",
            implode("\n  - ", $leftOut),
            implode("\n  - ", $holds),
        ));

        $skills = Handoff::skills();
        $recorded = array_map(
            static fn (string $dir): string => basename($dir),
            $machine['skills']['dirs'],
        );

        sort($skills);
        sort($recorded);

        self::assertSame($recorded, $skills, sprintf(
            'The machine record lists the skills %s, and HANDOFF.md names %s.',
            implode(', ', $recorded),
            implode(', ', $skills),
        ));

        self::assertSame($machine['skills']['index'], Handoff::skillsIndex(), sprintf(
            'The machine record says the skills are indexed by [%s], and HANDOFF.md says [%s].',
            $machine['skills']['index'],
            Handoff::skillsIndex(),
        ));

        self::assertSame($machine['checks']['list'], Handoff::checksCommand(), sprintf(
            'The machine record says the checks are listed by [%s], and HANDOFF.md says [%s].',
            $machine['checks']['list'],
            Handoff::checksCommand(),
        ));

        self::assertSame($machine['checks']['count'], Handoff::claimedChecks(), sprintf(
            'The machine record says the list names %d checks, and HANDOFF.md says %d.',
            $machine['checks']['count'],
            Handoff::claimedChecks(),
        ));

        $run = Handoff::run([PHP_BINARY, Handoff::root().'/bin/checks.php', '--list']);

        self::assertSame(
            0,
            $run['exit'],
            sprintf('php bin/checks.php --list exited %d: %s%s', $run['exit'], $run['output'], $run['error']),
        );

        $names = array_map(
            static fn (string $line): string => (string) strtok($line, ' '),
            Handoff::outputLines($run['output']),
        );

        self::assertSame($machine['checks']['names'], $names, sprintf(
            "The machine record says the list is:\n  - %s\nIt is:\n  - %s",
            implode("\n  - ", $machine['checks']['names']),
            implode("\n  - ", $names),
        ));

        $analysis = Handoff::phpstan();
        $paths = $analysis['paths'];
        $recordedPaths = $machine['staticAnalysis']['paths'];

        sort($paths);
        sort($recordedPaths);

        self::assertSame($machine['staticAnalysis']['config'], $analysis['config'], sprintf(
            'The machine record says the analysis is configured in [%s], and HANDOFF.md says [%s].',
            $machine['staticAnalysis']['config'],
            $analysis['config'],
        ));

        self::assertSame($machine['staticAnalysis']['level'], $analysis['level'], sprintf(
            'The machine record says the analysis runs at level [%s], and HANDOFF.md says [%s].',
            $machine['staticAnalysis']['level'],
            $analysis['level'],
        ));

        self::assertSame($recordedPaths, $paths, sprintf(
            'The machine record says the analysis covers %s, and HANDOFF.md says %s.',
            implode(', ', $recordedPaths),
            implode(', ', $paths),
        ));
    }

    /**
     * The one thing a row *added* to section 5 can break, which every test above reads past.
     *
     * WHY THIS IS NOT ONE OF THE COMPARISONS ABOVE
     * --------------------------------------------
     *   Each test above takes a row by the label it knows — `Handoff::php()`, `Handoff::composer()`,
     *   `Handoff::search()`, `Handoff::ships()` — so a row nobody asks for is a row nothing
     *   compares. The table can grow a field for the machine (`PowerShell`, the container the suite
     *   runs in, the editor) and stay green while describing one machine more than
     *   `.agents/machine.local.json` — the record the table is *held to* — does. The gap is an
     *   absence, so it is invisible in a diff of either file.
     *
     *   This therefore asks the other question: the rows the table *has*, rather than the rows a
     *   reader asks for. `Handoff::working()` is that list read against the contract in the reader
     *   (`WORKING_LEAVES`), which says which of the record's leaves holds each row, and which rows
     *   (one, so far) the record does not hold at all. Two answers fail it: a row the contract does
     *   not name, which is a row nobody has decided about; and a row held by a path that is not a
     *   leaf of `Handoff::machineLeaves()` — coverage in name only, because those leaves are the
     *   ones `machine()` refuses a record without, and a path nothing asks the record for would
     *   cover the row with nothing.
     *
     * WHY IT DOES NOT SKIP WHERE THE OTHERS DO
     * ---------------------------------------
     *   The comparisons above need the record, so they skip in a checkout without one — a fresh
     *   clone, CI's runner. This one does not: the answer is the contract, which is in the reader,
     *   and a checkout with no record is exactly where a row nobody has decided about would
     *   otherwise arrive unremarked, in the checkout and on the runner both.
     */
    public function test_section_5_has_no_row_the_machine_record_does_not_cover(): void
    {
        $leaves = Handoff::machineLeaves();
        $rows = Handoff::working();
        $undecided = [];
        $unheld = [];

        foreach ($rows as $row) {
            if (!$row['known']) {
                $undecided[] = sprintf('%s (line %d)', $row['label'], $row['source']);

                continue;
            }

            foreach ($row['leaves'] ?? [] as $path) {
                if (!in_array($path, $leaves, true)) {
                    $unheld[] = sprintf('%s (line %d) is held by %s', $row['label'], $row['source'], $path);
                }
            }
        }

        self::assertGreaterThanOrEqual(
            10,
            count($rows),
            sprintf(
                'HANDOFF.md section 5 has %d rows. This guard reads the table it finds rather than a count kept '
                .'beside it, so a table cut back to a handful of rows would be read as the whole of section 5.',
                count($rows),
            ),
        );

        self::assertSame(
            [],
            $undecided,
            sprintf(
                "HANDOFF.md section 5 has rows nothing says which machine-record leaves hold:\n  - %s\n".
                "A row is how a reader learns a fact about the machine, and `.agents/machine.local.json` is where those\n".
                "facts live — so a row names the record's leaves for it in the reader's contract, or is written there as\n".
                "one the record does not hold. A row in neither is one nothing compares: the record would not notice it\n".
                'missing, and the table would describe a machine the record does not.',
                implode("\n  - ", $undecided),
            ),
        );

        self::assertSame(
            [],
            $unheld,
            sprintf(
                "HANDOFF.md section 5 has rows held by leaves the machine record is not required to carry:\n  - %s\n".
                "A row is covered only by a leaf of `Handoff::machineLeaves()` — the list `machine()` proves the record\n".
                'has, and refuses a record without — because a path nothing asks the record for covers the row with nothing.',
                implode("\n  - ", $unheld),
            ),
        );
    }

    public function test_the_machine_record_describes_this_checkout(): void
    {
        $machine = self::machineRecord();

        self::assertSame(
            realpath(Handoff::root()),
            realpath($machine['package']['dir']),
            sprintf(
                'The machine record says this checkout is [%s], and the package root reads [%s].',
                $machine['package']['dir'],
                realpath(Handoff::root()),
            ),
        );

        self::assertFileExists($machine['php']['exe'], sprintf(
            'The machine record points PHP at [%s], which is not on this machine.',
            $machine['php']['exe'],
        ));

        self::assertSame($machine['php']['dir'], dirname($machine['php']['exe']), sprintf(
            'The machine record splits PHP into dir [%s] and exe [%s], which do not sit together.',
            $machine['php']['dir'],
            $machine['php']['exe'],
        ));

        self::assertFileExists($machine['pwsh']['exe'], sprintf(
            'The machine record points pwsh at [%s], which is not on this machine.',
            $machine['pwsh']['exe'],
        ));

        self::assertFileExists($machine['composer']['phar'], sprintf(
            'The machine record says composer.phar is [%s], which is not on this machine.',
            $machine['composer']['phar'],
        ));

        if ($machine['search']['rgOnPath']) {
            self::assertFileExists($machine['search']['rgExe'], sprintf(
                'The machine record says rg answers on PATH at [%s], which is not on this machine.',
                $machine['search']['rgExe'],
            ));
        }

        self::assertFileExists(Handoff::root().'/'.$machine['skills']['index'], sprintf(
            'The machine record says the skills are indexed by [%s], which this checkout does not have.',
            $machine['skills']['index'],
        ));

        $pint = json_decode((string) file_get_contents(Handoff::root().'/pint.json'), true);

        self::assertIsArray($pint, 'pint.json is not readable to be compared with the machine record.');

        self::assertSame($pint['preset'] ?? null, $machine['style']['preset'], sprintf(
            'pint.json asks for the [%s] preset, and the machine record says [%s].',
            $pint['preset'] ?? '(none)',
            $machine['style']['preset'],
        ));

        $neon = (string) file_get_contents(Handoff::root().'/phpstan.neon.dist');

        self::assertSame(
            1,
            preg_match('/phpVersion:\s*(\S+)/', $neon, $version),
            'phpstan.neon.dist no longer states a phpVersion for the machine record to agree with.',
        );

        self::assertSame($version[1], $machine['staticAnalysis']['phpVersion'], sprintf(
            'phpstan.neon.dist floors the analysis at [%s], and the machine record says [%s].',
            $version[1],
            $machine['staticAnalysis']['phpVersion'],
        ));

        $ignored = Handoff::git('check-ignore', '--quiet', '.agents/machine.local.json');

        self::assertSame(
            0,
            $ignored['exit'],
            '.agents/machine.local.json is not gitignored, so the machine record would be committed with everything else.',
        );

        $attribute = Handoff::git('check-attr', 'export-ignore', '--', '.agents/machine.local.json');

        self::assertSame(0, $attribute['exit'], sprintf('git could not be asked about the machine record: %s', $attribute['error']));

        self::assertStringEndsWith(
            ': set',
            trim($attribute['output']),
            '.agents/machine.local.json is not export-ignored, so the machine record would ship in a consumer\'s tarball.',
        );

        $probe = array_merge(
            glob(Handoff::root().'/bin/*') ?: [],
            glob(Handoff::root().'/docs/*.md') ?: [],
        );
        $unread = [];

        foreach ($machine['envVars']['read'] as $name) {
            $found = false;

            foreach ($probe as $file) {
                if (is_file($file) && str_contains((string) file_get_contents($file), $name)) {
                    $found = true;

                    break;
                }
            }

            if (!$found) {
                $unread[] = $name;
            }
        }

        self::assertSame([], $unread, sprintf(
            'The machine record lists environment variables nothing in bin/ or docs/ reads: %s.',
            implode(', ', $unread),
        ));
    }

    /**
     * The machine record every test above compares against, or a skip when this checkout has
     * none — the file is gitignored and describes one machine, so a fresh clone and CI's runner
     * have nothing to compare rather than something wrong to compare against.
     *
     * @return array<string, mixed>
     */
    private static function machineRecord(): array
    {
        $machine = Handoff::machine();

        if ($machine === null) {
            self::markTestSkipped(
                '.agents/machine.local.json is not in this checkout, so section 5 has no machine record to be held '.
                'to here. Recreate it from machine.local.json.example to run this half of the guard.',
            );
        }

        return $machine;
    }

    /**
     * The version that is the largest of a list, which order of creation does not decide: `v0.1.0`
     * is newer than `v0.0.9-alpha1` whichever came first.
     *
     * @param list<string> $tags
     */
    private static function newest(array $tags): string
    {
        $newest = null;

        foreach ($tags as $tag) {
            if ($newest === null || version_compare(ltrim($tag, 'v'), ltrim($newest, 'v'), '>')) {
                $newest = $tag;
            }
        }

        self::assertNotNull($newest, 'This repository has no v* tag, so the note describes a lane that does not exist.');

        return $newest;
    }

    /**
     * What `git` says about one attribute of a list of paths, keyed by path — asked once for all of
     * them, because `.gitattributes` decides by pattern and the question is only asked to the tool
     * that reads it.
     *
     * @param list<string> $paths
     * @return array<string, string>
     */
    private static function attributes(array $paths): array
    {
        $run = Handoff::git('check-attr', 'export-ignore', ...$paths);

        self::assertSame(0, $run['exit'], sprintf('git could not be asked about the tarball: %s', $run['error']));

        $attributes = [];

        foreach (Handoff::outputLines($run['output']) as $line) {
            if (preg_match('/^(.*?): export-ignore: (.*)$/', $line, $matches) === 1) {
                $attributes[$matches[1]] = $matches[2];
            }
        }

        self::assertCount(
            count($paths),
            $attributes,
            sprintf('git answered about %d of the %d paths, so half of this comparison would have been against nothing.', count($attributes), count($paths)),
        );

        return $attributes;
    }

    /**
     * `phpstan.neon.dist` as it is configured: the level, and the paths the analysis covers.
     *
     * @return array{level: string, paths: list<string>}
     */
    private static function phpstanNeon(): array
    {
        $raw = @file_get_contents(Handoff::root().'/phpstan.neon.dist');

        self::assertIsString($raw, 'phpstan.neon.dist is not there to be compared with the note.');
        self::assertSame(1, preg_match('/^\s*level:\s*(\S+)\s*$/m', $raw, $level), 'phpstan.neon.dist no longer states a level.');

        $paths = [];
        $inside = false;

        foreach (explode("\n", $raw) as $line) {
            if (preg_match('/^\s*paths:\s*$/', $line) === 1) {
                $inside = true;

                continue;
            }

            if (!$inside) {
                continue;
            }

            if (preg_match('/^\s*-\s*(\S+)\s*$/', $line, $matches) === 1) {
                $paths[] = trim($matches[1], '/');

                continue;
            }

            break;
        }

        self::assertNotSame([], $paths, 'phpstan.neon.dist no longer states the paths the analysis covers.');

        return ['level' => $level[1], 'paths' => $paths];
    }

    /**
     * A breakdown as the note writes one — `feat 14, fix 15` — so a failure quotes both sides the
     * way a reader would compare them.
     *
     * @param array<string, int> $counts
     */
    private static function written(array $counts): string
    {
        return implode(', ', array_map(
            static fn (string $type, int $count): string => $type.' '.$count,
            array_keys($counts),
            array_values($counts),
        ));
    }
}
