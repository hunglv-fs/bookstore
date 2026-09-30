<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Support\Facades\DB;

class AddressService
{
    /**
     * Add a shipping address. The first address becomes the default; a new
     * default replaces the previous one. The customer row is locked so two
     * concurrent "make default" requests cannot race into the one-default
     * partial unique index.
     *
     * @param  array{receiver_name: string, phone: string, address: string, ward: string, district: string, city: string, is_default?: bool}  $data
     */
    public function create(Customer $customer, array $data): CustomerAddress
    {
        return DB::transaction(function () use ($customer, $data) {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->first();

            $isDefault = ($data['is_default'] ?? false) || ! $customer->addresses()->exists();

            if ($isDefault) {
                $customer->addresses()->where('is_default', true)->update(['is_default' => false]);
            }

            return $customer->addresses()->create(['is_default' => $isDefault] + $data);
        });
    }
}
