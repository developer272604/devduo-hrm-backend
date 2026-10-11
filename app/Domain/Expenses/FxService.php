<?php
namespace App\Domain\Expenses;

use App\Domain\Audit\Audit;
use App\Domain\Access\DataScope;
use App\Domain\Expenses\Models\{ExpenseClaim, ExpenseFxRate};
use App\Domain\Leave\Accrual\Actors;
use App\Domain\Operations\{Money, RequestAccess};
use App\Domain\Payroll\Support\Decimal;
use App\Domain\Work\TaskService;
use App\Models\User;
use Brick\Math\{BigDecimal, RoundingMode};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** FX rate table with a recorded source, plus maker-checker approval of claim-specific overrides. All maths is exact decimal. */
final class FxService {
    public const SOURCES = ['central_bank', 'bank_feed', 'provider', 'manual'];
    public function __construct(private RequestAccess $access, private DataScope $scope, private TaskService $tasks) {}

    public static function rateString(string $rate): string {
        if (! preg_match('/^\d{1,10}(?:\.\d{1,8})?$/', $rate) || BigDecimal::of($rate)->isZero()) throw ValidationException::withMessages(['rate' => 'Enter a positive rate with at most 8 decimal places.']);
        return BigDecimal::of($rate)->toScale(8)->__toString();
    }
    /** Converts integer minor units of one currency into minor units of another using a decimal rate (half-up). */
    public static function convert(int $minor, int $fromScale, string $rate, int $toScale): int {
        return (int) BigDecimal::of($minor)->dividedBy(BigDecimal::of(10)->power($fromScale), 12, RoundingMode::HALF_UP)->multipliedBy($rate)->multipliedBy(BigDecimal::of(10)->power($toScale))->toScale(0, RoundingMode::HALF_UP)->__toString();
    }
    public function rateFor(string $from, string $to, string $date, ?int $claimId): ?ExpenseFxRate {
        if ($claimId) {
            $override = ExpenseFxRate::where('base_currency', $from)->where('quote_currency', $to)->where('is_override', true)->where('claim_id', $claimId)->where('status', 'active')->orderByDesc('id')->first();
            if ($override) return $override;
        }
        return ExpenseFxRate::where('base_currency', $from)->where('quote_currency', $to)->where('is_override', false)->where('status', 'active')->where('effective_on', '<=', $date)->orderByDesc('effective_on')->orderByDesc('id')->first();
    }
    public function rateValue(ExpenseFxRate $r): string { return Decimal::fromDb($r->getRawOriginal('rate'), 8); }
    public function view(ExpenseFxRate $r): array {
        return ['uuid' => $r->uuid, 'base_currency' => $r->base_currency, 'quote_currency' => $r->quote_currency, 'rate' => $this->rateValue($r), 'effective_on' => $r->effective_on->toDateString(),
            'source' => $r->source, 'source_reference' => $r->source_reference, 'is_override' => $r->is_override, 'status' => $r->status, 'reason' => $r->reason, 'decision_reason' => $r->decision_reason,
            'claim_uuid' => $r->claim_id ? ExpenseClaim::whereKey($r->claim_id)->value('uuid') : null, 'requested_by' => $r->requested_by, 'decided_at' => $r->decided_at?->toIso8601String()];
    }
    public function list(User $user) {
        abort_unless($user->allows('expenses.fx.manage') || $user->allows('expenses.fx.approve') || $user->allows('expenses.manage'), 403);
        return ExpenseFxRate::orderByDesc('id')->limit(200)->get()->map(fn ($r) => $this->view($r) + ['can_decide' => $this->canDecide($user, $r)]);
    }
    public function canDecide(User $user, ExpenseFxRate $r): bool {
        if ($r->status !== 'pending' || ! $user->allows('expenses.fx.approve') || ! $this->scope->hasTenantWide($user, 'expenses.fx.approve') || $r->requested_by === $user->id) return false;
        $claim = $r->claim_id ? ExpenseClaim::find($r->claim_id) : null;
        return ! ($claim && $this->access->own($user, $claim));
    }
    public function create(User $user, array $d): ExpenseFxRate {
        $this->access->tenantManager($user, 'expenses.fx.manage');
        $this->pair($d);
        return DB::transaction(function () use ($user, $d) {
            $r = ExpenseFxRate::create(['base_currency' => $d['base_currency'], 'quote_currency' => $d['quote_currency'], 'rate' => self::rateString($d['rate']), 'effective_on' => $d['effective_on'],
                'source' => $d['source'], 'source_reference' => $d['source_reference'] ?? null, 'is_override' => false, 'status' => 'active', 'requested_by' => $user->id]);
            Audit::record('expense.fx_rate_created', 'expenses', 'fx:'.$r->uuid);
            return $r;
        });
    }
    public function requestOverride(User $user, array $d): ExpenseFxRate {
        $this->access->tenantManager($user, 'expenses.fx.manage');
        $this->pair($d);
        $claim = ExpenseClaim::where('uuid', $d['claim_uuid'])->whereIn('status', ['draft', 'submitted'])->firstOrFail();
        abort_if($this->access->own($user, $claim), 403);
        return DB::transaction(function () use ($user, $d, $claim) {
            $r = ExpenseFxRate::create(['base_currency' => $d['base_currency'], 'quote_currency' => $d['quote_currency'], 'rate' => self::rateString($d['rate']), 'effective_on' => $d['effective_on'],
                'source' => 'manual', 'source_reference' => $d['source_reference'] ?? null, 'is_override' => true, 'claim_id' => $claim->id, 'reason' => $d['reason'], 'status' => 'pending', 'requested_by' => $user->id]);
            foreach (Actors::usersWithPermission('expenses.fx.approve', [$user->id]) as $id) {
                $approver = User::find($id);
                if ($approver && $this->canDecide($approver, $r)) $this->tasks->createOnce(['assignee_id' => $id, 'title' => 'Approve an FX rate override', 'source_module' => 'expenses', 'source_entity' => 'fx:'.$r->uuid,
                    'action' => '/expenses/rules', 'priority' => 'normal', 'status' => 'open']);
            }
            Audit::record('expense.fx_override_requested', 'expenses', 'fx:'.$r->uuid);
            return $r;
        });
    }
    public function decide(User $user, string $uuid, string $decision, string $reason): ExpenseFxRate {
        return DB::transaction(function () use ($user, $uuid, $decision, $reason) {
            $r = ExpenseFxRate::where('uuid', $uuid)->where('is_override', true)->lockForUpdate()->firstOrFail();
            abort_unless($user->allows('expenses.fx.approve'), 403);
            abort_unless($this->canDecide($user, $r) || $r->status !== 'pending', 403);
            if ($r->status !== 'pending') throw ValidationException::withMessages(['rate' => 'Only pending overrides can be decided.']);
            $r->status = $decision === 'approve' ? 'active' : 'rejected'; $r->approved_by = $user->id; $r->decided_at = now(); $r->decision_reason = $reason; $r->save();
            $this->tasks->completeBySource('expenses', 'fx:'.$r->uuid);
            Audit::record('expense.fx_override_'.$decision, 'expenses', 'fx:'.$r->uuid);
            return $r;
        });
    }
    private function pair(array $d): void {
        Money::scale($d['base_currency']); Money::scale($d['quote_currency']);
        if ($d['base_currency'] === $d['quote_currency']) throw ValidationException::withMessages(['quote_currency' => 'Choose two different currencies.']);
    }
}
