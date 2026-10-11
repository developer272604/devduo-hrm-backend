<?php

namespace App\Domain\Quality\Rules;

use App\Domain\Access\Classification;
use App\Domain\Quality\Finding;
use Illuminate\Support\Facades\DB;

/** Malformed emails and phone numbers (work, personal, contact records, emergency contacts). Values are never copied into findings. */
final class InvalidContactInfoRule extends PeopleRule
{
    public function key(): string
    {
        return 'invalid_contact_info';
    }

    public function category(): string
    {
        return 'contact_info';
    }

    public function label(): string
    {
        return 'Invalid contact information';
    }

    public static function validEmail(?string $v): bool
    {
        return $v === null || trim($v) === '' || filter_var(trim($v), FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function validPhone(?string $v): bool
    {
        if ($v === null || trim($v) === '') {
            return true;
        }
        $digits = preg_replace('/\D+/', '', $v);

        return preg_match('/^\+?[0-9 ()\-.]+$/', trim($v)) === 1 && strlen($digits) >= 6 && strlen($digits) <= 15;
    }

    public function scan(int $tenantId): iterable
    {
        $bad = [];
        $byPerson = [];
        $q = $this->employees($tenantId)->select(['e.id as id', 'e.person_id', 'e.work_email', 'e.work_phone', 'p.personal_email', 'p.personal_phone']);
        foreach ($this->stream($q) as $row) {
            $byPerson[$row->person_id][] = $row->id;
            foreach (['work_email' => 'Work email', 'personal_email' => 'Personal email'] as $c => $label) {
                if (! self::validEmail($row->{$c})) {
                    $bad[$row->id][$label] = true;
                }
            }
            foreach (['work_phone' => 'Work phone', 'personal_phone' => 'Personal phone'] as $c => $label) {
                if (! self::validPhone($row->{$c})) {
                    $bad[$row->id][$label] = true;
                }
            }
        }
        $sources = [
            ['employee_contacts', 'Contact record', fn ($r) => $r->kind === 'email' ? self::validEmail($r->value) : self::validPhone($r->value), ['kind', 'value']],
            ['employee_emergency_contacts', 'Emergency contact phone', fn ($r) => self::validPhone($r->phone), ['phone']],
        ];
        foreach ($sources as [$table, $label, $ok, $cols]) {
            foreach (array_chunk(array_keys($byPerson), 500) as $chunk) {
                foreach (DB::table($table)->where('tenant_id', $tenantId)->whereIn('person_id', $chunk)->select(['person_id', ...$cols])->cursor() as $r) {
                    if (! $ok($r)) {
                        foreach ($byPerson[$r->person_id] ?? [] as $employeeId) {
                            $bad[$employeeId][$label] = true;
                        }
                    }
                }
            }
        }
        ksort($bad);
        foreach ($bad as $id => $labels) {
            $names = array_keys($labels);
            yield new Finding((int) $id, 'emp:'.$id, 'Invalid contact information: '.$this->list($names), 'An email address or phone number is malformed. Ask the employee to correct it.', 'warning',
                ['employee', (int) $id], ['fields' => $names], Classification::EMPLOYEE);
        }
    }
}
