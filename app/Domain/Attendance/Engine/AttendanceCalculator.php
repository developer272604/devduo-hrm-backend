<?php

namespace App\Domain\Attendance\Engine;

/**
 * THE ATTENDANCE CALCULATION ENGINE: a pure, deterministic, versioned and explainable calculator.
 *
 * PURE means: no database, no facades, no clock (`CalculationInput::$now` is injected), no randomness and no static mutable
 * state. The same input always produces a byte-identical `CalculationResult::toArray()` (and `steps`), independent of the
 * order in which punches were supplied. Every decision is appended to `steps` as {step, rule, inputs, outcome}; the
 * `explanation` is generated from those steps only (see Explainer). Full contract: docs/attendance-engine.md.
 *
 * VERSIONING (ENGINE_VERSION, semantic):
 *  - the processor stores ENGINE_VERSION with EVERY result, so historical results stay explainable and are never silently
 *    reinterpreted: a stored result is only ever recomputed by an explicit, audited recalculation;
 *  - MAJOR: a change that can alter status/minutes of already-valid inputs (e.g. new rounding or grace semantics, a different
 *    pairing rule, a changed status decision);
 *  - MINOR: new behavior that only affects inputs that did not use the feature before (a new optional rule, new exception,
 *    new optional DTO field) or new trace/explanation detail that does not change numbers;
 *  - PATCH: bug fixes that change results ONLY for inputs that were previously computed wrongly, and wording-only changes.
 *  Any behavioral change MUST bump the version in the same commit AND add/adjust a test pinning the new behavior.
 */
final class AttendanceCalculator
{
    public const ENGINE_VERSION = '1.0.0';

    /** Static so both `AttendanceCalculator::calculate($in)` and `(new AttendanceCalculator)->calculate($in)` work (no state is kept). */
    public static function calculate(CalculationInput $input): CalculationResult
    {
        return (new CalculationRun($input))->execute();
    }
}
