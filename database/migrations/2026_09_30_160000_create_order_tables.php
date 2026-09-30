<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STATUSES = "'pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled'";

    /**
     * Run the migrations.
     *
     * Orders and their children are financial records: every FK is RESTRICT
     * (never CASCADE) so nothing can delete an order, its items or its history
     * indirectly.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 20)->unique();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            // Addresses can only be created, never edited or deleted, so the
            // referenced row is effectively the shipping-address snapshot.
            $table->foreignId('address_id')->constrained('customer_addresses')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('payment_status', 20)->default('unpaid');
            $table->decimal('shipping_fee', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2);
            $table->decimal('total_amount', 12, 2);
            $table->text('note')->nullable();
            $table->timestamp('ordered_at');
            $table->timestamps();

            // "My orders, newest first" (GET /api/orders).
            $table->index(['customer_id', 'ordered_at']);
        });

        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_status_valid CHECK (status IN ('.self::STATUSES.'))');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_status_valid CHECK (payment_status IN ('unpaid', 'paid'))");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amounts_non_negative CHECK (subtotal >= 0 AND shipping_fee >= 0)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_total_matches CHECK (total_amount = subtotal + shipping_fee)');

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('book_id')->constrained('books')->restrictOnDelete();
            $table->integer('quantity');
            // Price at the time of purchase; never follows later changes of books.price.
            $table->decimal('unit_price', 12, 2);
            $table->decimal('subtotal', 12, 2);
            $table->timestamps();

            // One line per book (the cart is already merged per book); the
            // leading order_id also serves "items of this order".
            $table->unique(['order_id', 'book_id']);
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_positive CHECK (quantity > 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_unit_price_non_negative CHECK (unit_price >= 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_subtotal_matches CHECK (subtotal = unit_price * quantity)');

        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            // NULL only for the entry recording the creation of the order.
            $table->string('old_status', 20)->nullable();
            $table->string('new_status', 20);
            $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Timeline of an order.
            $table->index(['order_id', 'created_at']);
        });

        DB::statement('ALTER TABLE order_status_histories ADD CONSTRAINT order_status_histories_statuses_valid CHECK ((old_status IS NULL OR old_status IN ('.self::STATUSES.')) AND new_status IN ('.self::STATUSES.'))');
        DB::statement('ALTER TABLE order_status_histories ADD CONSTRAINT order_status_histories_status_changes CHECK (old_status IS DISTINCT FROM new_status)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
