<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Infrastructure\Builder\Support;

/**
 * Splits an integer total across named buckets by weight, using
 * largest-remainder rounding so the parts always sum back to exactly
 * $total (plain round()/intdiv() per-bucket would drift by a few units).
 */
final class WeightedDistributor
{
    /**
     * @param array<string,float> $weights Need not sum to 1.0 - normalized internally.
     * @param array<string,int>   $minimums Optional per-bucket floor (e.g. "at least 1").
     * @return array<string,int> Same keys as $weights, values summing to $total.
     */
    public static function distribute(int $total, array $weights, array $minimums = []): array
    {
        $weightSum = array_sum($weights);
        if ($weightSum <= 0.0 || $total <= 0) {
            return array_map(static fn () => 0, $weights);
        }

        $raw = [];
        $floors = [];
        foreach ($weights as $key => $weight) {
            $share = $total * ($weight / $weightSum);
            $floors[$key] = (int) floor($share);
            $raw[$key] = $share - $floors[$key];
        }

        $result = $floors;
        foreach ($minimums as $key => $min) {
            if (isset($result[$key]) && $result[$key] < $min) {
                $result[$key] = $min;
            }
        }

        $remainder = $total - array_sum($result);

        // Distribute whatever's left to the buckets with the largest
        // fractional remainder first, so the sum matches $total exactly.
        arsort($raw);
        foreach (array_keys($raw) as $key) {
            if ($remainder <= 0) {
                break;
            }
            $result[$key]++;
            $remainder--;
        }

        // If minimums pushed the sum over $total, trim from the largest
        // buckets (never below their own minimum) until it fits again.
        while ($remainder < 0) {
            arsort($result);
            $largestKey = array_key_first($result);
            $floor = $minimums[$largestKey] ?? 0;
            if ($result[$largestKey] <= $floor) {
                break;
            }
            $result[$largestKey]--;
            $remainder++;
        }

        return $result;
    }
}
