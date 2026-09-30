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
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // Roles are reference data the application cannot run without
        // (registration assigns "customer"), so they ship with the schema
        // instead of depending on an optional seeder.
        DB::table('roles')->insert([
            ['name' => 'admin', 'description' => 'Store administrator', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'customer', 'description' => 'Registered customer', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
