<?php

namespace App\Policies;

use App\Models\CartItem;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CartItemPolicy
{
    /**
     * A customer may only change items in their own active cart. Anything else
     * is reported as "not found" so item ids of other customers are not revealed.
     */
    public function update(User $user, CartItem $item): Response
    {
        $cart = $item->cart;

        return $user->customer !== null
            && $cart->customer_id === $user->customer->id
            && $cart->isActive()
                ? Response::allow()
                : Response::denyAsNotFound('Resource not found.');
    }

    public function delete(User $user, CartItem $item): Response
    {
        return $this->update($user, $item);
    }
}
