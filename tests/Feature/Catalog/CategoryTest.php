<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_nested_tree(): void
    {
        $programming = Category::factory()->create(['name' => 'Programming']);
        $backend = Category::factory()->childOf($programming)->create(['name' => 'Backend']);
        Category::factory()->childOf($backend)->create(['name' => 'PHP']);
        Category::factory()->childOf($programming)->create(['name' => 'Frontend']);
        Category::factory()->create(['name' => 'Business']);

        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Business')
            ->assertJsonPath('data.1.name', 'Programming')
            ->assertJsonPath('data.1.children.0.name', 'Backend')
            ->assertJsonPath('data.1.children.0.children.0.name', 'PHP')
            ->assertJsonPath('data.1.children.1.name', 'Frontend');
    }

    public function test_show_returns_parent_and_children(): void
    {
        $programming = Category::factory()->create(['name' => 'Programming']);
        $backend = Category::factory()->childOf($programming)->create(['name' => 'Backend']);
        Category::factory()->childOf($backend)->create(['name' => 'PHP']);

        $this->getJson("/api/categories/{$backend->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Backend')
            ->assertJsonPath('data.parent.name', 'Programming')
            ->assertJsonPath('data.children.0.name', 'PHP');
    }

    public function test_show_missing_category_is_not_found(): void
    {
        $this->getJson('/api/categories/12345')->assertNotFound();
        $this->getJson('/api/categories/abc')->assertNotFound();
    }
}
