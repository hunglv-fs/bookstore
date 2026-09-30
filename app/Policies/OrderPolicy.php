<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class OrderPolicy
{
    /**
     * Customers see only their own orders; another customer's order is
     * reported as "not found" so order ids cannot be probed.
     */
    public function view(User $user, Order $order): Response
    {
        return $user->customer !== null && $order->customer_id === $user->customer->id
            ? Response::allow()
            : Response::denyAsNotFound('Resource not found.');
    }

    /**
     * Ownership only; whether the order's status still allows cancelling is a
     * business rule checked (on the locked row) by OrderService.
     */
    public function cancel(User $user, Order $order): Response
    {
        return $this->view($user, $order);
    }
}
