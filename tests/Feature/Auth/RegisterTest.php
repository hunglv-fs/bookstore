<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nguyen Van A',
            'email' => 'a@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'phone' => '0901234567',
        ], $overrides);
    }

    public function test_customer_can_register_and_receives_token(): void
    {
        $response = $this->postJson('/api/register', $this->payload());

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'token_type',
                    'user' => ['id', 'name', 'email', 'phone', 'role', 'is_active', 'customer' => ['id', 'name', 'email', 'phone']],
                ],
            ])
            ->assertJsonPath('data.user.role', 'customer')
            ->assertJsonPath('data.user.email', 'a@example.com')
            ->assertJsonMissingPath('data.user.password');

        $user = User::where('email', 'a@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole(RoleName::Customer));
        $this->assertNotNull($user->customer);
        $this->assertSame('0901234567', $user->customer->phone);

        $this->withToken($response->json('data.token'))
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'a@example.com');
    }

    public function test_registration_cannot_choose_role(): void
    {
        $this->postJson('/api/register', $this->payload(['role_id' => 1, 'role' => 'admin', 'is_active' => false]))
            ->assertCreated()
            ->assertJsonPath('data.user.role', 'customer')
            ->assertJsonPath('data.user.is_active', true);
    }

    public function test_email_is_normalized_and_must_be_unique(): void
    {
        $this->postJson('/api/register', $this->payload(['email' => ' A@Example.COM ']))
            ->assertCreated()
            ->assertJsonPath('data.user.email', 'a@example.com');

        $this->postJson('/api/register', $this->payload(['email' => 'a@EXAMPLE.com']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertSame(1, User::count());
    }

    public function test_registration_validates_input(): void
    {
        $this->postJson('/api/register', [
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'different',
            'phone' => 'abc',
        ])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors'])
            ->assertJsonValidationErrors(['name', 'email', 'password', 'phone']);
    }
}
