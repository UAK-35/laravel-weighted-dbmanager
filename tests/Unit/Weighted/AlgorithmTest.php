<?php

namespace Uak35\WeightedDbManager\Tests\Unit\Weighted;

use Uak35\WeightedDbManager\Database\Weighted\Algorithm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure SWRR algorithm. Verifies:
 *   • Exact distribution over N×Σweights draws
 *   • Reference sequence (Nginx)
 *   • No clumping (max 2 consecutive for asymmetric weights)
 *   • Edge cases (single, all-zero, mismatched sizes)
 *   • State immutability — Algorithm::step() must not mutate its inputs
 *
 * Run: vendor/bin/phpunit tests/Unit/Weighted/AlgorithmTest.php --testdox
 */
class AlgorithmTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // Reference: Nginx weights 5:1:1 (total=7), expected 0,0,1,0,2,0,0
    // ─────────────────────────────────────────────────────────────────────────

    public function test_nginx_reference_sequence(): void
    {
        $weights = [5, 1, 1];
        $cw = Algorithm::initialState(3);
        $expected = [0, 0, 1, 0, 2, 0, 0];   // one full cycle (7 steps)

        foreach ($expected as $step => $want) {
            [$idx, $cw] = Algorithm::step($cw, $weights);
            $this->assertSame(
                $want,
                $idx,
                "Step {$step}: expected {$want}, got {$idx}",
            );
        }

        // After one full cycle state is [0,0,0].
        $this->assertSame([0, 0, 0], $cw, 'State should reset after one cycle.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Distribution accuracy over many cycles
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param int[] $weights
     */
    #[DataProvider('weightProvider')]
    public function test_distribution_matches_weights(array $weights): void
    {
        $n = count($weights);
        $total = array_sum($weights);
        $cw = Algorithm::initialState($n);
        $hits = array_fill(0, $n, 0);
        $cycles = 1000;

        for ($i = 0; $i < $cycles * $total; $i++) {
            [$idx, $cw] = Algorithm::step($cw, $weights);
            $hits[$idx]++;
        }

        foreach ($weights as $i => $w) {
            $this->assertSame(
                $cycles * $w,
                $hits[$i],
                "Replica {$i}: expected " . ($cycles * $w) . " hits, got {$hits[$i]}",
            );
        }
    }

    public static function weightProvider(): array
    {
        return [
            '2 replicas equal' => [[1, 1]],
            '2 replicas 2:1' => [[2, 1]],
            '3 replicas 5:1:1 (Nginx)' => [[5, 1, 1]],
            '3 replicas 5:2:1' => [[5, 2, 1]],
            '4 replicas 7:5:3:2' => [[7, 5, 3, 2]],
            'cpu/ram result 160:80:40' => [[160, 80, 40]],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Smoothness — no clumping for 5:2:1 (replica 0 has weight 5)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_consecutive_clumping_for_5_2_1(): void
    {
        $weights = [5, 2, 1];
        $cw = Algorithm::initialState(3);
        $prev = -1;
        $streak = 0;

        for ($i = 0; $i < 40; $i++) {
            [$idx, $cw] = Algorithm::step($cw, $weights);

            if ($idx === $prev) {
                $streak++;
            } else {
                $streak = 1;
                $prev = $idx;
            }

            $this->assertLessThanOrEqual(
                2,
                $streak,
                "Replica {$idx} appeared {$streak} times in a row at step {$i}.",
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Edge cases
    // ─────────────────────────────────────────────────────────────────────────

    public function test_single_replica_always_picked(): void
    {
        $weights = [7];
        $cw = Algorithm::initialState(1);

        for ($i = 0; $i < 10; $i++) {
            [$idx, $cw] = Algorithm::step($cw, $weights);
            $this->assertSame(0, $idx);
        }
    }

    public function test_all_zero_weights_returns_first(): void
    {
        [$idx] = Algorithm::step([0, 0, 0], [0, 0, 0]);
        $this->assertSame(0, $idx);
    }

    public function test_equal_weights_distribute_evenly(): void
    {
        $weights = [3, 3, 3];
        $cw = Algorithm::initialState(3);
        $hits = [0, 0, 0];

        for ($i = 0; $i < 30; $i++) {
            [$idx, $cw] = Algorithm::step($cw, $weights);
            $hits[$idx]++;
        }

        $this->assertSame([10, 10, 10], $hits);
    }

    public function test_mismatched_current_weights_resets(): void
    {
        // Caller passes a wrong-sized current_weights array (e.g. after a
        // topology change that the caller forgot to handle).
        // step() should detect the mismatch and re-initialise to zeros
        // rather than throwing.
        $weights = [3, 1, 1];
        $cw = [0];   // wrong size
        [$idx, $newCw] = Algorithm::step($cw, $weights);

        $this->assertSame(3, count($newCw));
        $this->assertGreaterThanOrEqual(0, $idx);
        $this->assertLessThan(3, $idx);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // State immutability — step() must not modify its input arrays
    // ─────────────────────────────────────────────────────────────────────────

    public function test_step_does_not_mutate_input_arrays(): void
    {
        $weights = [5, 2, 1];
        $cw = [0, 0, 0];
        $origCw = $cw;
        $origW = $weights;

        Algorithm::step($cw, $weights);

        $this->assertSame($origCw, $cw, 'current_weights input was mutated.');
        $this->assertSame($origW, $weights, 'static weights input was mutated.');
    }
}
