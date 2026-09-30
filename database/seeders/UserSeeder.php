<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * One admin plus ten customer accounts, all with password "password".
     */
    public function run(): void
    {
        User::factory()->admin()->create([
            'name' => 'Store Admin',
            'email' => 'admin@bookstore.test',
        ]);

        User::factory()->withCustomer()->create([
            'name' => 'Demo Customer',
            'email' => 'customer@bookstore.test',
        ]);

        User::factory()->count(9)->withCustomer()->create();
    }
}
