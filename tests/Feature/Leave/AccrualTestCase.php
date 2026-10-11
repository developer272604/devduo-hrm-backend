<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Contracts\LeavePolicyResolver;
use App\Domain\Leave\Models\LeavePolicy;
use App\Domain\Leave\Models\LeavePolicyVersion;
use App\Domain\Leave\Models\LeaveType;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

/** Workstream B tests run against a FAKE policy resolver, so they do not depend on workstream A's policy authoring. */
abstract class AccrualTestCase extends TestCase
{
    use PeopleFixtures;

    protected LeaveType $al;

    public LeavePolicyVersion $version;

    /** @var array<string,mixed> rules returned for every employee/type/date */
    public array $rules = [];

    protected function setUpLeave(array $rules): void
    {
        $this->bootTenant();
        $this->rules = $rules;
        $this->al = LeaveType::create(['name' => 'Annual leave', 'code' => 'AL', 'kind' => 'annual']);
        $policy = LeavePolicy::create(['name' => 'Std', 'code' => 'STD']);
        $this->version = LeavePolicyVersion::create(['policy_id' => $policy->id, 'version' => 1, 'status' => 'active', 'effective_from' => '2020-01-01']);
        $test = $this;
        $this->app->bind(LeavePolicyResolver::class, fn () => new class($test) implements LeavePolicyResolver
        {
            public function __construct(private AccrualTestCase $t) {}

            public function resolve(int $employeeId, string $date): ?array
            {
                return null;
            }

            public function rulesFor(int $employeeId, int $leaveTypeId, string $date): ?array
            {
                return $this->t->rules ? ['version_id' => $this->t->version->id, 'policy_id' => $this->t->version->policy_id, 'type_id' => $leaveTypeId, 'rules' => $this->t->rules, 'reason' => 'fake'] : null;
            }
        });
    }
}
