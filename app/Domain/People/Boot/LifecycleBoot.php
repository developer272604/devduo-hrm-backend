<?php

namespace App\Domain\People\Boot;

use App\Domain\Events\DomainEventRecorded;
use App\Domain\People\Lifecycle\InvitationService;
use App\Domain\People\Lifecycle\SeparationService;
use App\Domain\People\Models\Employee;
use App\Domain\People\Operations\Bulk\SendInvitationAction;
use App\Domain\People\Operations\BulkActions;
use App\Http\Controllers\People\Lifecycle\PublicInvitationController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/**
 * Lifecycle wiring: the send_invitation bulk action, the "employee became separated" listener, and the one public (unauthenticated)
 * endpoint, invitation acceptance, which is throttled per IP and per token and answers every failure identically.
 */
final class LifecycleBoot
{
    public static function register(Application $app): void
    {
        $app->singleton(InvitationService::class);
        $app->singleton(SeparationService::class);
    }

    public static function boot(Application $app): void
    {
        $app->make(BulkActions::class)->register(new SendInvitationAction);

        Event::listen(DomainEventRecorded::class, function (DomainEventRecorded $recorded) {
            $event = $recorded->event;
            if ($event->type === 'EmploymentStatusChanged' && ($event->payload['to'] ?? null) === 'separated' && $event->employee_id) {
                $employee = Employee::query()->find($event->employee_id);
                $employee && app(SeparationService::class)->onSeparated($employee);
            }
        });

        RateLimiter::for('people-invitation-accept', fn ($request) => [
            Limit::perMinute(10)->by('invite-ip:'.$request->ip()),
            Limit::perMinute(5)->by('invite-token:'.sha1((string) $request->input('token'))),
        ]);
        Route::middleware(['web', 'throttle:people-invitation-accept'])->prefix('api/v1/public')->group(function () {
            Route::post('invitations/accept', [PublicInvitationController::class, 'accept']);
        });
    }
}
