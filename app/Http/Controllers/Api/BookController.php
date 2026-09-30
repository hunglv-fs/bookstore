<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ListBooksRequest;
use App\Http\Resources\BookResource;
use App\Models\Book;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BookController extends Controller
{
    public function index(ListBooksRequest $request): AnonymousResourceCollection
    {
        $books = Book::query()
            ->active()
            ->filter($request->filters())
            ->sortBy($request->validated('sort'))
            ->with(['publisher', 'authors', 'categories', 'thumbnail', 'inventory'])
            ->paginate($request->perPage())
            ->withQueryString();

        return BookResource::collection($books);
    }

    public function show(Book $book): BookResource
    {
        // Inactive books are hidden from the storefront as if they did not exist.
        abort_unless($book->isActive(), 404);

        return new BookResource($book->load(['publisher', 'authors', 'categories', 'thumbnail', 'images', 'inventory']));
    }
}
