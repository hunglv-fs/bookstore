<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Address\StoreAddressRequest;
use App\Http\Resources\AddressResource;
use App\Services\AddressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Addresses are create-only: an address used by an order must never change,
 * so there is intentionally no update or delete endpoint.
 */
class AddressController extends Controller
{
    public function __construct(private readonly AddressService $addresses) {}

    /**
     * A customer has a handful of addresses; no pagination needed.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $addresses = $request->user()->customerOrFail()->addresses()
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();

        return AddressResource::collection($addresses);
    }

    public function store(StoreAddressRequest $request): JsonResponse
    {
        $address = $this->addresses->create($request->user()->customerOrFail(), $request->validated());

        return (new AddressResource($address))->response()->setStatusCode(201);
    }
}
