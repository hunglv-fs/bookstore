<?php

namespace Tests\Feature\Cart;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CartAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_cart_requires_authentication(): void
    {
        $this->getJson('/api/cart')->assertUnauthorized();
        $this->postJson('/api/cart/items', ['book_id' => 1, 'quantity' => 1])->assertUnauthorized();
        $this->patchJson('/api/cart/items/1', ['quantity' => 1])->assertUnauthorized();
        $this->deleteJson('/api/cart/items/1')->assertUnauthorized();
    }

    public function test_admin_has_no_cart(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/cart')
            ->assertForbidden()
            ->assertExactJson(['message' => 'This action is only available to customers.']);
    }

    public function test_unknown_item_is_not_found(): void
    {
        Sanctum::actingAs(User::factory()->withCustomer()->create());

        $this->patchJson('/api/cart/items/999999', ['quantity' => 1])->assertNotFound();
        $this->deleteJson('/api/cart/items/abc')->assertNotFound();
    }
}
