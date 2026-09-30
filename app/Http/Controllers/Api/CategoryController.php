<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    /**
     * The whole category tree. Categories are a small, admin-curated list, so
     * they are loaded in one query and nested in memory instead of paginated.
     */
    public function index(): AnonymousResourceCollection
    {
        $categories = Category::query()->orderBy('name')->get();
        $byParent = $categories->groupBy(fn (Category $c) => $c->parent_id ?? 0);

        foreach ($categories as $category) {
            $category->setRelation('children', $byParent->get($category->id, collect())->values());
        }

        return CategoryResource::collection($byParent->get(0, collect())->values());
    }

    public function show(Category $category): CategoryResource
    {
        return new CategoryResource($category->load(['parent', 'children']));
    }
}
