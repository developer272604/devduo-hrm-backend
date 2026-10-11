<?php

namespace App\Domain\Attendance\Engine;

/**
 * Result of pairing punches into sessions.
 *
 * anomalies: list of {kind, punch}: kind = missing_out (an IN never closed) | missing_in (an OUT with nothing before it)
 *            | duplicate_in (IN while a session is open) | duplicate_out (OUT with no open session after a completed pair)
 */
final class Pairing
{
    /**
     * @param  array<int,Session>  $sessions
     * @param  array<int,array{kind:string,punch:Punch}>  $anomalies
     * @param  array<int,array{id:string,given:string,resolved:string}>  $resolved
     * @param  array<int,string>  $ignored  punch ids not used (first_last intermediates)
     */
    public function __construct(
        public readonly array $sessions,
        public readonly array $anomalies,
        public readonly array $resolved,
        public readonly array $ignored,
    ) {}
}
