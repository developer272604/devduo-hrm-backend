<?php

namespace App\Http\Controllers\People\Operations;

use App\Domain\People\Models\BulkRun;
use App\Domain\People\Operations\BulkActions;
use App\Domain\People\Operations\BulkService;
use App\Domain\People\Operations\DescribesBulkAction;
use App\Domain\People\Operations\ExportService;
use App\Http\Controllers\People\PeopleController;
use Illuminate\Http\Request;

class BulkController extends PeopleController
{
    public function __construct(private BulkService $bulk) {}

    public function actions(Request $r, BulkActions $actions)
    {
        return ['data' => collect($actions->forUser($r->user()))->map(fn ($a) => ['key' => $a->key(), 'permission' => $a->permission(),
            'label' => $a instanceof DescribesBulkAction ? $a->label() : ucfirst(str_replace('_', ' ', $a->key())), 'description' => $a instanceof DescribesBulkAction ? $a->description() : null])->values()];
    }

    public function preview(Request $r)
    {
        $data = $r->validate(['action' => 'required|string', 'params' => 'nullable|array', 'employee_uuids' => 'nullable|array|max:10000', 'employee_uuids.*' => 'uuid', 'filters' => 'nullable|array']);
        abort_if(empty($data['employee_uuids']) && ! array_key_exists('filters', $data), 422, 'Choose employees or a filter to act on.');
        $unknown = array_diff(array_keys($data['filters'] ?? []), ExportService::FILTER_KEYS);
        abort_if($unknown, 422, 'Unknown filter: '.implode(', ', $unknown).'.');

        return $this->bulk->preview($r->user(), $data['action'], $data['params'] ?? [], ! empty($data['employee_uuids']) ? $data['employee_uuids'] : null, $data['filters'] ?? null);
    }

    public function apply(Request $r)
    {
        $data = $r->validate(['confirmation_token' => 'required|string|max:2000']);
        $run = $this->bulk->apply($r->user(), $data['confirmation_token']);

        return response()->json($this->bulk->present($run), $run->status === 'queued' || $run->status === 'processing' ? 202 : 200);
    }

    public function runs(Request $r)
    {
        $page = BulkRun::query()->where('user_id', $r->user()->id)->where('status', '!=', 'previewed')->orderByDesc('id')->paginate($this->perPage($r));
        $page->setCollection($page->getCollection()->map(fn ($run) => $this->bulk->present($run)));

        return $page;
    }

    public function run(Request $r, string $uuid)
    {
        return $this->bulk->present(BulkRun::query()->where('uuid', $uuid)->where('user_id', $r->user()->id)->where('status', '!=', 'previewed')->firstOrFail());
    }
}
