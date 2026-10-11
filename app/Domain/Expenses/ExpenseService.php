<?php
namespace App\Domain\Expenses;
use App\Domain\Expenses\Models\{ExpensePolicy, ExpenseClaim, ExpenseLine, ExpenseLineAllocation, ExpenseReceipt, ExpenseViolation};
use App\Domain\Operations\{Money, RequestAccess};
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\{TenantClock, TenantContext};
use App\Domain\Travel\Models\TravelRequest;
use App\Domain\Audit\Audit;
use App\Models\User;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ExpenseService {
    public function __construct(public RequestAccess $access, private FxService $fx, private ExpenseRuleService $rules, private ExpensePolicyEngine $engine) {}
    public function visible(User $user) { return $this->access->visible(ExpenseClaim::query(), $user, 'expenses'); }
    public function createPolicy(User $user, array $data): ExpensePolicy {
        $this->access->tenantManager($user, 'expenses.manage');
        $scale = Money::scale($data['currency']);
        $max = Money::minor($data['max_amount'], $scale, 'max_amount');
        $threshold = Money::minor($data['receipt_threshold'], $scale, 'receipt_threshold');
        if (! $max || $threshold > $max) $this->invalid('The policy limit must be positive and at least its receipt threshold.');
        return DB::transaction(function () use ($data, $user, $scale, $max, $threshold) {
            $p = ExpensePolicy::create(collect($data)->except(['max_amount', 'receipt_threshold'])->all() + ['minor_units' => $scale, 'max_amount_minor' => $max, 'receipt_threshold_minor' => $threshold, 'created_by' => $user->id]);
            Audit::record('expense.policy_created', 'expenses', 'policy:'.$p->uuid);
            return $p;
        });
    }
    public function policyView(ExpensePolicy $p): array {
        return ['uuid' => $p->uuid, 'name' => $p->name, 'category' => $p->category, 'currency' => $p->currency, 'minor_units' => $p->minor_units,
            'max_amount' => Money::format($p->max_amount_minor, $p->minor_units), 'receipt_threshold' => Money::format($p->receipt_threshold_minor, $p->minor_units),
            'effective_from' => $p->effective_from->toDateString(), 'effective_to' => $p->effective_to?->toDateString()];
    }
    /** Country, entity, grade, department and cost center of the employee today; stored on the claim so rules and reports are reproducible. */
    private function employeeContext(int $employeeId): array {
        $e = Employee::findOrFail($employeeId); $a = $e->assignmentOn();
        return ['country' => $e->country, 'legal_entity_id' => $a?->legal_entity_id, 'grade_id' => $a?->grade_id, 'department_id' => $a?->department_id, 'cost_center_id' => $a?->cost_center_id];
    }
    private function costCenter(int $id): int {
        if (! DB::table('cost_centers')->where('tenant_id', app(TenantContext::class)->id)->where('id', $id)->exists()) $this->invalid('Choose a cost center from this workspace.');
        return $id;
    }
    /** Validates and prices every line (amount, FX, mileage, per diem) and its cost-center allocation. Returns [rows, total]. */
    private function prepare(ExpensePolicy $p, array $ctx, array $lines, ?ExpenseClaim $claim): array {
        $rows = []; $total = 0;
        foreach ($lines as $line) {
            $date = $line['spent_on'];
            if ($date < $p->effective_from->toDateString() || ($p->effective_to && $date > $p->effective_to->toDateString()) || $date > TenantClock::today()) $this->invalid('Every expense date must be within the selected policy dates and not in the future.');
            $kind = $line['kind'] ?? 'amount';
            $attrs = ['spent_on' => $date, 'merchant' => $line['merchant'], 'description' => $line['description'], 'category' => $line['category'] ?? null, 'kind' => $kind];
            if ($kind === 'amount') {
                if (! isset($line['amount'])) $this->invalid('Enter an amount for each expense line.');
                $currency = $line['currency'] ?? $p->currency;
                if ($currency === $p->currency) $amount = Money::minor($line['amount'], $p->minor_units);
                else {
                    $scale = Money::scale($currency); $original = Money::minor($line['amount'], $scale);
                    $rate = $this->fx->rateFor($currency, $p->currency, $date, $claim?->id) ?? $this->invalid('No FX rate from '.$currency.' to '.$p->currency.' is available for that date.');
                    $amount = FxService::convert($original, $scale, $this->fx->rateValue($rate), $p->minor_units);
                    $attrs += ['original_currency' => $currency, 'original_amount_minor' => $original, 'fx_rate_id' => $rate->id, 'fx_rate_applied' => $this->fx->rateValue($rate)];
                }
            } else {
                $rate = $this->rules->rateFor($kind, $date, $p->currency, $ctx) ?? $this->invalid('No '.str_replace('_', ' ', $kind).' rate applies to you for that date.');
                $qty = $line['quantity'] ?? ''; if (! preg_match('/^\d{1,6}(?:\.\d{1,2})?$/', $qty)) $this->invalid('Enter a positive quantity with at most two decimals.');
                $rateValue = \App\Domain\Payroll\Support\Decimal::fromDb($rate->getRawOriginal('rate'), 6);
                $amount = ExpenseRuleService::amountFor($qty, $rateValue, $p->minor_units); $attrs += ['quantity' => $qty, 'rate_applied' => $rateValue];
            }
            if (! $amount) $this->invalid('Each expense line must have a positive amount.');
            if (! empty($line['receipt_uuid'])) {
                $receipt = $claim?->receipts()->where('uuid', $line['receipt_uuid'])->first() ?? $this->invalid('Choose a receipt attached to this claim.');
                $attrs['receipt_id'] = $receipt->id;
            }
            $allocations = [];
            if (! empty($line['allocations'])) {
                $sum = 0;
                foreach ($line['allocations'] as $a) { $m = Money::minor($a['amount'], $p->minor_units, 'allocations'); $sum += $m; $allocations[] = ['cost_center_id' => $this->costCenter((int) $a['cost_center_id']), 'amount_minor' => $m]; }
                if ($sum !== $amount) $this->invalid('Cost-center allocations must add up to the line amount ('.Money::format($amount, $p->minor_units).').');
            } elseif ($ctx['cost_center_id'] ?? null) $allocations[] = ['cost_center_id' => (int) $ctx['cost_center_id'], 'amount_minor' => $amount];
            $total += $amount; $rows[] = ['attrs' => $attrs + ['amount_minor' => $amount], 'allocations' => $allocations];
        }
        if ($total > $p->max_amount_minor) $this->invalid('The claim exceeds this policy limit.');
        return [$rows, $total];
    }
    private function store(ExpenseClaim $claim, array $rows): void {
        foreach ($rows as $row) {
            $line = ExpenseLine::create($row['attrs'] + ['claim_id' => $claim->id]);
            foreach ($row['allocations'] as $a) ExpenseLineAllocation::create($a + ['line_id' => $line->id]);
        }
    }
    public function create(User $user, array $data): ExpenseClaim {
        $employeeId = $this->access->ownEmployee($user, 'expenses');
        $p = ExpensePolicy::where('uuid', $data['policy_uuid'])->firstOrFail(); $ctx = $this->employeeContext($employeeId);
        $trip = null;
        if (! empty($data['travel_request_uuid'])) {
            $trip = TravelRequest::where('uuid', $data['travel_request_uuid'])->where('employee_id', $employeeId)->firstOrFail();
            if ($trip->status !== 'approved') $this->invalid('Expenses can only be linked to an approved trip.');
        }
        [$rows, $total] = $this->prepare($p, $ctx, $data['lines'], null);
        return DB::transaction(function () use ($user, $employeeId, $data, $p, $total, $rows, $ctx, $trip) {
            $claim = ExpenseClaim::create(['employee_id' => $employeeId, 'created_by' => $user->id, 'policy_id' => $p->id,
                'policy_snapshot' => $this->policyView($p) + ['receipt_threshold_minor' => $p->receipt_threshold_minor, 'max_amount_minor' => $p->max_amount_minor],
                'title' => $data['title'], 'currency' => $p->currency, 'minor_units' => $p->minor_units, 'total_minor' => $total, 'status' => 'draft', 'travel_request_id' => $trip?->id,
                'country' => $ctx['country'], 'legal_entity_id' => $ctx['legal_entity_id'], 'grade_id' => $ctx['grade_id'], 'department_id' => $ctx['department_id']]);
            $this->store($claim, $rows);
            Audit::record('expense.created', 'expenses', 'claim:'.$claim->uuid);
            return $claim;
        });
    }
    /** Edit a draft: replaces the title and lines (including per-line receipt links and cost-center allocations). */
    public function update(User $user, string $uuid, array $data): ExpenseClaim {
        return DB::transaction(function () use ($user, $uuid, $data) {
            $c = $this->visible($user)->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless($user->allows('expenses.self') && $this->access->own($user, $c), 403);
            if ($c->status !== 'draft') $this->invalid('Only draft claims can be edited. Withdraw a submitted claim first.');
            $p = ExpensePolicy::findOrFail($c->policy_id); $ctx = $this->employeeContext($c->employee_id);
            [$rows, $total] = $this->prepare($p, $ctx, $data['lines'], $c);
            $ids = $c->lines()->pluck('id'); ExpenseLineAllocation::whereIn('line_id', $ids)->delete(); $c->lines()->delete();
            $this->store($c, $rows);
            $c->title = $data['title'] ?? $c->title; $c->total_minor = $total; $c->save();
            Audit::record('expense.updated', 'expenses', 'claim:'.$c->uuid);
            return $c->refresh();
        });
    }
    private function violationViews(ExpenseClaim $c): array {
        $lines = $c->lines->values();
        $items = $c->status === 'draft' ? $this->engine->evaluate($c) : ExpenseViolation::where('claim_id', $c->id)->get()->map(fn ($v) => ['line_id' => $v->line_id, 'code' => $v->code, 'severity' => $v->severity, 'message' => $v->message])->all();
        return array_map(fn ($v) => ['code' => $v['code'], 'severity' => $v['severity'], 'message' => $v['message'], 'line' => $v['line_id'] ? $lines->search(fn ($l) => $l->id === $v['line_id']) + 1 : null], $items);
    }
    public function present(ExpenseClaim $c, User $user, bool $detail = false): array {
        $own = $this->access->own($user, $c); $scope = app(\App\Domain\Access\DataScope::class);
        $manager = $user->allows('expenses.manage') && $scope->hasTenantWide($user, 'expenses.manage');
        $out = ['uuid' => $c->uuid, 'title' => $c->title, 'status' => $c->status, 'currency' => $c->currency, 'escalated' => (bool) $c->escalated,
            'total' => Money::format($c->total_minor, $c->minor_units), 'submitted_at' => $c->submitted_at?->toIso8601String(),
            'decision_reason' => $c->decision_reason, 'payment_reference' => $c->payment_reference, 'paid_at' => $c->paid_at?->toIso8601String(),
            'can_submit' => $own && $c->status === 'draft', 'can_edit' => $own && $c->status === 'draft', 'can_withdraw' => $own && $c->status === 'submitted',
            'can_review' => $c->status === 'submitted' && $this->access->canReview($user, $c, 'expenses') && (! $c->escalated || $manager),
            'can_confirm_payment' => $c->status === 'approved' && ! $own && $user->allows('expenses.payments.confirm') && $scope->hasTenantWide($user, 'expenses.payments.confirm')];
        if ($detail) {
            $c->loadMissing('lines', 'receipts');
            $receiptUuid = $c->receipts->pluck('uuid', 'id'); $allocations = ExpenseLineAllocation::whereIn('line_id', $c->lines->pluck('id'))->get()->groupBy('line_id');
            $out['policy'] = $c->policy_snapshot; $out['travel_request_uuid'] = $c->travel_request_id ? TravelRequest::whereKey($c->travel_request_id)->value('uuid') : null;
            $out['lines'] = $c->lines->map(fn ($l) => ['spent_on' => $l->spent_on->toDateString(), 'merchant' => $l->merchant, 'description' => $l->description, 'amount' => Money::format($l->amount_minor, $c->minor_units),
                'category' => $l->category ?: ($c->policy_snapshot['category'] ?? null), 'kind' => $l->kind, 'quantity' => $l->quantity, 'rate_applied' => $l->rate_applied, 'receipt_uuid' => $receiptUuid[$l->receipt_id] ?? null,
                'original_currency' => $l->original_currency, 'original_amount' => $l->original_currency ? Money::format((int) $l->original_amount_minor, Money::scale($l->original_currency)) : null, 'fx_rate' => $l->fx_rate_applied,
                'allocations' => ($allocations[$l->id] ?? collect())->map(fn ($a) => ['cost_center_id' => $a->cost_center_id, 'amount' => Money::format($a->amount_minor, $c->minor_units)])->values()]);
            $out['receipts'] = $c->receipts->map(fn ($f) => ['uuid' => $f->uuid, 'filename' => $f->filename, 'size_bytes' => $f->size_bytes]);
            $out['violations'] = $this->violationViews($c);
            $out['readiness'] = ['receipt_required' => $c->total_minor >= $c->policy_snapshot['receipt_threshold_minor'], 'has_receipt' => $c->receipts->isNotEmpty(), 'within_limit' => $c->total_minor <= $c->policy_snapshot['max_amount_minor']];
        }
        return $out;
    }
    public function addReceipt(User $user, string $uuid, UploadedFile $file): ExpenseReceipt {
        $path = null;
        try {
            return DB::transaction(function () use ($user, $uuid, $file, &$path) {
                $c = $this->visible($user)->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
                abort_unless($this->access->own($user, $c) && $user->allows('expenses.self'), 403);
                if ($c->status !== 'draft' || $c->receipts()->count() >= 5) $this->invalid('Only draft claims with fewer than five receipts accept uploads.');
                $hash = hash_file('sha256', $file->getRealPath());
                if ($c->receipts()->where('content_hash', $hash)->exists()) $this->invalid('This receipt is already attached.');
                $id = (string) Str::uuid(); $path = 'tenant/'.$user->tenant_id.'/expenses/'.$c->uuid.'/'.$id.'.'.$file->extension();
                Storage::disk('operations_private')->put($path, file_get_contents($file->getRealPath()));
                $receipt = ExpenseReceipt::create(['uuid' => $id, 'claim_id' => $c->id, 'filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255), 'path' => $path,
                    'size_bytes' => $file->getSize(), 'mime_type' => $file->getMimeType(), 'content_hash' => $hash]);
                Audit::record('expense.receipt_added', 'expenses', 'claim:'.$c->uuid);
                return $receipt;
            });
        } catch (\Throwable $e) { if ($path) Storage::disk('operations_private')->delete($path); throw $e; }
    }
    /** Re-prices foreign-currency lines with the rate now in force (an independently approved override wins) before the claim is frozen. */
    private function reconvert(ExpenseClaim $c): void {
        $total = 0;
        foreach ($c->lines()->get() as $l) {
            if ($l->original_currency) {
                $rate = $this->fx->rateFor($l->original_currency, $c->currency, $l->spent_on->toDateString(), $c->id) ?? $this->invalid('No FX rate is available for a foreign-currency line.');
                $value = $this->fx->rateValue($rate);
                $new = FxService::convert((int) $l->original_amount_minor, Money::scale($l->original_currency), $value, $c->minor_units);
                if ($new !== $l->amount_minor || $rate->id !== $l->fx_rate_id) {
                    $old = $l->amount_minor; $allocs = ExpenseLineAllocation::where('line_id', $l->id)->orderBy('id')->get(); $left = $new;
                    foreach ($allocs as $i => $a) { $share = $i === $allocs->count() - 1 ? $left : intdiv($a->amount_minor * $new + intdiv($old, 2), $old); $a->amount_minor = $share; $a->save(); $left -= $share; }
                    $l->amount_minor = $new; $l->fx_rate_id = $rate->id; $l->fx_rate_applied = $value; $l->save();
                }
            }
            $total += $l->amount_minor;
        }
        if ($total > $c->policy_snapshot['max_amount_minor']) $this->invalid('The claim exceeds this policy limit after currency conversion.');
        $c->total_minor = $total; $c->save();
    }
    public function action(User $user, string $uuid, array $data): ExpenseClaim {
        return DB::transaction(function () use ($user, $uuid, $data) {
            $c = $this->visible($user)->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $action = $data['action'];
            if ($action === 'submit') {
                abort_unless($user->allows('expenses.self') && $this->access->own($user, $c), 403);
                if ($c->status !== 'draft') $this->invalid('Only draft claims can be submitted.');
                $this->reconvert($c);
                $receipts = $c->receipts()->get();
                if ($c->total_minor >= $c->policy_snapshot['receipt_threshold_minor'] && $receipts->isEmpty()) $this->invalid('Attach a receipt before submitting this claim.');
                $violations = $this->engine->evaluate($c->refresh());
                $blocks = array_values(array_filter($violations, fn ($v) => $v['severity'] === 'block'));
                if ($blocks) throw ValidationException::withMessages(['claim' => array_column($blocks, 'message')]);
                $this->engine->persist($c, $violations); $c->escalated = (bool) array_filter($violations, fn ($v) => $v['severity'] === 'escalate');
                foreach ($receipts as $receipt) {
                    DB::table('expense_receipt_uses')->insertOrIgnore(['tenant_id' => $c->tenant_id, 'claim_id' => $c->id, 'content_hash' => $receipt->content_hash]);
                    if (DB::table('expense_receipt_uses')->where('tenant_id', $c->tenant_id)->where('content_hash', $receipt->content_hash)->where('claim_id', '!=', $c->id)->exists()) $this->invalid('A receipt matches another submitted claim. Ask finance to review it before proceeding.');
                }
                $this->access->reviewTasks($c, 'expenses'); $c->status = 'submitted'; $c->submitted_at = now();
            } elseif ($action === 'withdraw') {
                abort_unless($user->allows('expenses.self') && $this->access->own($user, $c), 403);
                if ($c->status !== 'submitted') $this->invalid('Only submitted claims awaiting review can be withdrawn.');
                DB::table('expense_receipt_uses')->where('tenant_id', $c->tenant_id)->where('claim_id', $c->id)->delete();
                ExpenseViolation::where('claim_id', $c->id)->delete(); $this->access->completeTasks($c, 'expenses');
                $c->status = 'draft'; $c->escalated = false; $c->submitted_at = null; $c->withdrawn_at = now();
            } elseif (in_array($action, ['approve', 'reject'])) {
                abort_unless($this->access->canReview($user, $c, 'expenses'), 403);
                if ($c->escalated) $this->access->tenantManager($user, 'expenses.manage');
                if ($c->status !== 'submitted') $this->invalid('Only submitted claims can be reviewed.');
                $c->status = $action === 'approve' ? 'approved' : 'rejected'; $c->reviewed_by = $user->id; $c->reviewed_at = now(); $c->decision_reason = $data['reason'];
                $this->access->completeTasks($c, 'expenses');
            } elseif ($action === 'confirm_payment') {
                $this->access->tenantManager($user, 'expenses.payments.confirm'); abort_if($this->access->own($user, $c), 403);
                if ($c->status !== 'approved') $this->invalid('Only approved, unpaid claims can have payment confirmed.');
                if (ExpenseClaim::where('payment_reference', $data['payment_reference'])->exists()) $this->invalid('This payment reference has already been used.');
                $c->status = 'paid'; $c->payment_reference = $data['payment_reference']; $c->payment_confirmed_by = $user->id; $c->paid_at = now();
            } else $this->invalid('Unsupported expense action.');
            $c->save(); Audit::record('expense.'.$action, 'expenses', 'claim:'.$c->uuid);
            return $c->refresh();
        });
    }
    private function invalid(string $message): never { throw ValidationException::withMessages(['claim' => $message]); }
}
