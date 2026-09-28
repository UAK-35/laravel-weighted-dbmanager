<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Tests\Support\ReleaseRepo;

/**
 * The CI rail: a tag is a version, so it is not cut on a commit nothing verified.
 *
 * The commit that has to be verified is the one the release is *built on* — HEAD, the tip of
 * the branch — because the release commit this script goes on to create cannot have a run
 * yet: it is not on the remote until the push at the end. So the rail asks two questions,
 * and these tests pin both, plus the states in between: is HEAD the tip of the remote
 * branch, and does the workflow have a finished, passing run for it?
 *
 * Nothing here reaches the network. The fixture pushes to a bare repository beside itself
 * for the first question, and answers the second with `withCiAnswer()` — the JSON gh would
 * have printed — so a failing test is always about the rail rather than about GitHub.
 */
final class CiGateTest extends TestCase
{
    /** An entry under `### Fixed` — the lightest notes, so the weighing is quiet. */
    private const FIXED = "### Fixed\n\n- A ported defect, fixed.\n";

    /**
     * The JSON `gh run list --commit …` prints for one commit, as the workflow reports it.
     *
     * @param string|null $conclusion null is how a run that has not finished reports itself
     */
    private static function runs(
        string $branch,
        string $status = 'completed',
        ?string $conclusion = 'success',
        string $event = 'push',
        string $name = 'PHP Composer',
    ): string {
        return json_encode([[
            'name' => $name,
            'status' => $status,
            'conclusion' => $conclusion,
            'event' => $event,
            'headBranch' => $branch,
        ]], JSON_THROW_ON_ERROR);
    }

    /** A fixture with a remote, a branch pushed to it, and the tip's sha. */
    private static function pushed(): array
    {
        $repo = ReleaseRepo::make(self::FIXED)->withRemote();
        $repo->git('push', '-u', 'origin', 'main');

        return [$repo, trim($repo->git('rev-parse', 'HEAD'))];
    }

    /**
     * A branch the remote has never seen is the state a first release is in, and it is not
     * "CI failed" — it is "nothing there has been built", which is why the rail asks about
     * the remote before it asks about CI at all.
     */
    public function test_a_branch_that_is_not_on_the_remote_is_refused_without_asking(): void
    {
        $repo = ReleaseRepo::make(self::FIXED)->withRemote();
        $repo->withCiAnswer('[]');

        $run = $repo->release('--weigh', '--yes');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('origin has no main branch'), $run->describe());
        $this->assertTrue($run->refused('--skip-ci'), 'A refusal names the way out.', $run->describe());

