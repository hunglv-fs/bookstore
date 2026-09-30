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
        // Single warehouse in the MVP: one row per book. A future multi-warehouse
        // design adds warehouse_id and widens the unique key to (warehouse_id, book_id).
        Schema::create('inventory', function (Blueprint $table) {
            $table->id();
            // unique() is both the one-row-per-book rule and the lookup/lock index.
            $table->foreignId('book_id')->unique()->constrained('books')->restrictOnDelete();
            $table->integer('quantity')->default(0);
            $table->integer('reserved_quantity')->default(0);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE inventory ADD CONSTRAINT inventory_quantity_non_negative CHECK (quantity >= 0)');
        DB::statement('ALTER TABLE inventory ADD CONSTRAINT inventory_reserved_non_negative CHECK (reserved_quantity >= 0)');
        // Last line of defence against overselling: available = quantity - reserved_quantity >= 0.
        DB::statement('ALTER TABLE inventory ADD CONSTRAINT inventory_reserved_within_quantity CHECK (reserved_quantity <= quantity)');

        // Append-only ledger. quantity_change is the change of the counter the
        // type affects: purchase / adjustment / sale -> quantity,
        // reservation / release -> reserved_quantity (a sale also consumes the
        // matching reservation, i.e. reserved_quantity decreases by the same amount).
        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained('books')->restrictOnDelete();
            $table->string('transaction_type', 20);
            $table->integer('quantity_change');
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Stock history of a book, newest first.
            $table->index(['book_id', 'created_at']);
        });

        DB::statement("ALTER TABLE inventory_transactions ADD CONSTRAINT inventory_transactions_type_valid CHECK (transaction_type IN ('purchase', 'sale', 'reservation', 'release', 'adjustment'))");
        DB::statement(<<<'SQL'
            ALTER TABLE inventory_transactions ADD CONSTRAINT inventory_transactions_sign_matches_type CHECK (
                (transaction_type IN ('purchase', 'reservation') AND quantity_change > 0)
                OR (transaction_type IN ('sale', 'release') AND quantity_change < 0)
                OR (transaction_type = 'adjustment' AND quantity_change <> 0)
            )
            SQL);
        DB::statement('ALTER TABLE inventory_transactions ADD CONSTRAINT inventory_transactions_reference_pair CHECK ((reference_type IS NULL) = (reference_id IS NULL))');
        DB::statement("ALTER TABLE inventory_transactions ADD CONSTRAINT inventory_transactions_order_movements_referenced CHECK (transaction_type NOT IN ('sale', 'reservation', 'release') OR reference_type IS NOT NULL)");
        // Idempotency guard: a document (order) reserves, releases or sells a
        // given book at most once, so repeating "cancel" or "deliver" can never
        // move stock twice, even under concurrent requests. Its leading
        // (reference_type, reference_id) columns also serve "the reservations
        // of order X", so no separate reference index is needed.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX inventory_transactions_once_per_reference
            ON inventory_transactions (reference_type, reference_id, book_id, transaction_type)
            WHERE transaction_type IN ('reservation', 'release', 'sale')
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('inventory');
    }
};
