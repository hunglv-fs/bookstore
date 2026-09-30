<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * One or more books do not have enough available stock. Rendered as 422 in
 * the same shape as validation errors, plus machine-readable details.
 */
class InsufficientStockException extends RuntimeException
{
    /**
     * @param  list<array{book_id: int, requested: int, available: int}>  $shortages
     */
    public function __construct(public readonly array $shortages)
    {
        parent::__construct('Insufficient stock');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => 'Insufficient stock',
            'errors' => [
                'book_id' => array_map(
                    fn (array $s) => "The requested quantity is not available (book {$s['book_id']}: requested {$s['requested']}, available {$s['available']}).",
                    $this->shortages,
                ),
            ],
            'shortages' => $this->shortages,
        ], 422);
    }
}
