<?php

namespace App\Domain\Attendance\Engine;

/**
 * Pure interval arithmetic on UNIX timestamps (seconds). An interval is [start, end] with end > start.
 * Used by the calculator for presence, overlap with scheduled segments and overtime windows.
 */
final class Intervals
{
    /**
     * @param  array<int,array{0:int,1:int}>  $intervals
     * @return array<int,array{0:int,1:int}> sorted, merged, empty/negative intervals removed
     */
    public static function union(array $intervals): array
    {
        $clean = [];
        foreach ($intervals as $iv) {
            if ($iv[1] > $iv[0]) {
                $clean[] = [$iv[0], $iv[1]];
            }
        }
        usort($clean, fn ($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
        $out = [];
        foreach ($clean as $iv) {
            if ($out !== [] && $iv[0] <= $out[count($out) - 1][1]) {
                $out[count($out) - 1][1] = max($out[count($out) - 1][1], $iv[1]);
            } else {
                $out[] = $iv;
            }
        }

        return $out;
    }

    public static function seconds(array $intervals): int
    {
        $total = 0;
        foreach (self::union($intervals) as $iv) {
            $total += $iv[1] - $iv[0];
        }

        return $total;
    }

    /** Total seconds where both lists overlap. */
    public static function overlap(array $a, array $b): int
    {
        $a = self::union($a);
        $b = self::union($b);
        $total = 0;
        foreach ($a as $x) {
            foreach ($b as $y) {
                $lo = max($x[0], $y[0]);
                $hi = min($x[1], $y[1]);
                if ($hi > $lo) {
                    $total += $hi - $lo;
                }
            }
        }

        return $total;
    }

    /** Parts of $intervals that are NOT covered by $cut. */
    public static function subtract(array $intervals, array $cut): array
    {
        $cut = self::union($cut);
        $out = [];
        foreach (self::union($intervals) as $iv) {
            $cursor = $iv[0];
            foreach ($cut as $c) {
                if ($c[1] <= $cursor || $c[0] >= $iv[1]) {
                    continue;
                }
                if ($c[0] > $cursor) {
                    $out[] = [$cursor, $c[0]];
                }
                $cursor = max($cursor, $c[1]);
            }
            if ($cursor < $iv[1]) {
                $out[] = [$cursor, $iv[1]];
            }
        }

        return $out;
    }

    public static function clip(array $intervals, int $lo, int $hi): array
    {
        $out = [];
        foreach ($intervals as $iv) {
            $s = max($iv[0], $lo);
            $e = min($iv[1], $hi);
            if ($e > $s) {
                $out[] = [$s, $e];
            }
        }

        return $out;
    }
}
