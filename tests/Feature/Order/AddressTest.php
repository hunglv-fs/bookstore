<?php

namespace Tests\Feature\Order;

use App\Models\CustomerAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AddressTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'receiver_name' => 'Nguyen Van A',
            'phone' => '0901234567',
            'address' => '12 Nguyen Hue',
            'ward' => 'Ben Nghe',
            'district' => 'District 1',
            'city' => 'Ho Chi Minh City',
        ];
    }

    public function test_first_address_becomes_default_and_a_new_default_replaces_it(): void
    {
        $user = User::factory()->withCustomer()->create();
        Sanctum::actingAs($user);

        $first = $this->postJson('/api/addresses', $this->payload())->assertCreated()->assertJsonPath('data.is_default', true)->json('data.id');
        $this->postJson('/api/addresses', $this->payload(['address' => '2nd']))->assertCreated()->assertJsonPath('data.is_default', false);
        $third = $this->postJson('/api/addresses', $this->payload(['address' => '3rd', 'is_default' => true]))->assertJsonPath('data.is_default', true)->json('data.id');

        $this->assertFalse(CustomerAddress::find($first)->is_default);
        $this->assertSame([$third], CustomerAddress::where('is_default', true)->pluck('id')->all());

        $this->getJson('/api/addresses')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $third);
    }

    public function test_customer_only_sees_own_addresses(): void
    {
        CustomerAddress::factory()->create();
        Sanctum::actingAs(User::factory()->withCustomer()->create());

        $this->getJson('/api/addresses')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_address_is_validated(): void
    {
        Sanctum::actingAs(User::factory()->withCustomer()->create());

        $this->postJson('/api/addresses', ['phone' => 'abc'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['receiver_name', 'phone', 'address', 'ward', 'district', 'city']);
    }
}
