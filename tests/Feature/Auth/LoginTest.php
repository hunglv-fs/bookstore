<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_valid_credentials(): void
    {
        User::factory()->withCustomer()->create(['email' => 'b@example.com']);

        $response = $this->postJson('/api/login', [
            'email' => 'B@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['token', 'token_type', 'user' => ['id', 'email', 'role']]])
            ->assertJsonPath('data.token_type', 'Bearer');

        $this->withToken($response->json('data.token'))
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'b@example.com')
            ->assertJsonPath('data.role', 'customer');
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create(['email' => 'b@example.com']);

        $this->postJson('/api/login', ['email' => 'b@example.com', 'password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email'])
            ->assertJsonMissingPath('data');

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_login_fails_for_unknown_email_with_same_message(): void
    {
        User::factory()->create(['email' => 'b@example.com']);

        $wrongPassword = $this->postJson('/api/login', ['email' => 'b@example.com', 'password' => 'wrong'])->json('errors.email');
        $unknownEmail = $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'password'])->json('errors.email');

        $this->assertSame($wrongPassword, $unknownEmail);
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->inactive()->create(['email' => 'c@example.com']);

        $this->postJson('/api/login', ['email' => 'c@example.com', 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->postJson('/api/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }
}
