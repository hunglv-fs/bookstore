<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class LogoutAndMeTest extends TestCase
{
    use RefreshDatabase;

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->withCustomer()->create();
        $phoneToken = $user->createToken('phone')->plainTextToken;
        $laptopToken = $user->createToken('laptop')->plainTextToken;

        $this->withToken($phoneToken)->postJson('/api/logout')->assertNoContent();

        $this->assertSame(1, PersonalAccessToken::count());
        $this->assertSame('laptop', PersonalAccessToken::first()->name);

        // Guards cache the resolved user between requests inside one test.
        $this->app['auth']->forgetGuards();
        $this->withToken($phoneToken)->getJson('/api/me')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->withToken($laptopToken)->getJson('/api/me')->assertOk();
    }

    public function test_tokens_expire_when_a_lifetime_is_configured(): void
    {
        config(['sanctum.expiration' => 60]);
        $token = User::factory()->withCustomer()->create()->createToken('api')->plainTextToken;

        $this->travel(59)->minutes();
        $this->withToken($token)->getJson('/api/me')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->travel(2)->minutes();
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/me')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_unauthenticated_request_without_accept_header_gets_json_401(): void
    {
        $this->get('/api/me')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/logout')->assertUnauthorized();
    }

    public function test_me_returns_admin_role_without_customer_profile(): void
    {
        $admin = User::factory()->admin()->create();

        $this->withToken($admin->createToken('api')->plainTextToken)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.customer', null);
    }

    public function test_deactivated_user_token_is_rejected(): void
    {
        $user = User::factory()->withCustomer()->create();
        $token = $user->createToken('api')->plainTextToken;

        $user->forceFill(['is_active' => false])->save();

        $this->withToken($token)
            ->getJson('/api/me')
            ->assertForbidden()
            ->assertJsonPath('message', 'Your account has been deactivated.');
    }

    public function test_api_errors_are_json_without_accept_header(): void
    {
        $this->get('/api/does-not-exist')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Resource not found.']);
    }
}
