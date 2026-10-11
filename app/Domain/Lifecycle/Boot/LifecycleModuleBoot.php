<?php

namespace App\Domain\Lifecycle\Boot;

use App\Domain\Events\DomainEventRecorded;
use App\Domain\Insights\ActionCenter;
use App\Domain\Lifecycle\{CaseService, OffboardingService};
use App\Domain\Lifecycle\Contracts\{ClearanceRegistry, MoveGuard, NullClearanceProvider, NullMoveGuard, PayrollSettlementInitiator, SettlementInitiator};
use App\Domain\Lifecycle\Models\LifecycleCase;
use App\Domain\Lifecycle\Operations\{LifecycleEmployee360Provider, LifecycleInsightProvider, LifecycleQualityRules};
use App\Domain\People\Employee360\Employee360;
use App\Domain\People\Models\{Employee, SeparationRecord};
use App\Domain\Quality\DataQualityEngine;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\{Event, Log};

/**
 * Phase 7 wiring. Orchestrates existing domains: reacts to RecruitmentHired (onboarding), SeparationInitiated / Withdrawn (offboarding) and
 * EmployeeSeparated (alumni). Clearance providers for assets/expenses/loans/leave/payroll are null implementations until Phases 8-11 register real ones.
 */
final class LifecycleModuleBoot
{
    public static function register(Application $app): void
    {
        $app->singleton(ClearanceRegistry::class, function () {
            $r = new ClearanceRegistry;
            foreach (['assets', 'expenses', 'loans', 'leave', 'payroll'] as $key) $r->register(new NullClearanceProvider($key));

            return $r;
        });
        $app->bindIf(MoveGuard::class, NullMoveGuard::class);
        $app->bindIf(SettlementInitiator::class, PayrollSettlementInitiator::class);
    }

    public static function boot(Application $app): void
    {
        $app->make(ActionCenter::class)->register(LifecycleInsightProvider::class);
        $app->make(Employee360::class)->register(LifecycleEmployee360Provider::class);
        $engine = $app->make(DataQualityEngine::class);
        foreach (LifecycleQualityRules::all() as $rule) $engine->register($rule);

        Event::listen(DomainEventRecorded::class, function (DomainEventRecorded $recorded) {
            $e = $recorded->event;
            if (! in_array($e->type, ['RecruitmentHired', 'SeparationInitiated', 'SeparationWithdrawn', 'EmployeeSeparated'], true) || ! $e->employee_id) return;
            try {
                $employee = Employee::find($e->employee_id);
                if (! $employee || ! in_array('lifecycle', \App\Models\Tenant::whereKey($e->tenant_id)->value('settings')['modules'] ?? [], true)) return;
                $cases = app(CaseService::class);
                match ($e->type) {
                    'RecruitmentHired' => $cases->generate('onboarding', $employee, $e->effective_date?->toDateString() ?? $employee->joined_on->toDateString(), ['source' => 'recruitment_handover']),
                    'SeparationInitiated' => $cases->generate('offboarding', $employee, $e->effective_date?->toDateString() ?? $employee->separated_on->toDateString(), [
                        'source' => 'separation', 'separation_type' => $e->payload['type'] ?? null, 'separation_record_id' => SeparationRecord::where('uuid', $e->payload['record'] ?? '')->value('id')]),
                    'SeparationWithdrawn' => LifecycleCase::where('employee_id', $employee->id)->where('kind', 'offboarding')->where('status', 'open')->get()->each(fn ($c) => $cases->cancel($c, 'Separation withdrawn')),
                    default => app(OffboardingService::class)->onSeparated($employee),
                };
            } catch (\Throwable $ex) {
                Log::warning('lifecycle.event_failed', ['event' => $e->type, 'error' => $ex->getMessage()]);
            }
        });
    }
}
