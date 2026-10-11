<?php

namespace App\Domain\Attendance\Scheduling;

use App\Domain\Attendance\Models\ComplianceRuleOverride;
use App\Domain\Attendance\Support\AttendanceSettings;
use App\Domain\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Working-time compliance with three separated layers:
 *   global engine  -> ConflictDetector (generic checks, no country knowledge)
 *   country config -> config/hrm_compliance.php packs (illustrative defaults, NOT legal advice)
 *   tenant policy  -> compliance_rule_overrides rows + settings.attendance.compliance {enabled, enforcement: flag|block|off}
 */
final class ComplianceRules
{
    public const KEYS = ['max_daily_minutes', 'max_weekly_minutes', 'min_rest_minutes', 'weekly_rest_minutes', 'night_window_start', 'night_window_end', 'max_consecutive_nights',
        'standard_weekly_minutes', 'max_weekly_overtime_minutes', 'break_after_minutes', 'break_minimum_minutes'];

    /** @var array<string,array> per-request memo */
    private array $memo = [];

    public function settings(): array
    {
        $c = AttendanceSettings::get('compliance', []);

        return ['enabled' => (bool) ($c['enabled'] ?? true), 'enforcement' => in_array($c['enforcement'] ?? 'flag', ['flag', 'block', 'off'], true) ? ($c['enforcement'] ?? 'flag') : 'flag'];
    }

    public function pack(string $country): array
    {
        return config('hrm_compliance.countries.'.strtoupper($country).'.rules', []);
    }

    /** @return array{rules:array,layers:array<string,string>} effective rules for a country with the layer each value came from */
    public function forCountry(?string $country): array
    {
        $country = strtoupper((string) $country);
        if (isset($this->memo[$country])) {
            return $this->memo[$country];
        }
        $rules = [];
        $layers = [];
        foreach ($this->pack($country) as $k => $v) {
            $rules[$k] = $v;
            $layers[$k] = 'country';
        }
        $override = $country ? ComplianceRuleOverride::query()->where('country', $country)->first() : null;
        if ($override && $override->enabled) {
            foreach ($override->rules as $k => $v) {
                $rules[$k] = $v;
                $layers[$k] = 'tenant';
            }
        } elseif ($override && ! $override->enabled) {
            $rules = [];
            $layers = [];
        }
        $rules['night_min_overlap_minutes'] = config('hrm_compliance.night_min_overlap_minutes', 180);

        return $this->memo[$country] = ['rules' => $rules, 'layers' => $layers];
    }

    public function show(): array
    {
        $overrides = ComplianceRuleOverride::query()->get()->keyBy('country');
        $countries = [];
        foreach (config('hrm_compliance.countries') as $code => $pack) {
            $countries[$code] = ['name' => $pack['name'], 'pack' => $pack['rules'], 'override' => $overrides[$code]->rules ?? null, 'enabled' => $overrides[$code]->enabled ?? true, 'effective' => $this->forCountry($code)['rules']];
        }
        foreach ($overrides as $code => $o) {
            $countries[$code] ??= ['name' => $code, 'pack' => [], 'override' => $o->rules, 'enabled' => (bool) $o->enabled, 'effective' => $this->forCountry($code)['rules']];
        }

        return ['disclaimer' => config('hrm_compliance.disclaimer'), 'settings' => $this->settings(), 'countries' => $countries];
    }

    /** @param array{enforcement?:string,enabled?:bool,countries?:array<string,array{enabled?:bool,rules?:?array}>} $in */
    public function save(array $in): array
    {
        $errors = [];
        if (isset($in['enforcement']) && ! in_array($in['enforcement'], ['flag', 'block', 'off'], true)) {
            $errors['enforcement'] = 'Enforcement must be flag (warn), block (refuse) or off.';
        }
        foreach ((array) ($in['countries'] ?? []) as $code => $cfg) {
            if (! preg_match('/^[A-Za-z]{2}$/', (string) $code)) {
                $errors["countries.$code"] = 'Use two-letter country codes.';

                continue;
            }
            foreach ((array) ($cfg['rules'] ?? []) as $k => $v) {
                if (! in_array($k, self::KEYS, true)) {
                    $errors["countries.$code.rules.$k"] = "Unknown compliance rule \"$k\".";
                } elseif ($v !== null && (str_contains($k, 'window') ? ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $v) : (! is_int($v) || $v < 0 || $v > 100000))) {
                    $errors["countries.$code.rules.$k"] = str_contains($k, 'window') ? 'Use a 24-hour time such as 22:00.' : 'Enter a whole number of minutes (or empty to disable the check).';
                }
            }
        }
        if ($errors) {
            Fail::fields($errors);
        }
        DB::transaction(function () use ($in) {
            $all = AttendanceSettings::all();
            $all['compliance'] = array_merge($all['compliance'] ?? [], array_intersect_key($in, array_flip(['enforcement', 'enabled'])));
            AttendanceSettings::save($all);
            foreach ((array) ($in['countries'] ?? []) as $code => $cfg) {
                $code = strtoupper($code);
                $row = ComplianceRuleOverride::query()->where('country', $code)->first();
                if (array_key_exists('rules', $cfg) && $cfg['rules'] === null && ! isset($cfg['enabled'])) {
                    $row?->delete();

                    continue;
                }
                $payload = ['rules' => $cfg['rules'] ?? ($row->rules ?? []), 'enabled' => (bool) ($cfg['enabled'] ?? ($row->enabled ?? true))];
                $row ? $row->update($payload) : ComplianceRuleOverride::create($payload + ['country' => $code]);
            }
            Audit::record('updated', 'attendance', 'compliance_settings', ['enforcement' => $in['enforcement'] ?? null, 'countries' => array_keys((array) ($in['countries'] ?? []))]);
        });
        $this->memo = [];

        return $this->show();
    }
}