        // It did not ask CI about a commit that is not on the remote, and it did not write,
        // commit or tag anything: this is a precondition, not a cleanup.
        $this->assertSame('', $repo->ciAsked(), $run->describe());
        $this->assertSame('', trim($repo->tags()), $run->describe());
    }

    /**
     * A commit after the push: the tree is new and the tag would point one commit past what
     * CI has seen. This is the shape the rail exists for — releasing a working tree that was
     * never pushed — and it is refused by the commit's own name.
     */
    public function test_a_commit_that_is_not_the_remote_tip_is_refused(): void
    {
        [$repo] = self::pushed();

        $repo->write('src/After.php', "<?php\n\nnamespace Fixture;\n\nfinal class After\n{\n}\n");
        $repo->commit('feat: something after the push');

        $head = trim($repo->git('rev-parse', 'HEAD'));

        $run = $repo->release('--weigh', '--yes');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('is not the tip of origin/main'), $run->describe());
        $this->assertTrue(
            $run->refused(substr($head, 0, 7)),
            'The refusal has to name the commit it refused.',
            $run->describe(),
        );
        $this->assertSame('', trim($repo->tags()), $run->describe());
    }

    public function test_a_green_run_lets_the_release_through(): void
    {
        [$repo, $head] = self::pushed();

        $repo->withCiAnswer(self::runs('main'));

        $run = $repo->release('--weigh', '--yes');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertStringContainsString('CI green', $run->plan('ci'), $run->describe());
        $this->assertStringContainsString('PHP Composer', $run->plan('ci'), $run->describe());

        // It asked about the commit being released, rather than about whatever was green.
        $this->assertSame($head, $repo->ciAsked(), $run->describe());

        $this->assertStringContainsString('v0.0.1', $repo->tags(), $run->describe());
    }

    public function test_a_failed_run_refuses_the_tag(): void
    {
        [$repo, $head] = self::pushed();

        $repo->withCiAnswer(self::runs('main', conclusion: 'failure'));

        $run = $repo->release('--weigh', '--yes');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('CI failed for ' . substr($head, 0, 7)), $run->describe());
        $this->assertTrue($run->refused('PHP Composer (failure)'), $run->describe());
        $this->assertTrue($run->refused('--skip-ci'), $run->describe());
        $this->assertSame('', trim($repo->tags()), $run->describe());
    }

    /**
     * A run still going is not a pass. The rail must not answer before CI does — answering
     * early is the same mistake as answering without asking, in the direction that publishes
     * a version nobody has finished checking.
     */
    public function test_a_run_that_has_not_finished_refuses_the_tag(): void
    {
        [$repo] = self::pushed();

        $repo->withCiAnswer(self::runs('main', status: 'in_progress', conclusion: null));

        $run = $repo->release('--weigh', '--yes');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('CI is still running'), $run->describe());
        $this->assertTrue($run->refused('PHP Composer (in_progress)'), $run->describe());
        $this->assertSame('', trim($repo->tags()), $run->describe());
    }

    public function test_a_commit_with_no_run_on_this_branch_is_refused(): void
    {
        [$repo, $head] = self::pushed();

        $repo->withCiAnswer('[]');

        $run = $repo->release('--weigh', '--yes');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('CI has no run for ' . substr($head, 0, 7)), $run->describe());
        $this->assertTrue($run->refused('--skip-ci'), $run->describe());
        $this->assertSame('', trim($repo->tags()), $run->describe());
    }

    /**
     * One commit carries runs for more than one ref, and only a push run for the branch being
     * released says anything about that branch. Both of the shapes here are real: this
     * package's own `v0.1.0-alpha1` commit has a run for the branch push and a second for the
     * tag, and the tag run reports its head branch as the *tag name*; and a branch with an
     * open pull request gets a second run for the `pull_request` event.
     */
    public function test_a_green_run_for_another_ref_is_not_a_pass(): void
    {
        [$repo] = self::pushed();

        $repo->withCiAnswer(json_encode([
            ['name' => 'PHP Composer', 'status' => 'completed', 'conclusion' => 'success', 'event' => 'push', 'headBranch' => 'v0.0.1-alpha1'],
            ['name' => 'PHP Composer', 'status' => 'completed', 'conclusion' => 'success', 'event' => 'pull_request', 'headBranch' => 'main'],
        ], JSON_THROW_ON_ERROR));

        $run = $repo->release('--weigh', '--yes');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->refused('CI has no run for'),
            'The tag run and the pull request run are not runs for this branch.',
            $run->describe(),
        );
        $this->assertSame('', trim($repo->tags()), $run->describe());
    }

    /**
     * No gh, no token, offline: the rail cannot answer "verified", and nothing verified means
     * no tag. It quotes gh's own words rather than guessing at the cause, because the three
     * failures are repaired in three different places.
     */
    public function test_ci_that_cannot_be_asked_is_refused_by_default(): void
    {
        [$repo] = self::pushed();

        $repo->withCiAnswer('', 1, 'gh: Bad credentials (HTTP 401)');

        $run = $repo->release('--weigh', '--yes');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('Could not ask CI about'), $run->describe());
        $this->assertTrue($run->refused('Bad credentials'), 'gh\'s own words are the diagnosis.', $run->describe());
        $this->assertTrue($run->refused('gh auth login'), $run->describe());
        $this->assertTrue($run->refused('--skip-ci'), $run->describe());
        $this->assertSame('', trim($repo->tags()), $run->describe());
    }

    /**
     * An answer that is not a run list cannot be read as "nothing failed", so it is refused
     * as a question that could not be answered rather than treated as an empty one.
     */
    public function test_an_answer_that_is_not_a_run_list_is_refused(): void
    {
        [$repo] = self::pushed();

        $repo->withCiAnswer('no runs here, just prose');

        $run = $repo->release('--weigh', '--yes');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('was not a list of runs'), $run->describe());
        $this->assertSame('', trim($repo->tags()), $run->describe());
    }

    /**
     * `--skip-ci` is an escape from the rail, not a variance on it: it has to release on a
     * repository with no remote at all, without consulting anything. The command it was given
     * could not run if it were asked — that is the assertion.
     */
    public function test_skip_ci_tags_without_asking_anything(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->withEnv(['RELEASE_CI_COMMAND' => 'no-such-gh-binary %SHA%']);

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertSame('not checked (--skip-ci)', $run->plan('ci'), $run->describe());
        $this->assertStringContainsString('v0.0.1', $repo->tags(), $run->describe());
    }

    /**
     * A dry run reports the state and stops at nothing. Its job is to say whether the release
     * would go through, and "push this first" is the answer the rail exists to give — so
     * printing it is the point of running the plan, not a reason to fail it.
     */
    public function test_a_dry_run_reports_the_state_without_refusing(): void
    {
        $repo = ReleaseRepo::make(self::FIXED)->withRemote();
        $repo->withCiAnswer('[]');

        $run = $repo->release('--weigh', '--dry-run');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertSame('origin has no main branch', $run->plan('ci'), $run->describe());
        $this->assertTrue($run->said('a real run would refuse to tag on this'), $run->describe());
        $this->assertTrue($run->said('Dry run: nothing was written'), $run->describe());
        $this->assertSame('', trim($repo->tags()), $run->describe());
    }
}
