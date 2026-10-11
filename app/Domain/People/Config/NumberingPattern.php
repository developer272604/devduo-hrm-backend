<?php

namespace App\Domain\People\Config;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Pure validator and renderer for employee-number patterns. Rendering here never touches
 * employee_number_sequences, so previews cannot consume or disturb a live counter.
 * Mirrors the token set understood by NumberingService.
 */
final class NumberingPattern
{
    /** token => description shown in the admin UI. */
    public const TOKENS = [
        '{SEQ:n}' => 'Sequence number padded to n digits, e.g. {SEQ:5} gives 00042 (required; {SEQ} means no padding)',
        '{LE}' => 'Code of the employee\'s legal entity',
        '{LOC}' => 'Code of the employee\'s location',
        '{TYPE}' => 'Code of the employee type',
        '{COUNTRY}' => 'Two-letter country code',
        '{YYYY}' => 'Four-digit joining year',
        '{YY}' => 'Two-digit joining year',
    ];

    public const MAX_LENGTH = 60;

    private const SIMPLE = ['LE' => 10, 'LOC' => 10, 'TYPE' => 10, 'COUNTRY' => 2, 'YYYY' => 4, 'YY' => 2];

    /** Returns a user-facing error message, or null when the pattern is valid. */
    public static function error(mixed $pattern): ?string
    {
        if (! is_string($pattern) || trim($pattern) === '') {
            return 'Enter a numbering pattern, for example EMP-{SEQ:5}.';
        }
        if (mb_strlen($pattern) > self::MAX_LENGTH) {
            return 'A numbering pattern can be at most '.self::MAX_LENGTH.' characters long.';
        }
        $sequence = 0;
        $width = 0;
        $estimate = 0;
        $remainder = preg_replace_callback('/\{([^{}]*)\}/', function ($m) use (&$sequence, &$width, &$estimate, &$unknown) {
            $token = $m[1];
            if ($token === 'SEQ') {
                $sequence++;
                $width = 9;
                $estimate += 9;

                return '';
            }
            if (preg_match('/^SEQ:(\d{1,2})$/', $token, $n)) {
                $sequence++;
                $digits = (int) $n[1];
                if ($digits < 1 || $digits > 10) {
                    $unknown[] = '{'.$token.'} (use 1 to 10 digits)';
                }
                $width = $digits;
                $estimate += max($digits, 1);

                return '';
            }
            if (isset(self::SIMPLE[$token])) {
                $estimate += self::SIMPLE[$token];

                return '';
            }
            $unknown[] = '{'.$token.'}';

            return '';
        }, $pattern);

        if (! empty($unknown)) {
            return 'Unknown or invalid token '.implode(', ', array_unique($unknown)).'. Allowed tokens: {SEQ:n}, {SEQ}, {LE}, {LOC}, {TYPE}, {COUNTRY}, {YYYY}, {YY}.';
        }
        if (str_contains($remainder, '{') || str_contains($remainder, '}')) {
            return 'The pattern has an unmatched brace. Tokens must look like {SEQ:5}.';
        }
        if ($sequence !== 1) {
            return 'The pattern must contain exactly one {SEQ:n} (or {SEQ}) token so every number is unique.';
        }
        if (! preg_match('/^[A-Za-z0-9\-_\/.\s]*$/u', $remainder)) {
            return 'Prefixes and suffixes may only use letters, digits and - _ / . characters.';
        }
        if ($estimate + mb_strlen($remainder) > self::MAX_LENGTH) {
            return 'This pattern could produce employee numbers longer than '.self::MAX_LENGTH.' characters.';
        }

        return null;
    }

    /**
     * Renders a pattern for a sequence value. $codes may provide legal_entity, location and type codes plus country;
     * $now fixes the year tokens (default: current time).
     *
     * @param  array{legal_entity?:?string,location?:?string,type?:?string,country?:?string}  $codes
     */
    public static function render(string $pattern, int $value, array $codes = [], ?\DateTimeInterface $now = null): string
    {
        $now ??= now();
        $out = preg_replace_callback('/\{SEQ:(\d+)\}/', fn ($m) => str_pad((string) $value, (int) $m[1], '0', STR_PAD_LEFT), $pattern);
        $out = str_replace(['{SEQ}', '{LE}', '{LOC}', '{TYPE}', '{COUNTRY}', '{YYYY}', '{YY}'],
            [(string) $value, $codes['legal_entity'] ?? '', $codes['location'] ?? '', $codes['type'] ?? '', strtoupper($codes['country'] ?? ''), $now->format('Y'), $now->format('y')], $out);

        return mb_substr($out, 0, self::MAX_LENGTH);
    }

    /** Looks up the organization codes for a numbering context (read-only, tenant-scoped). */
    public static function codesFor(array $context): array
    {
        $tenant = app(TenantContext::class)->id;
        $code = fn (string $table, $id) => $id ? (DB::table($table)->where('tenant_id', $tenant)->where('id', $id)->value('code') ?? '') : '';

        return ['legal_entity' => $code('legal_entities', $context['legal_entity_id'] ?? null), 'location' => $code('locations', $context['location_id'] ?? null),
            'type' => $code('employee_types', $context['employee_type_id'] ?? null), 'country' => $context['country'] ?? ''];
    }
}
