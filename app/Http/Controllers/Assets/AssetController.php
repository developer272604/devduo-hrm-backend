<?php
namespace App\Http\Controllers\Assets;
use App\Domain\Assets\AssetService;
use App\Domain\People\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AssetController {
    public function __construct(private AssetService $assets) {}
    public function index(Request $r) {
        $q = $this->assets->visible($r->user());
        if ($r->filled('status')) $q->where('status', $r->query('status'));
        if ($r->filled('search')) $q->where('identifier', 'like', '%'.substr($r->query('search'), 0, 80).'%');
        return $q->orderByDesc('id')->paginate(25)->through(fn ($a) => $this->assets->present($a, $r->user()));
    }
    public function employees(Request $r) {
        abort_unless($this->assets->manages($r->user()), 403);
        $q = Employee::with('person')->whereIn('status', [...Employee::WORKING, 'preboarding']);
        if ($r->filled('search')) $q->whereHas('person', fn ($p) => $p->where('legal_first_name', 'like', '%'.substr($r->query('search'), 0, 80).'%')->orWhere('legal_last_name', 'like', '%'.substr($r->query('search'), 0, 80).'%'));
        return $q->orderBy('id')->limit(100)->get()->map(fn ($e) => ['uuid' => $e->uuid, 'name' => trim($e->person?->legal_first_name.' '.$e->person?->legal_last_name)]);
    }
    public function show(Request $r, string $uuid) { return $this->assets->present($this->assets->visible($r->user())->where('uuid', $uuid)->firstOrFail(), $r->user(), true); }
    public function store(Request $r) {
        $d = $r->validate(['identifier' => ['required', 'string', 'max:80', Rule::unique('assets')->where('tenant_id', $r->user()->tenant_id)],
            'name' => 'required|string|max:150', 'category' => 'required|string|max:80', 'serial_number' => ['nullable', 'string', 'max:150', Rule::unique('assets')->where('tenant_id', $r->user()->tenant_id)],
            'model' => 'nullable|string|max:150', 'purchase_reference' => 'nullable|string|max:150', 'warranty_until' => 'nullable|date_format:Y-m-d', 'location' => 'nullable|string|max:150']);
        return response()->json($this->assets->present($this->assets->create($r->user(), $d), $r->user()), 201);
    }
    public function transition(Request $r, string $uuid) {
        $d = $r->validate(['action' => 'required|in:assign,transfer,return,acknowledge,repair,restore,lost,dispose', 'version' => 'required|integer|min:0',
            'employee_uuid' => 'required_if:action,assign,transfer|nullable|uuid', 'condition' => 'required_if:action,return|in:good,worn,damaged',
            'reason' => 'required_unless:action,acknowledge|string|max:1000']);
        return $this->assets->present($this->assets->transition($r->user(), $uuid, $d), $r->user(), true);
    }
}
