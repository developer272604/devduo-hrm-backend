<?php

namespace App\Domain\Attendance\Engine;

/**
 * Step (b): turns an ordered punch list into sessions according to `rules.pairing.strategy`.
 *
 * Direction resolution:
 *  - strict:      declared IN/OUT are trusted; UNKNOWN takes the OPPOSITE of the previous resolved direction (IN when it is the first).
 *  - alternating: declared directions are IGNORED; punches alternate IN, OUT, IN, ... purely by position (an odd count leaves an open IN).
 *  - first_last:  declared directions are IGNORED; the first punch is IN and the last is OUT. Intermediate punches are used
 *                 as breaks (alternating OUT, IN, ...) only when `breaks.mode` = punch AND their count is even; otherwise they are ignored.
 *                 A single punch is an IN with a missing OUT.
 *
 * Pairing (all strategies): IN opens a session; the next OUT closes it. An IN while a session is open is a duplicate_in (the
 * EARLIER IN is kept, the later is unpaired). An OUT with no open session is missing_in when nothing precedes it, else duplicate_out.
 * An IN left open at the end is missing_out; the engine never invents the OUT here.
 * A session longer than `max_session_minutes` is CAPPED to that length (the cap is reported; no OUT time is invented).
 */
final class Pairer
{
    /** @param array<int,Punch> $punches ordered by time then id */
    public static function pair(array $punches, string $strategy, string $breakMode, int $maxSessionMinutes): Pairing
    {
        [$resolved, $ignored] = self::resolve(array_values($punches), $strategy, $breakMode);

        $sessions = [];
        $anomalies = [];
        $open = null;
        $seenAny = false;
        foreach ($resolved as [$punch, $dir]) {
            if ($dir === 'IN') {
                if ($open !== null) {
                    $anomalies[] = ['kind' => 'duplicate_in', 'punch' => $punch];
                } else {
                    $open = $punch;
                }
            } else {
                if ($open === null) {
                    $anomalies[] = ['kind' => $seenAny ? 'duplicate_out' : 'missing_in', 'punch' => $punch];
                } else {
                    $raw = $punch->at->getTimestamp() - $open->at->getTimestamp();
                    $cap = $maxSessionMinutes * 60;
                    $capped = $maxSessionMinutes > 0 && $raw > $cap;
                    $sessions[] = new Session($open, $punch, $capped ? $cap : $raw, false, $capped);
                    $open = null;
                }
            }
            $seenAny = true;
        }
        if ($open !== null) {
            $anomalies[] = ['kind' => 'missing_out', 'punch' => $open];
        }

        $log = [];
        foreach ($resolved as [$punch, $dir]) {
            $log[] = ['id' => $punch->id, 'given' => $punch->direction, 'resolved' => $dir];
        }

        return new Pairing($sessions, $anomalies, $log, $ignored);
    }

    /** @return array{0:array<int,array{0:Punch,1:string}>,1:array<int,string>} */
    private static function resolve(array $punches, string $strategy, string $breakMode): array
    {
        $n = count($punches);
        $out = [];
        $ignored = [];
        if ($strategy === 'alternating') {
            foreach ($punches as $i => $p) {
                $out[] = [$p, $i % 2 === 0 ? 'IN' : 'OUT'];
            }

            return [$out, $ignored];
        }
        if ($strategy === 'first_last') {
            if ($n === 1) {
                return [[[$punches[0], 'IN']], []];
            }
            $useMiddle = $breakMode === 'punch' && ($n - 2) % 2 === 0;
            foreach ($punches as $i => $p) {
                if ($i === 0) {
                    $out[] = [$p, 'IN'];
                } elseif ($i === $n - 1) {
                    $out[] = [$p, 'OUT'];
                } elseif ($useMiddle) {
                    $out[] = [$p, $i % 2 === 1 ? 'OUT' : 'IN'];
                } else {
                    $ignored[] = $p->id;
                }
            }

            return [$out, $ignored];
        }

        // strict (default for any unknown value as well)
        $prev = null;
        foreach ($punches as $p) {
            if ($p->direction === 'IN' || $p->direction === 'OUT') {
                $dir = $p->direction;
            } else {
                $dir = $prev === null || $prev === 'OUT' ? 'IN' : 'OUT';
            }
            $out[] = [$p, $dir];
            $prev = $dir;
        }

        return [$out, $ignored];
    }
}
