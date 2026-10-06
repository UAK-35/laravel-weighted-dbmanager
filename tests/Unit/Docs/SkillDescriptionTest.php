<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Docs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Tests\Support\SkillDescriptions;

/**
 * The `pkg-*` skill descriptions, held to the commands they name.
 *
 * WHY THIS EXISTS
 * ---------------
 * A skill's frontmatter `description` is the only part of it a tool reads before choosing the
 * skill, so it is the one piece of prose in this tree that is written for a reader that acts.
 * Every other record here — the README, RELEASING.md, PUSHING.md, the design notes under
 * `docs/` — has a reader already (`DocCitationsTest`, `ProseNumbersTest`, `PushingDocTest`,
 * `ReleasingDocTest`, the README tables). The skills had none: each of those guards reads its
 * own document, none of them opened `.agents/`, and no check in `bin/checks.php` opens the
 * directory either.
 *
 * The failures that gap allows all have one shape — a description advertising something the
 * package no longer has. Rename `bin/weighing.php`, or `db:pgcat-window-flip`, or a skill
 * itself, and a description that named it goes on reading as true and sends a reader to
 * something the tree no longer answers. Nothing fails, which is exactly how a restatement
 * rots: the description and the tree are never in the same diff.
 *
 * So the descriptions are read as data and every name in them is resolved against the surface
 * that really exists — the directories under `.agents/skills/`, the files in `bin/`,
 * `composer.json`'s `scripts`, and the `$signature` each console command declares. The index
 * at `.agents/README.md` is read the same way: it is the list of skills, and the only way to
 * find out which ones exist without opening three directories.
 *
 * WHAT THIS CANNOT SEE
 * --------------------
 * A description that names nothing is not a description that is true — the last two tests are
 * therefore fixtures rather than a corpus. Every description here happens to name only its own
 * handle, and no description names a `bin/` script, a `db:` command or a `composer` script at
 * all, so the command half of this guard has no subject in the corpus today. The fixtures below
 * drive the same reader the corpus test uses, over descriptions that name something the package
 * has and something it does not, so the guard is proved able to fail rather than merely
 * observed not to have failed.
 *
 * The command shapes are the four `SkillDescriptions` documents. A description that names a
 * generic tool (`git`, `pwsh`, `composer install`) is not read as a claim about this package
 * and is not in the corpus this holds — see that class for the boundary and why it is where it
 * is.
 */
final class SkillDescriptionTest extends TestCase
{
    /**
     * Every name in every description resolves against the package.
     *
     * One assertion for the whole corpus rather than one per skill: the repair is a sweep, and a
     * report that names each finding with the skill it came from is read faster than the first
     * failure of a run that stops at it.
     */
    public function test_every_description_names_only_things_this_package_has(): void
    {
        $skills = SkillDescriptions::skills();

        $this->assertNotSame([], $skills, 'No skill description was read, so nothing was held to the package.');

        $problems = [];

        foreach ($skills as $skill) {
            foreach (SkillDescriptions::missing($skill['description']) as $finding) {
                $problems[] = sprintf('%s: %s', $skill['source'], $finding);
            }
        }

        $this->assertSame(
            [],
            $problems,
            "a skill description names something this package does not have:\n  - ".implode("\n  - ", $problems),
        );
    }

    /**
     * Each description names the handle that invokes it.
     *
     * The description's job is to be matched, and `@name` is how a skill is invoked — a
     * description that describes the work without naming its own handle is one a reader has to
     * guess the name of, and the guess is what this pins. A renamed skill satisfies this only
     * by renaming its handle too, which is the two-file change the rename was anyway.
     */
    public function test_every_description_names_the_handle_that_invokes_it(): void
    {
        foreach (SkillDescriptions::skills() as $name => $skill) {
            $this->assertContains(
                $name,
                SkillDescriptions::handles($skill['description']),
                sprintf(
                    '%s describes the work but never names @%s, so nothing in it matches an invocation of it.',
                    $skill['source'],
                    $name,
                ),
            );
        }
    }

    /**
     * The index lists every skill, and lists nothing else.
     *
     * Both directions matter and only the first is obvious: a skill missing from the index is
     * one nobody reads the list to find, and a row for a skill that no longer exists is a
     * reader opening a directory that is not there. The list is the package's only answer to
     * "which skills does this have", since it has no `verify.py` and no listing command.
     */
    public function test_the_index_lists_every_skill_and_no_other(): void
    {
        $skills = array_keys(SkillDescriptions::skills());
        $indexed = array_map(
            static fn (array $row): string => $row['skill'],
            SkillDescriptions::indexed(),
        );

        sort($indexed);

        $this->assertSame(
            $skills,
            $indexed,
            sprintf(
                ".agents/skills/ and .agents/README.md do not list the same skills.\n  on disk: %s\n  in the index: %s",
                implode(', ', $skills),
                implode(', ', $indexed),
            ),
        );
    }

