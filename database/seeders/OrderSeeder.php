<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Enums\RoleName;
use App\Models\Book;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\User;
use App\Services\CartService;
use App\Services\CheckoutService;
use App\Services\OrderService;
use Illuminate\Database\Seeder;

class OrderSeeder extends Seeder
{
    /**
     * Every customer gets a default address. The demo customer gets three
     * orders (pending, delivered, cancelled) placed through the real services,
     * so inventory and its ledger stay consistent.
     */
    public function run(CartService $carts, CheckoutService $checkout, OrderService $orders): void
    {
        Customer::query()->each(fn (Customer $customer) => CustomerAddress::factory()->default()->create([
            'customer_id' => $customer->id,
            'receiver_name' => $customer->name,
            'phone' => $customer->phone ?? '0900000000',
        ]));

        $admin = User::query()->whereRelation('role', 'name', RoleName::Admin->value)->firstOrFail();
        $demo = User::query()->where('email', 'customer@bookstore.test')->firstOrFail();
        $address = $demo->customer->addresses()->firstOrFail();
        $books = Book::query()->active()->whereHas('inventory', fn ($q) => $q->where('quantity', '>=', 5))->orderBy('id')->limit(4)->get();

        $place = function (array $lines) use ($carts, $checkout, $demo, $address) {
            foreach ($lines as [$book, $quantity]) {
                $carts->addItem($demo->customer, $book->id, $quantity);
            }

            return $checkout->checkout($demo->customer, $address->id, null, $demo);
        };

        $delivered = $place([[$books[0], 2], [$books[1], 1]]);
        foreach ([OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Delivered] as $status) {
            $orders->changeStatus($delivered, $status, $admin);
        }

        $cancelled = $place([[$books[2], 1]]);
        $orders->cancel($cancelled, $demo, 'Changed my mind');

        $place([[$books[3], 1], [$books[0], 1]]);
    }
}
