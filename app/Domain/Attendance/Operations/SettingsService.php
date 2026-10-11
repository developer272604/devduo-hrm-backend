<?php

namespace App\Domain\Attendance\Operations;

use App\Domain\Attendance\Support\AttendanceSettings;
use App\Domain\Attendance\Support\AttendanceStatus;
use App\Domain\Audit\Audit;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Attendance settings sections (tenants.settings.attendance). Strict validation (unknown keys are rejected), merge-preserving
 * updates (omitted keys keep their value), audited with before/after.
 *
 * Wire format: object sections are the object itself; "methods" is {enabled: [...]}; "status_labels" is {labels: {code: label}}
 * (labels only; internal status codes never change).
 */
final class SettingsService
{
    public const SECTIONS = ['methods', 'location', 'status_labels', 'regularization', 'cutoffs', 'notifications', 'punch', 'processing', 'compliance', 'swap'];

    public const METHODS = ['web', 'mobile', 'biometric', 'import', 'api', 'admin', 'kiosk'];

    public const SWAP_DEFAULTS = ['enabled' => true, 'requires_approval' => true, 'min_notice_hours' => 24, 'max_per_month' => null];

    public function get(string $section): array
    {
        $this->assertSection($section);

        return ['section' => $section, 'settings' => $this->current($section), 'editable' => true];
    }

    /** @return array<string,array<string,mixed>> */
    public function all(): array
    {
        $out = [];
        foreach (self::SECTIONS as $section) {
            $out[$section] = $this->current($section);
        }

        return $out;
    }

    public function update(string $section, array $input): array
    {
        $this->assertSection($section);
        if ($input === []) {
            throw ValidationException::withMessages(['settings' => 'Send at least one setting to change.']);
        }
        $before = $this->current($section);
        $clean = $this->validate($section, $input);
        $after = $this->merge($before, $clean);

        DB::transaction(function () use ($section, $after) {
            $tenant = Tenant::whereKey(Ops::tenantId())->firstOrFail();
            $stored = $tenant->settings['attendance'] ?? [];
            $stored[$section] = match ($section) {
                'methods' => array_values($after['enabled']),
                'status_labels' => $after['labels'],
                default => $after,
            };
            AttendanceSettings::save($stored);
        });
        Audit::record('updated', 'attendance', 'settings:'.$section, ['before' => $before, 'after' => $after]);

        return ['section' => $section, 'settings' => $after, 'editable' => true];
    }

    /** @return array<string,mixed> */
    public function current(string $section): array
    {
        return match ($section) {
            'methods' => ['enabled' => array_values(AttendanceSettings::get('methods', []))],
            'status_labels' => ['labels' => AttendanceSettings::statusLabels()],
            'swap' => array_replace(self::SWAP_DEFAULTS, (array) AttendanceSettings::get('swap', [])),
            default => (array) AttendanceSettings::get($section, []),
        };
    }

    private function assertSection(string $section): void
    {
        abort_unless(in_array($section, self::SECTIONS, true), 404, 'That settings section does not exist.');
    }

    private function merge(array $before, array $clean): array
    {
        foreach ($clean as $key => $value) {
            $before[$key] = $value;
        }

        return $before;
    }

