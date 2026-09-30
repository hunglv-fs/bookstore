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
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        DB::statement("ALTER TABLE carts ADD CONSTRAINT carts_status_valid CHECK (status IN ('active', 'converted'))");
        // A customer has at most one active cart; converted carts are kept as history.
        // Also the index behind "find the active cart of this customer".
        DB::statement("CREATE UNIQUE INDEX carts_one_active_per_customer ON carts (customer_id) WHERE status = 'active'");

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            // Items only describe their cart, so they follow it on delete.
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('books')->restrictOnDelete();
            $table->integer('quantity');
            $table->timestamps();

            // Leading cart_id also serves "items of this cart" lookups.
            $table->unique(['cart_id', 'book_id']);
        });

        DB::statement('ALTER TABLE cart_items ADD CONSTRAINT cart_items_quantity_positive CHECK (quantity > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
