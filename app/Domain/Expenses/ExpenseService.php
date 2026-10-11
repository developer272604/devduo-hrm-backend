<?php
namespace App\Domain\Expenses;
use App\Domain\Expenses\Models\{ExpensePolicy, ExpenseClaim, ExpenseLine, ExpenseReceipt};
use App\Domain\Operations\{Money, RequestAccess};
use App\Domain\Tenancy\TenantClock;
use App\Domain\Audit\Audit;
use App\Models\User;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ExpenseService {
    public function __construct(public RequestAccess $access) {}
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
    public function create(User $user, array $data): ExpenseClaim {
        $employeeId = $this->access->ownEmployee($user, 'expenses');
        $p = ExpensePolicy::where('uuid', $data['policy_uuid'])->firstOrFail();
        $total = 0; $lines = [];
        foreach ($data['lines'] as $line) {
            if ($line['spent_on'] < $p->effective_from->toDateString() || ($p->effective_to && $line['spent_on'] > $p->effective_to->toDateString()) || $line['spent_on'] > TenantClock::today()) $this->invalid('Every expense date must be within the selected policy dates and not in the future.');
            $amount = Money::minor($line['amount'], $p->minor_units);
            if (! $amount) $this->invalid('Each expense line must have a positive amount.');
            $total += $amount; $lines[] = collect($line)->except('amount')->all() + ['amount_minor' => $amount];
        }
        if ($total > $p->max_amount_minor) $this->invalid('The claim exceeds this policy limit.');
        return DB::transaction(function () use ($user, $employeeId, $data, $p, $total, $lines) {
            $claim = ExpenseClaim::create(['employee_id' => $employeeId, 'created_by' => $user->id, 'policy_id' => $p->id,
                'policy_snapshot' => $this->policyView($p) + ['receipt_threshold_minor' => $p->receipt_threshold_minor, 'max_amount_minor' => $p->max_amount_minor],
                'title' => $data['title'], 'currency' => $p->currency, 'minor_units' => $p->minor_units, 'total_minor' => $total, 'status' => 'draft']);
            foreach ($lines as $line) ExpenseLine::create($line + ['claim_id' => $claim->id]);
            Audit::record('expense.created', 'expenses', 'claim:'.$claim->uuid);
            return $claim;
        });
    }
    public function present(ExpenseClaim $c, User $user, bool $detail = false): array {
        $own = $this->access->own($user, $c);
        $out = ['uuid' => $c->uuid, 'title' => $c->title, 'status' => $c->status, 'currency' => $c->currency,
            'total' => Money::format($c->total_minor, $c->minor_units), 'submitted_at' => $c->submitted_at?->toIso8601String(),
            'decision_reason' => $c->decision_reason, 'payment_reference' => $c->payment_reference, 'paid_at' => $c->paid_at?->toIso8601String(),
            'can_submit' => $own && $c->status === 'draft', 'can_review' => $c->status === 'submitted' && $this->access->canReview($user, $c, 'expenses'),
            'can_confirm_payment' => $c->status === 'approved' && ! $own && $user->allows('expenses.payments.confirm') && app(\App\Domain\Access\DataScope::class)->hasTenantWide($user, 'expenses.payments.confirm')];
        if ($detail) {
            $c->loadMissing('lines', 'receipts');
            $out['policy'] = $c->policy_snapshot;
            $out['lines'] = $c->lines->map(fn ($l) => ['spent_on' => $l->spent_on->toDateString(), 'merchant' => $l->merchant, 'description' => $l->description, 'amount' => Money::format($l->amount_minor, $c->minor_units)]);
            $out['receipts'] = $c->receipts->map(fn ($f) => ['uuid' => $f->uuid, 'filename' => $f->filename, 'size_bytes' => $f->size_bytes]);
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
    public function action(User $user, string $uuid, array $data): ExpenseClaim {
        return DB::transaction(function () use ($user, $uuid, $data) {
            $c = $this->visible($user)->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $action = $data['action'];
            if ($action === 'submit') {
                abort_unless($user->allows('expenses.self') && $this->access->own($user, $c), 403);
                if ($c->status !== 'draft') $this->invalid('Only draft claims can be submitted.');
                $receipts = $c->receipts()->get();
                if ($c->total_minor >= $c->policy_snapshot['receipt_threshold_minor'] && $receipts->isEmpty()) $this->invalid('Attach a receipt before submitting this claim.');
                foreach ($receipts as $receipt) {
                    DB::table('expense_receipt_uses')->insertOrIgnore(['tenant_id' => $c->tenant_id, 'claim_id' => $c->id, 'content_hash' => $receipt->content_hash]);
                    if (DB::table('expense_receipt_uses')->where('tenant_id', $c->tenant_id)->where('content_hash', $receipt->content_hash)->where('claim_id', '!=', $c->id)->exists()) $this->invalid('A receipt matches another submitted claim. Ask finance to review it before proceeding.');
                }
                $this->access->reviewTasks($c, 'expenses'); $c->status = 'submitted'; $c->submitted_at = now();
            } elseif (in_array($action, ['approve', 'reject'])) {
                abort_unless($this->access->canReview($user, $c, 'expenses'), 403);
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
