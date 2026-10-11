<?php

namespace App\Http\Controllers\Leave\Policies;

use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Policies\RuleValidator;
use App\Domain\Leave\Types\TypeService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TypeController extends Controller
{
    public function __construct(private readonly TypeService $service) {}

    public static function present(LeaveType $t): array
    {
        return ['uuid' => $t->uuid, 'name' => $t->name, 'code' => $t->code, 'kind' => $t->kind, 'category' => $t->category, 'unit' => $t->unit, 'tracks_balance' => (bool) $t->tracks_balance,
            'is_statutory' => (bool) $t->is_statutory, 'is_sensitive' => (bool) $t->is_sensitive, 'color' => $t->color, 'sort_order' => (int) $t->sort_order, 'config' => $t->config,
            'description' => $t->description, 'active' => (bool) $t->active];
    }

    public function meta()
    {
        return ['data' => ['kinds' => TypeService::KINDS, 'categories' => TypeService::CATEGORIES, 'units' => TypeService::UNITS, 'defaults' => TypeService::DEFAULTS,
            'config_keys' => ['countries', 'legal_entities', 'employee_types', 'evidence', 'limits', 'half_day_allowed', 'notes', 'holiday_pool'],
            'type_rule_keys' => RuleValidator::TYPE_KEYS, 'policy_rule_keys' => RuleValidator::POLICY_KEYS, 'carry_forward_modes' => RuleValidator::CARRY_MODES,
            'consumption_orders' => RuleValidator::CONSUMPTION, 'pay_classes' => RuleValidator::PAY_CLASSES]];
    }

    public function index(Request $r)
    {
        $q = LeaveType::query()->orderBy('sort_order')->orderBy('name')
            ->when($r->boolean('active_only'), fn ($q) => $q->where('active', true))
            ->when($r->input('kind'), fn ($q, $k) => $q->where('kind', $k));

        return ['data' => $q->get()->map(fn ($t) => self::present($t))];
    }

    private function rules(bool $create): array
    {
        $sometimes = $create ? 'required' : 'sometimes';

        return ['name' => "$sometimes|string|max:255", 'code' => "$sometimes|string|max:40|regex:/^[A-Za-z0-9_-]+$/", 'kind' => 'sometimes|string', 'category' => 'sometimes|string', 'unit' => 'sometimes|string',
            'tracks_balance' => 'sometimes|boolean', 'is_statutory' => 'sometimes|boolean', 'is_sensitive' => 'sometimes|boolean', 'color' => 'nullable|string|max:9', 'sort_order' => 'sometimes|integer|min:0|max:65535',
            'config' => 'nullable|array', 'description' => 'nullable|string|max:2000', 'active' => 'sometimes|boolean'];
    }

    public function store(Request $r)
    {
        return response()->json(['data' => self::present($this->service->create($r->validate($this->rules(true))))], 201);
    }

    public function show(string $uuid)
    {
        return ['data' => self::present(LeaveType::byUuid($uuid))];
    }

    public function update(Request $r, string $uuid)
    {
        return ['data' => self::present($this->service->update(LeaveType::byUuid($uuid), $r->validate($this->rules(false))))];
    }

    public function destroy(string $uuid)
    {
        $this->service->delete(LeaveType::byUuid($uuid));

        return response()->noContent();
    }

    public function seedDefaults()
    {
        return ['data' => $this->service->seedDefaults()];
    }
}
