<?php

namespace Tests\Feature\Leave;

use App\Models\User;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

/** Keeps the tenant context after HTTP calls (the app clears it per request) so tests can touch models directly. */
abstract class PolicyTestCase extends TestCase
{
    use PeopleFixtures { signIn as protected baseSignIn; }

    protected ?int $currentTenant = null;

    protected function signIn(User $user): static
    {
        $this->baseSignIn($user);
        $this->currentTenant = $user->tenant_id;

        return $this;
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        if ($this->currentTenant) {
            $this->context($this->currentTenant);
        }

        return $response;
    }
}
