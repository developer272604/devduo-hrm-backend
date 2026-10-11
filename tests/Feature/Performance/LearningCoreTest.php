<?php

namespace Tests\Feature\Performance;

use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\Enrolment;

class LearningCoreTest extends PerformanceTestCase
{
    private function course(string $code, array $extra = []): string
    {
        return $this->postJson('/api/v1/learning/courses', $extra + ['code' => $code, 'title' => "$code course", 'delivery' => 'online'])
            ->assertCreated()->json('uuid');
    }

    public function test_catalog_course_publish_and_self_enrol_are_connected(): void
    {
        $course = $this->course('SAFE-101');
        $this->actAs($this->emp1);
        $this->getJson('/api/v1/learning/courses')->assertOk()->assertJsonCount(0, 'data');
        $this->signIn($this->admin);
        $this->getJson('/api/v1/learning/courses')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/learning/courses/$course/status", ['status' => 'published'])->assertOk();
        $this->getJson('/api/v1/learning/courses')->assertOk()->assertJsonCount(1, 'data');

        $this->actAs($this->emp1);
        $enrolment = $this->postJson("/api/v1/learning/courses/$course/enrol", [])->assertCreated()->json('uuid');
        $this->assertDatabaseHas('learning_enrolments', ['uuid' => $enrolment, 'employee_id' => $this->emp1->id, 'status' => 'enrolled']);
        $this->getJson('/api/v1/learning/my')->assertOk()->assertJsonPath('0.course.uuid', $course);
        $this->postJson("/api/v1/learning/courses/$course/enrol", [])->assertUnprocessable();
    }

    public function test_enrolment_requires_completed_prerequisite(): void
    {
        $base = $this->course('BASE-101');
        $next = $this->course('NEXT-101', ['prerequisite_uuids' => [$base]]);
        $this->postJson("/api/v1/learning/courses/$base/status", ['status' => 'published'])->assertOk();
        $this->postJson("/api/v1/learning/courses/$next/status", ['status' => 'published'])->assertOk();
        $this->actAs($this->emp1);
        $this->postJson("/api/v1/learning/courses/$next/enrol", [])->assertUnprocessable();

        $this->context($this->admin->tenant_id);
        Enrolment::create(['course_id' => Course::where('uuid', $base)->value('id'), 'employee_id' => $this->emp1->id, 'status' => 'completed', 'source' => 'self', 'course_version' => 1, 'cost' => 0, 'currency' => 'INR']);
        $this->postJson("/api/v1/learning/courses/$next/enrol", [])->assertCreated()->assertJsonPath('status', 'enrolled');
    }
}
