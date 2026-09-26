<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Database\Weighted;

/**
 * Pure, stateless Smooth Weighted Round-Robin (SWRR) step.
 *
 * This is the algorithm Nginx uses for upstream load balancing
 * (see phusion/nginx commit 27e94984). It guarantees:
 *
 *   • Exact distribution — over Σweights steps, each replica i appears exactly w_i times.
 *   • No clumping       — never more than ⌈w_max / gcd(w_1…w_n)⌉ consecutive draws to one replica.
 *   • Stateless          — the only state is the current_weights array, which is caller-owned.
 *
 * Sequence for weights {A:5, B:1, C:1} (total=7), one full cycle:
 *
 *   step | +weight      | current_weights  | winner | after subtract
 *   -----|--------------|------------------|--------|----------------
 *    1   | [5, 1, 1]    | [5,  1,  1]      | A(5)   | [-2,  1,  1]
 *    2   | [5, 1, 1]    | [3,  2,  2]      | A(5)   | [-4,  2,  2]
 *    3   | [5, 1, 1]    | [1,  3,  3]      | B(3)   | [ 1, -4,  3]
 *    4   | [5, 1, 1]    | [6, -3,  4]      | A(6)   | [-1, -3,  4]
 *    5   | [5, 1, 1]    | [4, -2,  5]      | C(5)   | [ 4, -2, -2]
 *    6   | [5, 1, 1]    | [9, -1, -1]      | A(9)   | [ 2, -1, -1]
 *    7   | [5, 1, 1]    | [7,  0,  0]      | A(7)   | [ 0,  0,  0] ← reset
 *
 * IMPORTANT: replicas must be in a STABLE ORDER across calls. Sort by host:port
 * before calling so position[i] always refers to the same replica.
 *
 * This class is final and has no dependencies — it's pure PHP and unit-testable
 * without Laravel, Redis, or any framework.
 */
final class Algorithm
{
    /**
     * Run one SWRR step.
     *
     * @param int[] $currentWeights Mutable state from the last step (all 0 on first call).
     * @param int[] $staticWeights Fixed weights per replica (index-aligned with $currentWeights).
     * @return array{0:int, 1:int[]} [selected_index, new_current_weights]
     */
    public static function step(array $currentWeights, array $staticWeights): array
    {
        $n = count($staticWeights);
        $total = (int) array_sum($staticWeights);

        // Guard: empty or all-zero weights — pick first.
        if ($n === 0 || $total === 0) {
            return [0, $currentWeights];
        }

        // Reject mismatched sizes early. Different lengths mean the caller
        // forgot to re-initialise after a topology change.
        if (count($currentWeights) !== $n) {
            $currentWeights = array_fill(0, $n, 0);
        }

        // 1. Increment every current_weight by its static weight.
        for ($i = 0; $i < $n; $i++) {
            $currentWeights[$i] += $staticWeights[$i];
        }

        // 2. Find the replica with the highest current_weight.
        //    Tie-break by lowest index to keep the sequence deterministic.
        $best = 0;
        for ($i = 1; $i < $n; $i++) {
            if ($currentWeights[$i] > $currentWeights[$best]) {
                $best = $i;
            }
        }

        // 3. Subtract total from the winner.
        $currentWeights[$best] -= $total;

        return [$best, $currentWeights];
    }

    /**
     * Build zero-initialised current_weight state for N replicas.
     *
     * @return int[]
     */
    public static function initialState(int $n): array
    {
        return array_fill(0, $n, 0);
    }
}
