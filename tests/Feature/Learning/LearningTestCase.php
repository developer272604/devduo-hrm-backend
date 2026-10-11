<?php

namespace Tests\Feature\Learning;

use App\Domain\Learning\Models\Course;
use App\Domain\People\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

abstract class LearningTestCase extends TestCase
{
    use RefreshDatabase, PeopleFixtures;

    protected const API = '/api/v1/learning/';

    protected Employee $mgr;

    protected Employee $emp1;

    protected Employee $emp2;

    protected Employee $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $this->mgr = $this->hire('Meera Rao', ['role' => 'Manager']);
        $this->emp1 = $this->hire('Arun Kumar', ['role' => 'Team member', 'manager' => $this->mgr]);
        $this->emp2 = $this->hire('Bela Shah', ['role' => 'Team member', 'manager' => $this->mgr]);
        $this->outsider = $this->hire('Omar Diaz', ['role' => 'Team member']);
        $this->signIn($this->admin);
    }

    protected function userOf(Employee $e): User
    {
        return User::findOrFail(DB::table('people')->where('id', $e->person_id)->value('user_id'));
    }

    protected function actAs(Employee|User $who): static
    {
        return $this->signIn($who instanceof Employee ? $this->userOf($who) : $who);
    }

    protected function p(string $path): string
    {
        return self::API.$path;
    }

    /** Creates and publishes a course as admin; returns its uuid. */
    protected function course(string $code, array $extra = [], bool $publish = true): string
    {
        $this->signIn($this->admin);
        $uuid = $this->postJson($this->p('courses'), $extra + ['code' => $code, 'title' => "$code course", 'delivery' => 'online'])->assertCreated()->json('uuid');
        if ($publish) $this->postJson($this->p("courses/$uuid/status"), ['status' => 'published'])->assertOk();

        return $uuid;
    }

    protected function makeSession(string $courseUuid, string $code, string $from, string $to, array $extra = []): string
    {
        $this->signIn($this->admin);

        return $this->postJson($this->p("courses/$courseUuid/sessions"), $extra + ['code' => $code, 'starts_on' => $from, 'ends_on' => $to])->assertCreated()->json('uuid');
    }

    protected function enrolmentOf(Employee $e, string $courseUuid): \App\Domain\Learning\Models\Enrolment
    {
        $this->context($this->admin->tenant_id);

        return \App\Domain\Learning\Models\Enrolment::where('employee_id', $e->id)->where('course_id', Course::where('uuid', $courseUuid)->value('id'))->firstOrFail();
    }

    protected function assign(string $courseUuid, array $employees, array $extra = [])
    {
        return $this->postJson($this->p('assign/course'), $extra + ['course_uuid' => $courseUuid, 'employee_uuids' => array_map(fn ($e) => $e->uuid, $employees)]);
    }
}