    /** @return array<string,mixed> only the validated keys */
    private function validate(string $section, array $input): array
    {
        $rules = $this->rules($section);
        $unknown = array_diff(array_keys($input), array_keys($rules));
        if ($unknown) {
            throw ValidationException::withMessages(['settings' => 'Unknown setting: '.implode(', ', $unknown).'. Allowed: '.implode(', ', array_keys($rules)).'.']);
        }
        $validator = Validator::make($input, array_map(fn ($rule) => array_merge(['sometimes'], $rule), $rules), $this->messages());
        $validator->after(function ($v) use ($section, $input) {
            if ($section === 'punch' && array_key_exists('server_time_only', $input) && $input['server_time_only'] !== true) {
                $v->errors()->add('server_time_only', 'Punch times always come from the server clock; this cannot be turned off.');
            }
            if ($section === 'status_labels' && isset($input['labels']) && is_array($input['labels'])) {
                foreach ($input['labels'] as $code => $label) {
                    if (! in_array($code, AttendanceStatus::ALL, true)) {
                        $v->errors()->add('labels.'.$code, '"'.$code.'" is not an attendance status. Statuses: '.implode(', ', AttendanceStatus::ALL).'.');
                    } elseif ($label !== null && (! is_string($label) || mb_strlen(trim($label)) > 40)) {
                        $v->errors()->add('labels.'.$code, 'A status label must be text of at most 40 characters (or null to restore the default).');
                    }
                }
            }
            if ($section === 'compliance' && isset($input['country_overrides']) && is_array($input['country_overrides'])) {
                foreach ($input['country_overrides'] as $country => $override) {
                    if (! preg_match('/^[A-Z]{2}$/', (string) $country) || ! is_array($override)) {
                        $v->errors()->add('country_overrides', 'Overrides are keyed by a two-letter country code and hold an object of rule values.');
                    }
                }
            }
        });
        $validator->validate();

        $clean = [];
        foreach (array_keys($rules) as $key) {
            if (array_key_exists($key, $input)) {
                $clean[$key] = $input[$key];
            }
        }
        if ($section === 'status_labels') {
            $labels = $this->current('status_labels')['labels'];
            foreach ($clean['labels'] ?? [] as $code => $label) {
                $label = is_string($label) ? trim($label) : null;
                if ($label === null || $label === '') {
                    unset($labels[$code]);
                } else {
                    $labels[$code] = $label;
                }
            }
            $clean = ['labels' => $labels];
        }
        if ($section === 'methods') {
            $clean['enabled'] = array_values(array_unique($clean['enabled']));
        }

        return $clean;
    }

    /** @return array<string,mixed> */
    private function rules(string $section): array
    {
        $int = fn (int $min, int $max, bool $nullable = false) => [$nullable ? 'nullable' : 'required', 'integer', 'between:'.$min.','.$max];

        return match ($section) {
            'methods' => ['enabled' => ['required', 'array', 'min:1'], 'enabled.*' => ['string', Rule::in(self::METHODS)]],
            'location' => ['mode' => ['required', Rule::in(['off', 'policy', 'required'])], 'retention_days' => $int(1, 3650), 'purpose' => ['required', 'string', 'min:5', 'max:500'], 'managers_see_precise' => ['required', 'boolean']],
            'status_labels' => ['labels' => ['required', 'array']],
            'regularization' => ['window_days' => $int(0, 365), 'max_per_month' => $int(1, 100, true)],
            'cutoffs' => ['payroll_cutoff_day' => $int(1, 31, true), 'regularization_days_after' => $int(0, 60), 'manager_approval_days' => $int(0, 30), 'lock_day' => $int(1, 31, true)],
            'notifications' => ['check_out_reminder_minutes' => $int(0, 720), 'missing_punch_after_hours' => $int(1, 72), 'shift_starting_soon_minutes' => $int(0, 720), 'roster_published' => ['required', 'boolean'],
                'quiet_hours' => ['nullable', 'array'], 'quiet_hours.start' => ['required_with:quiet_hours', 'date_format:H:i'], 'quiet_hours.end' => ['required_with:quiet_hours', 'date_format:H:i']],
            'punch' => ['duplicate_window_seconds' => $int(0, 3600), 'server_time_only' => ['required', 'boolean']],
            'processing' => ['nightly_recalculation_days' => $int(1, 31), 'async_threshold_days' => $int(1, 366)],
            'compliance' => ['enabled' => ['required', 'boolean'], 'country_overrides' => ['nullable', 'array']],
            'swap' => ['enabled' => ['required', 'boolean'], 'requires_approval' => ['required', 'boolean'], 'min_notice_hours' => $int(0, 720), 'max_per_month' => $int(1, 31, true)],
        };
    }

    /** @return array<string,string> */
    private function messages(): array
    {
        return [
            'between' => 'The :attribute must be between :min and :max.', 'boolean' => 'The :attribute must be true or false.', 'integer' => 'The :attribute must be a whole number.',
            'in' => 'The :attribute has an unsupported value.', 'required' => 'The :attribute is required.', 'date_format' => 'The :attribute must be a time such as 22:00.',
        ];
    }
}