    /**
     * The index's own sentences name nothing the package has lost — the same claim as the
     * descriptions, made about the file a person reads to choose a skill.
     */
    public function test_the_index_names_only_things_this_package_has(): void
    {
        $problems = [];

        foreach (SkillDescriptions::indexed() as $row) {
            foreach (SkillDescriptions::missing($row['description']) as $finding) {
                $problems[] = sprintf('.agents/README.md line %d (%s): %s', $row['source'], $row['skill'], $finding);
            }
        }

        $this->assertSame(
            [],
            $problems,
            "the skills index names something this package does not have:\n  - ".implode("\n  - ", $problems),
        );
    }

    /**
     * The guard's own proof: a description that names something the package lost is reported.
     *
     * Each case is the real description's shape with one name replaced by one that is not
     * there — a renamed script, a removed console command, a removed script, a renamed skill —
     * and the expectation is the sentence naming it, not the whole message, so the wording
     * around the finding can change without the case having to.
     *
     * @param string $description the description text, written the way a skill writes one
     * @param string $expected the finding the reader has to produce
     */
    #[DataProvider('namesThePackageNoLongerHas')]
    public function test_a_description_that_names_something_the_package_lost_is_reported(string $description, string $expected): void
    {
        $findings = SkillDescriptions::missing($description);

        $this->assertNotSame(
            [],
            $findings,
            sprintf('Nothing was reported for [%s], which names something this package does not have.', $description),
        );

        $this->assertStringContainsString(
            $expected,
            implode("\n", $findings),
            sprintf('The finding for [%s] does not name the name that is missing: %s', $description, $expected),
        );
    }

    /**
     * The other half of the proof: a description that names only things the package has is
     * clean. Without this the cases above would pass for a reader that reports everything.
     *
     * @param string $description a description that names only real skills, scripts and commands
     */
    #[DataProvider('namesOnlyWhatThePackageHas')]
    public function test_a_description_that_names_only_real_things_is_clean(string $description): void
    {
        $this->assertSame(
            [],
            SkillDescriptions::missing($description),
            sprintf('[%s] names only things this package has, and it was reported anyway.', $description),
        );
    }

    /**
     * One case per shape this guard reads, each with the package's own name for the thing in the
     * description and a name it does not have.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function namesThePackageNoLongerHas(): array
    {
        return [
            'a bin script that was renamed' => [
                'Invoke on @pkg-commit mentions. Run `php bin/checks-gone.php --only=counts` before the commit.',
                '`bin/checks-gone.php` is not a script in this package\'s bin/',
            ],
            'a console command that was removed' => [
                'Invoke on @pkg-deploy mentions. Then `db:pgcat-gone --json` reports whether the boundary moved.',
                '`db:pgcat-gone` is not a console command this package registers',
            ],
            'a composer script that was renamed' => [
                'Invoke on @pkg-deploy mentions. Cut it with `composer release-gone -- --weigh`.',
                '`composer release-gone` is neither a script composer.json declares nor one of Composer\'s own subcommands',
            ],
            'a skill that was renamed' => [
                'Invoke on @pkg-published mentions, or when a tag has to be confirmed on the remote.',
                '@pkg-published is not a skill this package has',
            ],
        ];
    }

    /**
     * One case per shape, written the way a description that is right about the package writes
     * it — including the two names that are *not* this package's: `composer install` is one of
     * Composer's own subcommands, and `dev` is a branch.
     *
     * @return array<string, array{0: string}>
     */
    public static function namesOnlyWhatThePackageHas(): array
    {
        return [
            'a skill and the fast gate' => [
                'Invoke on @pkg-commit mentions. The fast gate is `php bin/checks.php --only=counts,sentences,pint,phpstan`.',
            ],
            'a skill and one of this package\'s composer scripts' => [
                'Invoke on @pkg-deploy mentions. Cut it with `composer release -- --weigh`, then `composer test:unit`.',
            ],
            'a skill and the window flip' => [
                'Invoke on @pkg-deploy mentions, and `db:pgcat-window-flip --mode=readers --at=07:00` is what moves the pooler.',
            ],
            'a prose name that is not a command' => [
                'Invoke on @pkg-push mentions. It runs on `dev`, reads `RELEASING.md`, and passes `--skip-ci` when the rail is not satisfied.',
            ],
        ];
    }
}
