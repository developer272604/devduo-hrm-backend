<?php

namespace App\Http\Controllers\People\Operations;

use App\Domain\Audit\Audit;
use App\Domain\People\Boot\OperationsBoot;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\EmployeeExport;
use App\Domain\People\Operations\ExportColumns;
use App\Domain\People\Operations\ExportService;
use App\Http\Controllers\People\PeopleController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ExportController extends PeopleController
{
    public function __construct(private ExportService $exports) {}

    public function columns(Request $r)
    {
        return ['data' => ExportColumns::available($r->user()), 'defaults' => ExportColumns::DEFAULTS];
    }

    public function store(Request $r)
    {
        $data = $r->validate(['format' => 'required|in:csv,xlsx', 'filters' => 'nullable|array', 'selected_uuids' => 'nullable|array|max:5000', 'selected_uuids.*' => 'uuid', 'columns' => 'nullable|array|max:60', 'columns.*' => 'string']);
        $unknown = array_diff(array_keys($data['filters'] ?? []), array_merge(ExportService::FILTER_KEYS, ['sort', 'dir']));
        if ($unknown) {
            throw ValidationException::withMessages(['filters' => ['Unknown filter: '.implode(', ', $unknown).'.']]);
        }
        $ids = null;
        if (! empty($data['selected_uuids'])) {
            $uuids = array_values(array_unique($data['selected_uuids']));
            $q = Employee::query()->whereIn('uuid', $uuids);
            app(\App\Domain\Access\DataScope::class)->constrain($q, $r->user(), 'people.view', 'employees.id');
            $ids = $q->pluck('id')->all();
            abort_if(count($ids) !== count($uuids), 422, 'Some of the selected employees could not be found.');
        }
        $export = $this->exports->request($r->user(), ['format' => $data['format'], 'filters' => $data['filters'] ?? [], 'columns' => $data['columns'] ?? null, 'selected_ids' => $ids]);

        return response()->json($this->exports->present($export), 202);
    }

    public function index(Request $r)
    {
        $page = EmployeeExport::query()->where('user_id', $r->user()->id)->orderByDesc('id')->paginate($this->perPage($r));
        $page->setCollection($page->getCollection()->map(fn ($e) => $this->exports->present($e)));

        return $page;
    }

    public function show(Request $r, string $uuid)
    {
        return $this->exports->present($this->own($r, $uuid));
    }

    public function download(Request $r, string $uuid)
    {
        $export = $this->own($r, $uuid);
        abort_if($export->status === 'expired' || ($export->expires_at && $export->expires_at->isPast()), 410, 'This export has expired. Create a new one.');
        abort_unless($export->status === 'completed' && $export->path, 409, 'This export is not ready yet.');
        $disk = Storage::disk(OperationsBoot::EXPORTS);
        abort_unless($disk->exists($export->path), 410, 'This export file is no longer available. Create a new one.');
        Audit::record('export_downloaded', 'people', 'employee-export:'.$export->uuid, ['sensitive' => $export->includes_sensitive, 'rows' => $export->row_count]);

        return $disk->download($export->path, 'employees-'.now()->format('Ymd-His').'.'.$export->format, ['Cache-Control' => 'no-store']);
    }

    private function own(Request $r, string $uuid): EmployeeExport
    {
        return EmployeeExport::query()->where('uuid', $uuid)->where('user_id', $r->user()->id)->firstOrFail();
    }
}
