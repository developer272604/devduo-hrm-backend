<?php

namespace Tests\Feature\Recruitment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobPipelineTest extends TestCase
{
    use PipelineFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecruitment();
    }

    public function test_job_default_is_inherited_and_open_requisitions_keep_original_stages(): void
    {
        $pipeline = $this->pipeline();
        $job = $this->postJson('/api/v1/recruitment/jobs', ['code' => 'ENGINEER', 'title' => 'Engineer', 'description' => 'Build software', 'default_pipeline_code' => 'FAST'])->assertCreated()->json('uuid');
        $this->getJson('/api/v1/recruitment/jobs')->assertOk()->assertJsonPath('data.0.default_pipeline_code', 'FAST');
        $req = $this->requisition(extra: ['job_uuid' => $job]);
        $first = collect($this->getJson('/api/v1/recruitment/requisitions')->assertOk()->json('data'))->firstWhere('uuid', $req);
        $this->assertSame($pipeline, $first['pipeline_uuid']);
        $this->assertSame(1, $first['pipeline_version']);
        $this->assertSame('review', $first['pipeline_stages'][1]['code']);
        $stages = $this->stages();
        $stages[1]['code'] = 'portfolio';
        $this->postJson('/api/v1/recruitment/pipelines/'.$pipeline.'/versions', ['name' => 'New process', 'stages' => $stages])->assertCreated();
        $second = collect($this->getJson('/api/v1/recruitment/requisitions')->assertOk()->json('data'))->firstWhere('uuid', $req);
        $this->assertSame($first['pipeline_stages'], $second['pipeline_stages']);
        $this->assertSame(1, $second['pipeline_version']);
        $this->pipeline('OTHER');
        $override = $this->requisition(extra: ['job_uuid' => $job, 'pipeline_code' => 'OTHER']);
        $row = collect($this->getJson('/api/v1/recruitment/requisitions')->json('data'))->firstWhere('uuid', $override);
        $this->assertSame('OTHER', $row['pipeline_code']);
    }

    public function test_job_default_must_be_an_active_pipeline_in_this_tenant(): void
    {
        $this->pipeline('PRIVATE');
        $other = $this->bootTenant('other');
        $this->signIn($other)->postJson('/api/v1/recruitment/jobs', ['code' => 'ENGINEER', 'title' => 'Engineer', 'description' => 'Build software', 'default_pipeline_code' => 'PRIVATE'])->assertNotFound();
    }
}
