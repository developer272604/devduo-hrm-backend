<?php

namespace App\Domain\Quality\Rules;

use App\Domain\Access\Classification;
use App\Domain\Quality\Finding;
use Illuminate\Support\Facades\DB;

/**
 * Likely duplicate people across the whole tenant, found with the same signals and confidences as DuplicateDetector
 * (work email, personal email, personal phone, name + date of birth, government identifier blind index) but computed
 * in bulk: each signal is one grouped query that returns only the keys shared by more than one person, and pairs are
 * generated inside those small buckets. No per-employee lookups and no N x N comparison.
 *
 * One finding per unordered PAIR OF PEOPLE (fingerprint "p:<uuidA>:<uuidB>", uuids sorted), so a rehire (same person,
 * two employments) is never a duplicate, and "not a duplicate" (dismiss) stays attached to the pair.
 * Nothing is ever merged automatically. Buckets larger than MAX_BUCKET (shared mailboxes, placeholder values) are skipped.
 */
final class DuplicateEmployeesRule extends PeopleRule
{
    private const MAX_BUCKET = 8;

    public function key(): string
    {
        return 'duplicate_employee';
    }

    public function category(): string
    {
        return 'duplicates';
    }

    public function label(): string
    {
        return 'Duplicate employees';
    }

    public function scan(int $tenantId): iterable
    {
        /** @var array<string,array{a:int,b:int,reasons:array<string,string>}> $pairs */
        $pairs = [];
        $add = function (array $personIds, string $reason, string $confidence) use (&$pairs) {
            $ids = array_values(array_unique(array_map('intval', $personIds)));
            sort($ids);
            if (count($ids) < 2 || count($ids) > self::MAX_BUCKET) {
                return;
            }
            for ($i = 0; $i < count($ids); $i++) {
                for ($j = $i + 1; $j < count($ids); $j++) {
                    $key = $ids[$i].':'.$ids[$j];
                    $pairs[$key] ??= ['a' => $ids[$i], 'b' => $ids[$j], 'reasons' => []];
                    $pairs[$key]['reasons'][$reason] = $confidence;
                }
            }
        };

        // Work email (employees) and personal email / name + DOB (people): shared-key buckets via GROUP BY.
        $this->bucket(DB::table('employees')->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereNotNull('work_email')->where('work_email', '!=', ''), 'LOWER(work_email)', 'person_id', 'Same work email', 'high', $add);
        $this->bucket(DB::table('people')->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereNull('anonymized_at')->whereNotNull('personal_email')->where('personal_email', '!=', ''), 'LOWER(personal_email)', 'id', 'Same personal email', 'high', $add);
        $this->nameAndBirth($tenantId, $add);
        $this->bucket(DB::table('identity_documents')->where('tenant_id', $tenantId), 'number_hash', 'person_id', 'Same government identifier', 'high', $add);

        // Phones need normalisation (digits only), so bucket them in PHP over a two-column scan.
        $phones = [];
        foreach (DB::table('people')->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereNull('anonymized_at')->whereNotNull('personal_phone')->select(['id', 'personal_phone'])->cursor() as $row) {
            $digits = preg_replace('/\D+/', '', (string) $row->personal_phone);
            if (strlen($digits) >= 7) {
                $phones[$digits][] = $row->id;
            }
        }
        foreach ($phones as $ids) {
            if (count($ids) > 1) {
                $add($ids, 'Same personal phone', 'medium');
            }
        }
        if (! $pairs) {
            return;
        }

        $people = $this->people($tenantId, collect($pairs)->flatMap(fn ($p) => [$p['a'], $p['b']])->unique()->values()->all());
        ksort($pairs);
        foreach ($pairs as $pair) {
            $a = $people[$pair['a']] ?? null;
            $b = $people[$pair['b']] ?? null;
            if (! $a || ! $b || (! $a['current'] && ! $b['current'])) {
                continue; // both gone: nothing for HR to resolve
            }
            [$first, $second] = strcmp($a['uuid'], $b['uuid']) <= 0 ? [$a, $b] : [$b, $a];
            $newer = $pair['b']; // higher person id = the record created later
            $subject = $people[$newer];
            $reasons = collect($pair['reasons'])->map(fn ($confidence, $reason) => ['reason' => $reason, 'confidence' => $confidence])->values()->all();
            $high = in_array('high', $pair['reasons'], true);
            yield new Finding($subject['employee_id'], 'p:'.$first['uuid'].':'.$second['uuid'], 'Possible duplicate: '.$first['name'].' and '.$second['name'],
                implode('; ', array_keys($pair['reasons'])).'. Review both records; nothing is merged automatically.', $high ? 'action_required' : 'warning',
                ['person', $newer], ['person_uuids' => [$first['uuid'], $second['uuid']], 'employee_ids' => array_values(array_filter([$first['employee_id'], $second['employee_id']])),
                    'employees' => [$this->card($first), $this->card($second)], 'reasons' => $reasons], Classification::HR_CONFIDENTIAL);
        }
    }

