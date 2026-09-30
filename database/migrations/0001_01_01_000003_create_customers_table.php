<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            // unique() enforces the 1:1 users <-> customers relationship and
            // doubles as the lookup index for "customer of the current user".
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->index()->constrained('customers')->restrictOnDelete();
            $table->string('receiver_name');
            $table->string('phone', 20);
            $table->string('address');
            $table->string('ward', 100);
            $table->string('district', 100);
            $table->string('city', 100);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // At most one default address per customer.
        DB::statement('CREATE UNIQUE INDEX customer_addresses_one_default_per_customer ON customer_addresses (customer_id) WHERE is_default');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('customers');
    }
};
