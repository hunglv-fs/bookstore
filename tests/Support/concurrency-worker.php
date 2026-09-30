<?php

/**
 * Runs one business operation in its own PHP process (own DB connection,
 * own transaction) for the concurrency tests. Prints a one-word outcome.
 *
 * Usage:
 *   php concurrency-worker.php reserve '{"12":1}' <reference_id>
 *   php concurrency-worker.php checkout <user_id> <address_id>
 *   php concurrency-worker.php status <order_id> <actor_user_id> <new_status>
 *
 * Outcomes: ok | insufficient | invalid:<field,...> | error: <details>
 */

use App\Enums\OrderStatus;
use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\InventoryService;
use App\Services\OrderService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$action = $argv[1] ?? '';
$args = array_slice($argv, 2);

try {
    match ($action) {
        'reserve' => DB::transaction(fn () => app(InventoryService::class)
            ->reserve(json_decode($args[0], true), 'test', (int) $args[1])),
        'checkout' => (function () use ($args) {
            $user = User::findOrFail((int) $args[0]);
            app(CheckoutService::class)->checkout($user->customer, (int) $args[1], null, $user);
        })(),
        'status' => app(OrderService::class)->changeStatus(
            Order::findOrFail((int) $args[0]),
            OrderStatus::from($args[2]),
            User::findOrFail((int) $args[1]),
        ),
    };

    echo 'ok';
} catch (InsufficientStockException) {
    echo 'insufficient';
} catch (ValidationException $e) {
    echo 'invalid:'.implode(',', array_keys($e->errors()));
} catch (Throwable $e) {
    echo 'error: '.$e::class.': '.$e->getMessage();
}
