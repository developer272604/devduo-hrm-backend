<?php

namespace App\Domain\People\Operations;

use App\Domain\Audit\Audit;
use App\Domain\People\Boot\OperationsBoot;
use App\Domain\People\Models\EmployeeExport;
use App\Domain\People\Services\EmployeeDirectory;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\People\ProcessEmployeeExport;
use App\Models\User;
use App\Support\Spreadsheet\TabularWriter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class ExportService
{
    /** Directory filter keys an export (and a saved view) may carry. */
    public const FILTER_KEYS = ['q', 'status', 'worker_type', 'department_id', 'designation_id', 'location_id', 'business_unit_id', 'team_id', 'legal_entity_id', 'employee_type_id', 'manager', 'skill_id', 'tag_id', 'joined_from', 'joined_to'];

    public function __construct(private EmployeeDirectory $directory) {}

    /** Returns a friendly problem for the requested columns, or null. */
    public function columnProblem(User $user, ?array $columns): ?string
    {
        $problems = [];
        foreach ($columns ?? [] as $key) {
            if ($reason = ExportColumns::denial($user, (string) $key)) {
                $problems[] = $reason;
            }
        }

        return $problems ? implode(' ', array_unique($problems)) : null;
    }

    /**
     * @param  array{format:string,filters?:?array,columns?:?array,selected_ids?:?array}  $data
     */
    public function request(User $user, array $data): EmployeeExport
    {
        $columns = array_values(array_unique($data['columns'] ?? []));
        if ($columns === []) {
            $columns = array_column(array_filter(ExportColumns::available($user), fn ($c) => $c['default']), 'key');
        }
        if ($problem = $this->columnProblem($user, $columns)) {
            throw ValidationException::withMessages(['columns' => [$problem]]);
        }
        $sensitive = (bool) array_filter($columns, fn ($c) => ExportColumns::isSensitive($c));
        $export = EmployeeExport::create([
            'user_id' => $user->id, 'format' => $data['format'], 'filters' => array_intersect_key($data['filters'] ?? [], array_flip(self::FILTER_KEYS + ['sort', 'dir'])),
            'columns' => $columns, 'selected_ids' => $data['selected_ids'] ?? null, 'includes_sensitive' => $sensitive, 'status' => 'queued',
        ]);
        Audit::record('export_requested', 'people', 'employee-export:'.$export->uuid, ['format' => $export->format, 'sensitive' => $sensitive,
            'selected' => $export->selected_ids ? count($export->selected_ids) : null, 'filters' => array_keys($export->filters ?? []), 'columns' => $sensitive ? $columns : count($columns)]);
        try {
            ProcessEmployeeExport::dispatch(app(TenantContext::class)->id, $export->id, $user->id);
        } catch (\Throwable $e) {
            report($e);
        }

        return $export->refresh();
    }

    /** Job body: builds the file in chunks and stores it privately. */
    public function run(EmployeeExport $export, User $user): void
    {
        Auth::setUser($user);
        $export->update(['status' => 'processing']);
        if ($problem = $this->columnProblem($user, $export->columns) ?? ($user->allows('people.export') ? null : 'You no longer have permission to export.')) {
            $export->update(['status' => 'failed', 'error' => $problem]);

            return;
        }
        $columns = $export->columns;
        $query = $this->directory->query($user, $export->filters ?? [], 'manage');
        $joins = [];
        foreach ($columns as $key) {
            if ($alias = ExportColumns::COLUMNS[$key][3] ?? null) {
                $joins[$alias] = true;
            }
        }
        foreach (array_keys($joins) as $alias) {
            [$table, $column] = ExportColumns::JOINS[$alias];
            $query->leftJoin($table.' as '.$alias, $alias.'.id', '=', 'a.'.$column);
        }
        if ($export->selected_ids !== null) {
            $query->whereIn('e.id', $export->selected_ids);
        }
        $select = ['e.id as id'];
        foreach ($columns as $i => $key) {
            $select[] = DB::raw(ExportColumns::COLUMNS[$key][2].' as c'.$i);
        }
        $headers = array_map(fn ($k) => ExportColumns::COLUMNS[$k][0], $columns);
        $tmp = tempnam(sys_get_temp_dir(), 'exp');
        $writer = TabularWriter::open($export->format, $tmp, $headers, ['sheet_title' => 'Employees']);
        $query->select($select)->chunkById((int) config('people_operations.export.chunk', 1000), function ($rows) use ($writer, $columns) {
            foreach ($rows as $row) {
                $writer->write(array_map(fn ($i) => $this->format($row->{'c'.$i}), array_keys($columns)));
            }
        }, 'e.id', 'id');
        $writer->close();

        $path = $export->tenant_id.'/'.$export->uuid.'.'.$export->format;
        $stream = fopen($tmp, 'rb');
        Storage::disk(OperationsBoot::EXPORTS)->put($path, $stream);
        fclose($stream);
        @unlink($tmp);
        $export->update(['status' => 'completed', 'path' => $path, 'row_count' => $writer->count(), 'expires_at' => now()->addHours((int) config('people_operations.export.ttl_hours', 24))]);
        Audit::record('export_completed', 'people', 'employee-export:'.$export->uuid, ['rows' => $writer->count(), 'sensitive' => $export->includes_sensitive,
            'columns' => $export->includes_sensitive ? $columns : count($columns)]);
    }

    private function format(mixed $value): mixed
    {
        return is_numeric($value) && ! is_string($value) ? $value : (is_string($value) ? $value : $value);
    }

    public function present(EmployeeExport $e): array
    {
        return ['uuid' => $e->uuid, 'format' => $e->format, 'status' => $e->status, 'row_count' => $e->row_count, 'columns' => $e->columns, 'includes_sensitive' => $e->includes_sensitive,
            'expires_at' => $e->expires_at?->toIso8601String(), 'error' => $e->error, 'created_at' => $e->created_at?->toIso8601String(),
            'download_url' => $e->status === 'completed' ? '/api/v1/people/exports/'.$e->uuid.'/download' : null];
    }
}
