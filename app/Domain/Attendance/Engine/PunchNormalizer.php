<?php

namespace App\Domain\Attendance\Engine;

use Carbon\CarbonImmutable;

/**
 * Step (a): duplicate removal, deterministic ordering and accepted-window filtering.
 *
 * Order of operations (all deterministic, independent of the input order):
 *  1. sort by instant, then by id (string comparison);
 *  2. a punch is a DUPLICATE when an already-kept punch has the SAME direction (UNKNOWN only matches UNKNOWN) and the
 *     two instants are at most `duplicate_window_seconds` apart; the EARLIEST is kept, the later one is dropped and
 *     recorded. Punches with different directions, or further apart than the window, are distinct and never removed;
 *  3. punches outside [windowStart, windowEnd] (both bounds inclusive) are set aside as "outside window".
 */
final class PunchNormalizer
{
    /**
     * @param  array<int,Punch>  $punches
     * @return array{kept:array<int,Punch>,duplicates:array<int,array{dropped:string,kept:string,seconds:int}>,outside:array<int,Punch>,sorted:array<int,Punch>}
     */
    public static function normalize(array $punches, int $duplicateWindowSeconds, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): array
    {
        $sorted = array_values($punches);
        usort($sorted, fn (Punch $a, Punch $b) => $a->at->getTimestamp() <=> $b->at->getTimestamp() ?: strcmp($a->id, $b->id));

        $kept = [];
        $duplicates = [];
        $lastByDirection = [];
        foreach ($sorted as $p) {
            $last = $lastByDirection[$p->direction] ?? null;
            if ($last !== null && ($p->id === $last->id || $p->at->getTimestamp() - $last->at->getTimestamp() <= $duplicateWindowSeconds)) {
                $duplicates[] = ['dropped' => $p->id, 'kept' => $last->id, 'seconds' => $p->at->getTimestamp() - $last->at->getTimestamp()];

                continue;
            }
            $lastByDirection[$p->direction] = $p;
            $kept[] = $p;
        }

        $inside = [];
        $outside = [];
        $ws = $windowStart->getTimestamp();
        $we = $windowEnd->getTimestamp();
        foreach ($kept as $p) {
            $ts = $p->at->getTimestamp();
            if ($ts >= $ws && $ts <= $we) {
                $inside[] = $p;
            } else {
                $outside[] = $p;
            }
        }

        return ['kept' => $inside, 'duplicates' => $duplicates, 'outside' => $outside, 'sorted' => $sorted];
    }
}
