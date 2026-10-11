<?php

namespace Tests\Concerns;

use App\Models\User;

/** Shared helpers for the lifecycle suites. Needs PeopleFixtures. */
trait LifecycleFixtures
{
    protected User $checker;

    protected function lifecycleSetup(): void
    {
        $this->bootTenant();
        $this->checker = $this->userWithRole('Workspace administrator', 'checker@alpha.test');
        $this->signIn($this->admin);
    }

    /** Creates and publishes a one-version template through the API. Returns the template uuid. */
    protected function publishedTemplate(string $kind, array $tasks, array $conditions = [], string $name = 'Standard'): string
    {
        $t = $this->postJson('/api/v1/lifecycle/templates', ['kind' => $kind, 'name' => $name, 'conditions' => $conditions, 'tasks' => $tasks])->assertCreated();
        $this->postJson('/api/v1/lifecycle/template-versions/'.$t->json('versions.0.uuid').'/publish')->assertOk();

        return $t->json('uuid');
    }
}
