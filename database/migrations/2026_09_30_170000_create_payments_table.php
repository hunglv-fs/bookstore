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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            // Financial record: RESTRICT, like the order itself.
            $table->foreignId('order_id')->index()->constrained('orders')->restrictOnDelete();
            $table->string('method', 20);
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('pending');
            // Reference from a payment gateway; COD has none.
            $table->string('transaction_no', 100)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            // A gateway transaction can be recorded only once (NULLs, i.e. COD, are not compared).
            $table->unique(['method', 'transaction_no']);
        });

        // Only COD is implemented in the MVP; the other gateway methods are
        // already accepted by the schema so adding one needs no migration.
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_valid CHECK (method IN ('cod', 'bank_transfer', 'credit_card', 'e_wallet'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_valid CHECK (status IN ('pending', 'paid', 'failed'))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_non_negative CHECK (amount >= 0)');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_paid_at_iff_paid CHECK ((status = 'paid') = (paid_at IS NOT NULL))");
        // At most one live (pending or paid) payment per order; failed attempts may accumulate.
        DB::statement("CREATE UNIQUE INDEX payments_one_live_per_order ON payments (order_id) WHERE status IN ('pending', 'paid')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
