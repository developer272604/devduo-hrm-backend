<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Contracts\LeavePolicyResolver;
use App\Domain\Leave\Ledger\BalanceService;
use App\Domain\Leave\Ledger\LedgerPosting;
use App\Domain\Leave\Ledger\LedgerService;
use App\Domain\Leave\Models\LeaveAccount;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\People\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PeopleFixtures;

/** Shared setup for workstream C tests. Policy is a fake binding (workstream A owns the real resolver); balances are seeded through LedgerService. */
trait RequestFixtures
{
    use PeopleFixtures;

    protected Employee $hr;

    protected Employee $boss;

    protected Employee $emp;

    protected Employee $peer;

    protected LeaveType $annual;

    /** @var array<string,mixed> rules the fake policy returns for every type */
    protected array $policyRules = [];

    protected function bootLeave(array $rules = []): void
    {
        $this->bootTenant();
        $this->hr = $this->hire('Hera HR', ['role' => 'HR administrator', 'email' => 'hr@alpha.test']);
        $this->boss = $this->hire('Maya Boss', ['role' => 'Manager', 'email' => 'maya@alpha.test']);
        $this->emp = $this->hire('Asha Rao', ['role' => 'Team member', 'manager' => $this->boss, 'email' => 'asha@alpha.test']);
        $this->peer = $this->hire('Piya Peer', ['role' => 'Team member', 'manager' => $this->boss, 'email' => 'piya@alpha.test']);
        $this->annual = LeaveType::create(['name' => 'Annual leave', 'code' => 'AL', 'kind' => 'annual', 'unit' => 'half_day']);
        $this->policy($rules);
    }

    protected function policy(array $rules): void
    {
        $this->policyRules = $rules;
        $self = $this;
        $this->app->instance(LeavePolicyResolver::class, new class($self) implements LeavePolicyResolver
        {
            public function __construct(private $test) {}

            public function resolve(int $employeeId, string $date): ?array
            {
                return ['version' => (object) ['rules' => (new \ReflectionProperty($this->test, 'policyRules'))->getValue($this->test)]];
            }

            public function rulesFor(int $employeeId, int $leaveTypeId, string $date): ?array
            {
                return ['version_id' => null, 'policy_id' => 0, 'type_id' => $leaveTypeId, 'rules' => (new \ReflectionProperty($this->test, 'policyRules'))->getValue($this->test), 'reason' => 'Test policy'];
            }
        });
    }

    /** Every HTTP call clears the tenant context; restore it so tests can read models directly afterwards. */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $tenant = $this->admin->tenant_id ?? null;
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        if ($tenant) {
            $this->context($tenant);
        }

        return $response;
    }

    protected function user(Employee $e): User
    {
        return User::find(DB::table('people')->where('id', $e->person_id)->value('user_id'));
    }

    protected function as(Employee $e): static
    {
        return $this->signIn($this->user($e));
    }

    protected function account(Employee $e, LeaveType $type, string $credit = '10', string $negative = '0'): LeaveAccount
    {
        $a = LeaveAccount::create(['employee_id' => $e->id, 'leave_type_id' => $type->id, 'period_start' => '2026-01-01', 'period_end' => '2026-12-31', 'negative_limit' => $negative]);
        if ($credit !== '0') {
            app(LedgerService::class)->post(new LedgerPosting($a->id, 'accrual', 'accrued', $credit, '2026-01-01', 'seed:'.$a->id));
        }

        return $a;
    }

    protected function available(LeaveAccount $a): string
    {
        return app(BalanceService::class)->balance($a, '2026-10-07')->available;
    }

    protected function leaveBody(array $o = []): array
    {
        return $o + ['leave_type' => $this->annual->uuid, 'start_date' => '2026-10-12', 'end_date' => '2026-10-13', 'reason' => 'Family trip'];
    }
}
