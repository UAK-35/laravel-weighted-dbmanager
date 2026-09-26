<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Tests\Support\ReleaseRepo;

/**
 * The gate: a bump declared by hand may never be smaller than the one the policy
 * weighs.
 *
 * That is the accident this whole policy exists to prevent — shipping a breaking
 * change as a patch, so nobody downstream is told to look — and the gate is the only
 * place it can still be stopped, because by then the version is a decision rather
 * than a reading. Overshooting is allowed: a bigger number is a promise about the
 * future, not a claim about the changes. Undershooting is not, unless the release is
 * overridden out loud.
 */
final class DeclaredBumpTest extends TestCase
{
    /** The notes declaring a patch. */
    private const FIXED = "### Fixed\n\n- A ported defect, fixed.\n";

    /** The notes declaring a new capability. */
    private const ADDED = "### Added\n\n- A command nobody could run before.\n";

    /** The notes declaring a breaking change. */
    private const REMOVED = "### Removed\n\n- The old command, gone.\n";

    public function test_a_version_that_undersells_the_changes_is_refused(): void
    {
        $repo = ReleaseRepo::make(self::ADDED);
        $repo->tag('v1.0.0');

        $run = $repo->release('--version=1.0.1', '--dry-run');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->refused('These changes call for a minor release, but --version=1.0.1 was declared.'),
            $run->describe(),
        );

        // The refusal carries its working, so the number is arguable rather than
        // mysterious: --weigh is named as the way out.
        $this->assertTrue($run->refused('Release with --weigh to let the policy pick'), $run->describe());
    }

    public function test_a_declared_bump_below_the_weighed_one_is_refused(): void
    {
        $repo = ReleaseRepo::make(self::REMOVED);
        $repo->tag('v1.0.0');

        $run = $repo->release('--minor', '--dry-run');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->refused('These changes call for a major release, but --minor was declared.'),
            $run->describe(),
        );
    }

    /**
     * A bigger declaration is a deliberate decision to move the line on. It is
     * allowed, and the plan says it was above what the changes called for, so nobody
     * later reads the number as the weighing's own answer.
     */
    public function test_declaring_more_than_the_changes_call_for_is_allowed(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v1.0.0');

        $run = $repo->release('--minor', '--dry-run');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertSame(
            'minor  (declared as --minor; the changes call for patch)',
            $run->plan('bump'),
            $run->describe(),
        );
        $this->assertSame('1.1.0  (tag v1.1.0)', $run->plan('next version'), $run->describe());
        $this->assertTrue(
            $run->said('--minor is above what the changes call for (patch)'),
            $run->describe(),
        );
    }

    /**
     * The escape hatch is loud by design: it releases below the weighing, and both the
     * note and the plan's own line say so, so the override is visible in the run's
     * output rather than only in whoever's memory of passing the flag.
     */
    public function test_ignore_policy_releases_anyway_and_says_so(): void
    {
        $repo = ReleaseRepo::make(self::REMOVED);
        $repo->tag('v1.0.0');

        $run = $repo->release('--minor', '--ignore-policy', '--dry-run');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->said('--ignore-policy: releasing 1.1.0, below the major the changes call for'),
            $run->describe(),
        );
        $this->assertSame(
            'minor  (declared as --minor; the changes call for major)',
            $run->plan('bump'),
            $run->describe(),
        );
        $this->assertSame('1.1.0  (tag v1.1.0)', $run->plan('next version'), $run->describe());
    }

    /**
     * `--patch` used to be how a patch was declared by hand. It is not a rail but a
     * removal: a usage error, not a policy failure, and it points at the flag that
     * replaced it rather than silently reading as unknown.
     */
    public function test_patch_is_refused_with_a_pointer_to_weigh(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v1.0.0');

        $run = $repo->release('--patch', '--dry-run');

        $this->assertSame(2, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('--patch was removed'), $run->describe());
        $this->assertTrue($run->refused('Use --weigh.'), $run->describe());
    }

    /**
     * A version is a promise about ordering, so going backwards is refused before the
     * weighing is even consulted.
     */
    public function test_a_version_that_is_not_newer_is_refused(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v1.0.0');

        $run = $repo->release('--version=0.9.0', '--dry-run');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->refused('0.9.0 is not newer than the latest release (1.0.0).'),
            $run->describe(),
        );
    }

    /**
     * A declared bump that matches the weighing is the normal case, and it must not be
     * refused: the gate is about underselling, not about hand-declaring at all.
     */
    public function test_declaring_exactly_what_the_changes_call_for_is_allowed(): void
    {
        $repo = ReleaseRepo::make(self::ADDED);
        $repo->tag('v1.0.0');

        $run = $repo->release('--minor', '--dry-run');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertSame(
            'minor  (declared as --minor; the changes call for minor)',
            $run->plan('bump'),
            $run->describe(),
        );
        $this->assertSame('1.1.0  (tag v1.1.0)', $run->plan('next version'), $run->describe());
    }
}
