<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Pgcat;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Pgcat\DryRunResult;

/**
 * The rehearsal's value object. What matters here is that its kinds mean the same thing a
 * FlipResult's do — so a caller (and db:pgcat-flip's exit code) can read either one the same
 * way — and that the pipeline survives the round trip into an array.
 */
class DryRunResultTest extends TestCase
{
    /** @var list<array{step: string, outcome: string, detail: string}> */
    private array $steps = [
        ['step' => 'lock', 'outcome' => DryRunResult::STEP_DONE, 'detail' => '/tmp/flip.lock'],
        ['step' => 'read source', 'outcome' => DryRunResult::STEP_DONE, 'detail' => '/etc/pgcat/readers.toml (17 bytes)'],
        ['step' => 'rename', 'outcome' => DryRunResult::STEP_WOULD, 'detail' => '/etc/pgcat/pgcat.toml.tmp.1 → /etc/pgcat/pgcat.toml'],
        ['step' => 'state file', 'outcome' => DryRunResult::STEP_WOULD, 'detail' => '/tmp/flip-state.json (writable, left as it is)'],
    ];

    public function test_a_rehearsal_that_would_flip_exits_zero(): void
    {
        $result = DryRunResult::wouldFlip('readers', 'writer', $this->steps);

        $this->assertSame('would_flip', $result->kind());
        $this->assertTrue($result->wouldFlipHappen());
        $this->assertSame(0, $result->exitCode());
        $this->assertSame('readers', $result->mode);
        $this->assertSame('writer', $result->previousMode);
        $this->assertNull($result->error);
        $this->assertSame(
            'would flip: writer → readers (nothing was renamed, and pgcat was neither signalled nor restarted)',
            $result->summary(),
        );
    }

    public function test_a_first_flip_says_never_instead_of_naming_no_mode(): void
    {
        $result = DryRunResult::wouldFlip('readers', null, $this->steps);

        $this->assertStringContainsString('would flip: never → readers', $result->summary());
        $this->assertStringContainsString('the mode has never been applied', $result->reason);
    }

    public function test_a_rehearsal_with_nothing_to_do_is_not_a_failure(): void
    {
        $result = DryRunResult::wouldNotFlip('readers', 'mode unchanged since last flip', $this->steps);

        $this->assertFalse($result->wouldFlipHappen());
        $this->assertSame(0, $result->exitCode());
        $this->assertSame(
            'would not flip (mode=readers): mode unchanged since last flip',
            $result->summary(),
        );
    }

    public function test_a_rehearsal_a_flip_would_skip_is_not_a_failure_either(): void
    {
        $result = DryRunResult::skipped('readers', 'another flipper instance holds the lock', $this->steps);

        $this->assertFalse($result->wouldFlipHappen());
        $this->assertSame(0, $result->exitCode(), 'a flip would skip too — the lock is doing its job');
        $this->assertStringContainsString('a flip would be skipped (mode=readers)', $result->summary());
        $this->assertStringContainsString('another flipper instance holds the lock', $result->summary());
    }

    public function test_a_rehearsal_that_finds_a_broken_step_exits_one(): void
    {
        $result = DryRunResult::failed('readers', 'The temp file a swap writes could not be created: /etc/pgcat/pgcat.toml.tmp.1', $this->steps);

        $this->assertFalse($result->wouldFlipHappen());
        $this->assertSame(1, $result->exitCode());
        $this->assertSame('a step a flip needs did not work', $result->reason);
        $this->assertStringContainsString('a flip would fail (mode=readers)', $result->summary());
        $this->assertStringContainsString('could not be created', $result->summary());
    }

    public function test_a_step_can_be_read_by_name_without_walking_the_list(): void
    {
        $result = DryRunResult::wouldFlip('readers', null, $this->steps);

        $this->assertSame('done', $result->step('read source')['outcome']);
        $this->assertSame('would', $result->step('rename')['outcome']);
        $this->assertNull($result->step('supervisor'), 'a step the rehearsal never reached has no row');
    }

    public function test_the_array_form_carries_the_pipeline_unchanged(): void
    {
        $result = DryRunResult::wouldFlip('readers', 'writer', $this->steps);

        $this->assertSame([
            'kind' => 'would_flip',
            'mode' => 'readers',
            'previous_mode' => 'writer',
            'reason' => $result->reason,
            'error' => null,
            'steps' => $this->steps,
        ], $result->toArray());
    }

    /**
     * Every kind but one is a rehearsal that found nothing to do or something in the way. The
     * exit code is what a pipeline reads, so it has to be the kind that failed and nothing
     * else.
     */
    public function test_only_a_failed_rehearsal_is_non_zero(): void
    {
        $kinds = [
            DryRunResult::wouldFlip('readers', null, [])->exitCode(),
            DryRunResult::wouldNotFlip('readers', 'unchanged', [])->exitCode(),
            DryRunResult::skipped('readers', 'locked', [])->exitCode(),
            DryRunResult::failed('readers', 'broken', [])->exitCode(),
        ];

        $this->assertSame([0, 0, 0, 1], $kinds);
    }
}
