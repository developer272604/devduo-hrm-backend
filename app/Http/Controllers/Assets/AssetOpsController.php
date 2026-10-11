<?php
namespace App\Http\Controllers\Assets;

use App\Domain\Assets\{AssetBulkService, AssetHistoryService};
use App\Domain\Tenancy\TenantClock;
use Illuminate\Http\Request;

final class AssetOpsController {
    public function __construct(private AssetBulkService $bulk, private AssetHistoryService $history) {}
    public function previewImport(Request $r) {
        $r->validate(['file' => 'required|file|mimes:csv,txt,xlsx|max:5120']);
        return response()->json($this->bulk->previewImport($r->user(), $r->file('file')), 201);
    }
    public function showPreview(Request $r, string $uuid) { return $this->bulk->show($r->user(), $uuid); }
    public function commitImport(Request $r, string $uuid) { return $this->bulk->commitImport($r->user(), $uuid, $r->boolean('skip_invalid')); }
    public function previewBulk(Request $r) {
        $d = $r->validate(['action' => 'required|in:assign,transfer,return', 'items' => 'required|array|min:1|max:200', 'items.*.identifier' => 'required|string|max:80', 'items.*.employee_uuid' => 'nullable|uuid',
            'employee_uuid' => 'nullable|uuid', 'condition' => 'required_if:action,return|nullable|in:good,worn,damaged', 'reason' => 'required|string|max:1000']);
        return response()->json($this->bulk->previewBulk($r->user(), $d), 201);
    }
    public function commitBulk(Request $r, string $uuid) { return $this->bulk->commitBulk($r->user(), $uuid); }
    public function history(Request $r, string $uuid) { return $this->history->history($r->user(), $uuid); }
    public function maintenance(Request $r, string $uuid) {
        $d = $r->validate(['kind' => 'required|in:service,repair,inspection,calibration', 'vendor' => 'nullable|string|max:150', 'cost' => 'nullable|string|max:20', 'currency' => 'required_with:cost|nullable|in:USD,EUR,GBP,INR,JPY,KWD',
            'started_on' => 'required|date_format:Y-m-d', 'completed_on' => 'nullable|date_format:Y-m-d', 'notes' => 'nullable|string|max:2000']);
        $this->history->addMaintenance($r->user(), $uuid, $d);
        return response()->json($this->history->history($r->user(), $uuid), 201);
    }
    public function procurement(Request $r, string $uuid) {
        $d = $r->validate(['kind' => 'required|in:purchase,warranty_extension,invoice', 'vendor' => 'nullable|string|max:150', 'reference' => 'nullable|string|max:150', 'purchased_on' => 'nullable|date_format:Y-m-d',
            'cost' => 'nullable|string|max:20', 'currency' => 'required_with:cost|nullable|in:USD,EUR,GBP,INR,JPY,KWD', 'salvage' => 'nullable|string|max:20', 'useful_life_months' => 'nullable|integer|min:1|max:600',
            'warranty_until' => 'nullable|date_format:Y-m-d', 'notes' => 'nullable|string|max:2000']);
        $this->history->addProcurement($r->user(), $uuid, $d);
        return response()->json($this->history->history($r->user(), $uuid), 201);
    }
    public function depreciation(Request $r) {
        $d = $r->validate(['as_of' => 'nullable|date_format:Y-m-d']);
        return $this->history->depreciation($r->user(), $d['as_of'] ?? TenantClock::today());
    }
}
