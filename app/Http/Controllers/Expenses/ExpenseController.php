<?php
namespace App\Http\Controllers\Expenses;
use App\Domain\Expenses\ExpenseService;
use App\Domain\Expenses\Models\ExpensePolicy;
use App\Domain\Audit\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class ExpenseController {
    public function __construct(private ExpenseService $expenses) {}
    public function policies() { return ExpensePolicy::orderByDesc('id')->limit(100)->get()->map(fn ($p) => $this->expenses->policyView($p)); }
    public function createPolicy(Request $r) {
        $d = $r->validate(['name' => 'required|string|max:150', 'category' => 'required|string|max:80', 'currency' => 'required|in:USD,EUR,GBP,INR,JPY,KWD',
            'max_amount' => 'required|string|max:20', 'receipt_threshold' => 'required|string|max:20', 'effective_from' => 'required|date_format:Y-m-d', 'effective_to' => 'nullable|date_format:Y-m-d|after_or_equal:effective_from']);
        return response()->json($this->expenses->policyView($this->expenses->createPolicy($r->user(), $d)), 201);
    }
    public function index(Request $r) { return $this->expenses->visible($r->user())->latest('id')->paginate(25)->through(fn ($c) => $this->expenses->present($c, $r->user())); }
    public function show(Request $r, string $uuid) { return $this->expenses->present($this->expenses->visible($r->user())->where('uuid', $uuid)->firstOrFail(), $r->user(), true); }
    private function lineRules(): array {
        return ['lines' => 'required|array|min:1|max:20', 'lines.*.spent_on' => 'required|date_format:Y-m-d', 'lines.*.merchant' => 'required|string|max:150', 'lines.*.description' => 'required|string|max:500',
            'lines.*.amount' => 'nullable|string|max:20', 'lines.*.kind' => 'nullable|in:amount,mileage,per_diem', 'lines.*.quantity' => 'nullable|string|max:12', 'lines.*.currency' => 'nullable|in:USD,EUR,GBP,INR,JPY,KWD',
            'lines.*.category' => 'nullable|string|max:80', 'lines.*.receipt_uuid' => 'nullable|uuid', 'lines.*.allocations' => 'nullable|array|max:10',
            'lines.*.allocations.*.cost_center_id' => 'required|integer', 'lines.*.allocations.*.amount' => 'required|string|max:20'];
    }
    public function create(Request $r) {
        $d = $r->validate(['title' => 'required|string|max:200', 'policy_uuid' => 'required|uuid', 'travel_request_uuid' => 'nullable|uuid'] + $this->lineRules());
        return response()->json($this->expenses->present($this->expenses->create($r->user(), $d), $r->user(), true), 201);
    }
    public function update(Request $r, string $uuid) {
        $d = $r->validate(['title' => 'nullable|string|max:200'] + $this->lineRules());
        return $this->expenses->present($this->expenses->update($r->user(), $uuid, $d), $r->user(), true);
    }
    public function action(Request $r, string $uuid) {
        $d = $r->validate(['action' => 'required|in:submit,withdraw,approve,reject,confirm_payment', 'reason' => 'required_if:action,approve,reject|string|max:1000', 'payment_reference' => 'required_if:action,confirm_payment|string|max:150']);
        return $this->expenses->present($this->expenses->action($r->user(), $uuid, $d), $r->user(), true);
    }
    public function upload(Request $r, string $uuid) {
        $d = $r->validate(['file' => 'required|file|mimes:pdf,png,jpg,jpeg|max:5120']);
        $f = $this->expenses->addReceipt($r->user(), $uuid, $d['file']);
        return response()->json(['uuid' => $f->uuid, 'filename' => $f->filename, 'size_bytes' => $f->size_bytes], 201);
    }
    public function receipt(Request $r, string $uuid, string $receipt) {
        $c = $this->expenses->visible($r->user())->where('uuid', $uuid)->firstOrFail();
        $f = $c->receipts()->where('uuid', $receipt)->firstOrFail();
        abort_unless(Storage::disk('operations_private')->exists($f->path), 404);
        Audit::record('expense.receipt_accessed', 'expenses', 'claim:'.$c->uuid);
        return Storage::disk('operations_private')->download($f->path, $f->filename, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