    /** Same legal name (case-insensitive) and the same date of birth: group on both columns, then bucket the few matching people. */
    private function nameAndBirth(int $tenantId, callable $add): void
    {
        $base = fn () => DB::table('people')->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereNull('anonymized_at')->whereNotNull('date_of_birth');
        $births = $base()->selectRaw('date_of_birth as dob')->groupBy('date_of_birth', DB::raw('LOWER(legal_name)'))->havingRaw('COUNT(*) > 1')->pluck('dob')->unique()->all();
        foreach (array_chunk($births, 400) as $chunk) {
            $members = [];
            foreach ($base()->whereIn('date_of_birth', $chunk)->get(['id', 'legal_name', 'date_of_birth']) as $row) {
                $members[mb_strtolower(trim($row->legal_name)).'|'.substr((string) $row->date_of_birth, 0, 10)][] = $row->id;
            }
            foreach ($members as $ids) {
                $add($ids, 'Same name and date of birth', 'high');
            }
        }
    }

    /** Finds keys shared by several people and reports each bucket's people. */
    private function bucket($base, string $keyExpression, string $personColumn, string $reason, string $confidence, callable $add): void
    {
        $keys = (clone $base)->selectRaw("$keyExpression as k")->groupBy(DB::raw($keyExpression))->havingRaw("COUNT(DISTINCT $personColumn) > 1")->pluck('k')->all();
        foreach (array_chunk($keys, 400) as $chunk) {
            $members = [];
            $rows = (clone $base)->selectRaw("$keyExpression as k, $personColumn as person")->whereIn(DB::raw($keyExpression), $chunk)->get();
            foreach ($rows as $row) {
                $members[$row->k][] = $row->person;
            }
            foreach ($members as $ids) {
                $add($ids, $reason, $confidence);
            }
        }
    }

    /** @return array<int,array<string,mixed>> person id => card (uuid, name, latest/current employment). */
    private function people(int $tenantId, array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DB::table('people')->where('tenant_id', $tenantId)->whereIn('id', $chunk)->get(['id', 'uuid', 'display_name']) as $p) {
                $out[$p->id] = ['uuid' => $p->uuid, 'name' => $p->display_name, 'employee_id' => null, 'employee_uuid' => null, 'employee_number' => null, 'status' => null, 'current' => false];
            }
            foreach (DB::table('employees')->where('tenant_id', $tenantId)->whereIn('person_id', $chunk)->whereNull('deleted_at')->orderBy('employment_sequence')->get(['id', 'uuid', 'person_id', 'employee_number', 'status']) as $e) {
                $current = in_array($e->status, self::CURRENT, true);
                if (isset($out[$e->person_id]) && (! $out[$e->person_id]['current'] || $current)) { // prefer a current employment, else the latest
                    $out[$e->person_id] = ['employee_id' => (int) $e->id, 'employee_uuid' => $e->uuid, 'employee_number' => $e->employee_number, 'status' => $e->status, 'current' => $current] + $out[$e->person_id];
                }
            }
        }

        return $out;
    }

    private function card(array $p): array
    {
        return ['person_uuid' => $p['uuid'], 'employee_uuid' => $p['employee_uuid'], 'name' => $p['name'], 'employee_number' => $p['employee_number'], 'status' => $p['status']];
    }
}
