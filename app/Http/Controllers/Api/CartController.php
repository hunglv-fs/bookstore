<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\StoreCartItemRequest;
use App\Http\Requests\Cart\UpdateCartItemRequest;
use App\Http\Resources\CartResource;
use App\Models\Cart;
use App\Models\CartItem;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(private readonly CartService $carts) {}

    public function show(Request $request): JsonResponse
    {
        return $this->respond($this->carts->activeCartFor($request->user()->customerOrFail()));
    }

    public function storeItem(StoreCartItemRequest $request): JsonResponse
    {
        $cart = $this->carts->addItem(
            $request->user()->customerOrFail(),
            $request->integer('book_id'),
            $request->integer('quantity'),
        );

        return $this->respond($cart);
    }

    public function updateItem(UpdateCartItemRequest $request, CartItem $item): JsonResponse
    {
        return $this->respond($this->carts->updateItem($item, $request->integer('quantity')));
    }

    public function destroyItem(CartItem $item): JsonResponse
    {
        return $this->respond($this->carts->removeItem($item));
    }

    /**
     * Always 200: JsonResource would answer 201 whenever the cart row was
     * created lazily during this request, which is wrong for GET /cart.
     */
    private function respond(Cart $cart): JsonResponse
    {
        return (new CartResource($cart->load('items.book.thumbnail')))->response()->setStatusCode(200);
    }
}
