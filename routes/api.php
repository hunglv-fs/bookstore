<?php

use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\Admin\OrderStatusController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\OrderController;
use Illuminate\Support\Facades\Route;

// Route-model ids must fit a PostgreSQL bigint; anything else is a 404 instead
// of an "invalid input syntax for type bigint" database error.
Route::pattern('book', '[0-9]{1,18}');
Route::pattern('category', '[0-9]{1,18}');
Route::pattern('item', '[0-9]{1,18}');
Route::pattern('order', '[0-9]{1,18}');

Route::middleware('throttle:10,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::get('/books', [BookController::class, 'index']);
Route::get('/books/{book}', [BookController::class, 'show']);
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{category}', [CategoryController::class, 'show']);

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::middleware('role:customer')->group(function () {
        Route::get('/cart', [CartController::class, 'show']);
        Route::post('/cart/items', [CartController::class, 'storeItem']);
        Route::patch('/cart/items/{item}', [CartController::class, 'updateItem'])->can('update', 'item');
        Route::delete('/cart/items/{item}', [CartController::class, 'destroyItem'])->can('delete', 'item');

        Route::get('/addresses', [AddressController::class, 'index']);
        Route::post('/addresses', [AddressController::class, 'store']);

        Route::get('/orders', [OrderController::class, 'index']);
        Route::post('/orders', [OrderController::class, 'store']);
        Route::get('/orders/{order}', [OrderController::class, 'show'])->can('view', 'order');
        Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->can('cancel', 'order');
        Route::get('/orders/{order}/payments', [OrderController::class, 'payments'])->can('view', 'order');
    });

    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::patch('/orders/{order}/status', [OrderStatusController::class, 'update']);
    });
});
