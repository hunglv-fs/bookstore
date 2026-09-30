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
        Schema::create('publishers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('address')->nullable();
            $table->string('website')->nullable();
            $table->timestamps();
        });

        Schema::create('authors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('biography')->nullable();
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->text('description')->nullable();
            // Children lookups and the recursive "category + descendants" filter walk parent_id.
            $table->foreignId('parent_id')->nullable()->index()->constrained('categories')->restrictOnDelete();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_parent_not_self CHECK (parent_id IS NULL OR parent_id <> id)');
        // Sibling names must be unique; NULLS NOT DISTINCT makes this apply to root categories too.
        DB::statement('CREATE UNIQUE INDEX categories_parent_id_name_unique ON categories (parent_id, name) NULLS NOT DISTINCT');

        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->string('isbn', 13)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2);
            $table->foreignId('publisher_id')->index()->constrained('publishers')->restrictOnDelete();
            $table->date('published_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE books ADD CONSTRAINT books_price_non_negative CHECK (price >= 0)');
        DB::statement("ALTER TABLE books ADD CONSTRAINT books_status_valid CHECK (status IN ('active', 'inactive'))");
        DB::statement("ALTER TABLE books ADD CONSTRAINT books_isbn_format CHECK (isbn ~ '^[0-9]{9}[0-9X]$' OR isbn ~ '^[0-9]{13}$')");

        // Pivot and image rows only describe a book, so they may follow it on delete.
        // In practice books are never deleted once sold: order_items / inventory
        // reference books with RESTRICT (later phases); use status = inactive instead.
        Schema::create('book_authors', function (Blueprint $table) {
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->foreignId('author_id')->index()->constrained('authors')->restrictOnDelete();
            // The composite PK is the UNIQUE(book_id, author_id) and also serves lookups by book_id.
            $table->primary(['book_id', 'author_id']);
        });

        Schema::create('book_categories', function (Blueprint $table) {
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->foreignId('category_id')->index()->constrained('categories')->restrictOnDelete();
            $table->primary(['book_id', 'category_id']);
        });

        Schema::create('book_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->string('image_url', 2048);
            $table->boolean('is_thumbnail')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            // "Images of a book in display order".
            $table->index(['book_id', 'sort_order']);
        });

        DB::statement('ALTER TABLE book_images ADD CONSTRAINT book_images_sort_order_non_negative CHECK (sort_order >= 0)');
        DB::statement('CREATE UNIQUE INDEX book_images_one_thumbnail_per_book ON book_images (book_id) WHERE is_thumbnail');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('book_images');
        Schema::dropIfExists('book_categories');
        Schema::dropIfExists('book_authors');
        Schema::dropIfExists('books');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('authors');
        Schema::dropIfExists('publishers');
    }
};
