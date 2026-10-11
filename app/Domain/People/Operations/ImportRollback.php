<?php

namespace App\Domain\People\Operations;

use App\Domain\Audit\Audit;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\EmployeeImport;
use App\Domain\People\Models\Person;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Safe rollback: only employees created by the import that have had NO later activity are removed (soft-deleted).
 * If any employee is blocked, nothing is rolled back and the blockers are listed.
 * The employee number of a removed employee is released (suffixed) so the corrected file can be imported again.
 */
final class ImportRollback
{
    private const PERSON_TABLES = ['employee_contacts' => 'contact details', 'employee_addresses' => 'addresses', 'employee_emergency_contacts' => 'emergency contacts', 'employee_dependents' => 'dependents',
        'employee_education' => 'education', 'employee_experience' => 'experience', 'employee_certifications' => 'certifications', 'employee_skills' => 'skills', 'identity_documents' => 'identity records',
        'work_authorizations' => 'work authorization', 'employee_bank_accounts' => 'bank accounts', 'employee_documents' => 'documents'];

    private const EMPLOYEE_TABLES = ['employee_notes' => 'notes', 'employee_tags' => 'tags', 'employee_invitations' => 'an invitation', 'separation_records' => 'a separation', 'profile_change_requests' => 'profile changes',
        'employee_policy_references' => 'policy assignments', 'employee_group_members' => 'group membership', 'attendance_events' => 'attendance', 'attendance_days' => 'attendance', 'attendance_exceptions' => 'attendance',
        'attendance_adjustments' => 'attendance', 'attendance_regularizations' => 'attendance', 'roster_entries' => 'roster entries', 'overtime_requests' => 'overtime', 'on_duty_requests' => 'on-duty requests', 'employee_device_mappings' => 'device mappings'];

    private ?string $finishedAt = null;

    public function run(EmployeeImport $import): array
    {
        $this->finishedAt = $import->finished_at?->format('Y-m-d H:i:s');
        abort_unless(in_array($import->status, ['completed', 'partial'], true) && $import->imported_rows > 0, 409, 'Only imports that created employees can be rolled back.');
        $ids = DB::table('employee_import_rows')->where('tenant_id', $import->tenant_id)->where('import_id', $import->id)->where('status', 'imported')->pluck('employee_id')->all();
        $employees = Employee::query()->with('person')->whereIn('id', $ids)->get();
        $blocked = [];
        foreach ($employees as $e) {
            if ($reasons = $this->blockers($e, $ids)) {
                $blocked[] = ['employee_uuid' => $e->uuid, 'employee_number' => $e->employee_number, 'name' => $e->person?->display_name, 'reasons' => $reasons];
            }
        }
        if ($blocked) {
            $names = collect($blocked)->take(5)->map(fn ($b) => $b['name'].' ('.$b['employee_number'].')')->implode(', ');
            abort(response()->json(['message' => count($blocked).' employee(s) have had activity since the import, so it cannot be rolled back: '.$names.(count($blocked) > 5 ? ' and more' : '').'.', 'code' => 'rollback_blocked', 'blocked' => $blocked], 409));
        }
        DB::transaction(function () use ($employees, $import) {
            foreach ($employees as $e) {
                $original = $e->employee_number;
                $e->employee_number = mb_substr($original, 0, 40).'#rb'.$import->id;
                $e->save();
                $e->delete();
                Person::query()->whereKey($e->person_id)->first()?->delete();
                DB::table('custom_field_values')->where('tenant_id', $e->tenant_id)->where('entity_type', 'employee')->where('entity_id', $e->id)->delete();
            }
            $import->update(['status' => 'rolled_back', 'rolled_back_at' => now()]);
            DB::table('employee_import_rows')->where('import_id', $import->id)->where('status', 'imported')->update(['status' => 'skipped']);
        });
        Audit::record('import_rolled_back', 'people', 'employee-import:'.$import->uuid, ['employees' => $employees->count(), 'numbers' => $employees->pluck('employee_number')->all()]);

        return ['rolled_back' => $employees->count()];
    }

    /** @param array<int,int> $importIds employee ids created by the same import */
    private function blockers(Employee $e, array $importIds): array
    {
        $reasons = [];
        if (DB::table('employee_status_history')->where('employee_id', $e->id)->count() > 1) {
            $reasons[] = 'its status changed';
        }
        if (DB::table('employee_assignments')->where('employee_id', $e->id)->count() > 1) {
            $reasons[] = 'its job information changed';
        }
        $mine = DB::table('employee_reporting_relationships')->where('employee_id', $e->id)->count();
        if ($mine > 1) {
            $reasons[] = 'its reporting line changed';
        }
        if (DB::table('employee_reporting_relationships')->where('manager_id', $e->id)->whereNotIn('employee_id', $importIds)->exists()) {
            $reasons[] = 'people outside this import report to it';
        }
        if ($this->finishedAt && DB::table('domain_events')->where('employee_id', $e->id)->where('occurred_at', '>', $this->finishedAt)->exists()) {
            $reasons[] = 'it has later timeline activity';
        }
        if ($e->person?->user_id) {
            $reasons[] = 'a login is linked to it';
        }
        foreach (self::PERSON_TABLES as $table => $label) {
            if (Schema::hasTable($table) && DB::table($table)->where('person_id', $e->person_id)->exists()) {
                $reasons[] = 'it has '.$label;
            }
        }
        foreach (self::EMPLOYEE_TABLES as $table => $label) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'employee_id') && DB::table($table)->where('employee_id', $e->id)->exists()) {
                $reasons[] = 'it has '.$label;
            }
        }

        return array_values(array_unique($reasons));
    }
}
