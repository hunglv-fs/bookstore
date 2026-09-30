<?php

namespace App\Http\Requests\Catalog;

use App\Models\Book;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListBooksRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'keyword' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'author_id' => ['nullable', 'integer', 'min:1'],
            'publisher_id' => ['nullable', 'integer', 'min:1'],
            'min_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'max_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999', Rule::when($this->filled('min_price'), 'gte:min_price')],
            'sort' => ['nullable', 'string', Rule::in(array_keys(Book::SORTS))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return $this->safe()->only(['keyword', 'category_id', 'author_id', 'publisher_id', 'min_price', 'max_price']);
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }
}
