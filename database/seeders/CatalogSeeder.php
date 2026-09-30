<?php

namespace Database\Seeders;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookImage;
use App\Models\Category;
use App\Models\Publisher;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    /**
     * Category tree => title building blocks for books filed under each leaf.
     */
    private const TREE = [
        'Programming' => [
            'Backend' => [['Laravel', 'PHP 8', 'Go', 'Node.js', 'Django', 'Spring Boot', 'REST API'], ['in Action', 'Cookbook', 'Up and Running', 'Deep Dive', 'for Professionals']],
            'Frontend' => [['React', 'Vue.js', 'TypeScript', 'CSS', 'JavaScript'], ['Essentials', 'Patterns', 'The Definitive Guide', 'from Scratch']],
            'Database' => [['PostgreSQL', 'SQL', 'Data Modeling', 'Query Tuning', 'Database Internals'], ['Mastery', 'Explained', 'in Practice', 'Handbook']],
        ],
        'Business' => [
            'Marketing' => [['Digital Marketing', 'Brand Strategy', 'Content Marketing', 'Growth'], ['Playbook', 'That Works', 'for Startups']],
            'Finance' => [['Personal Finance', 'Investing', 'Accounting', 'Startup Finance'], ['Made Simple', 'Fundamentals', 'for Everyone']],
        ],
        'Literature' => [
            'Novels' => [['The Silent', 'The Last', 'A Distant', 'The Hidden', 'Beyond the'], ['River', 'Garden', 'Winter', 'City', 'Harbor']],
            'Children' => [['The Little', 'Brave', 'Curious', 'The Magic'], ['Fox', 'Dragon', 'Explorer', 'Treehouse']],
        ],
    ];

    private const PUBLISHERS = [
        ['name' => 'Saigon Tech Press', 'address' => '12 Nguyen Hue, District 1, Ho Chi Minh City', 'website' => 'https://saigontechpress.example'],
        ['name' => 'Hanoi Knowledge Publishing', 'address' => '45 Trang Tien, Hoan Kiem, Hanoi', 'website' => 'https://hanoiknowledge.example'],
        ['name' => 'Lotus Books', 'address' => '8 Tran Phu, Hai Chau, Da Nang', 'website' => 'https://lotusbooks.example'],
        ['name' => 'Mekong Readers', 'address' => '101 Hoa Binh, Ninh Kieu, Can Tho', 'website' => 'https://mekongreaders.example'],
        ['name' => 'Bamboo House', 'address' => '27 Le Loi, Hue', 'website' => 'https://bamboohouse.example'],
    ];

    public function run(): void
    {
        $publishers = collect(self::PUBLISHERS)->map(fn (array $p) => Publisher::create($p));
        $authors = Author::factory()->count(10)->create();

        $leaves = $this->createCategories();

        // 100 books spread round-robin over the 7 leaf categories.
        foreach (range(0, 99) as $i) {
            [$leaf, [$subjects, $suffixes]] = $leaves[$i % count($leaves)];

            $book = Book::factory()
                ->for($publishers->random())
                ->state([
                    'title' => fake()->randomElement($subjects).' '.fake()->randomElement($suffixes),
                    // ~5% of the catalog is unpublished to exercise the active filter.
                    'status' => $i % 20 === 19 ? 'inactive' : 'active',
                ])
                ->create();

            $book->authors()->attach($authors->random(fake()->numberBetween(1, 2))->pluck('id'));
            $book->categories()->attach($leaf->id);

            $this->createImages($book, fake()->numberBetween(1, 3));
        }
    }

    /**
     * @return list<array{0: Category, 1: array{0: list<string>, 1: list<string>}}>
     */
    private function createCategories(): array
    {
        $leaves = [];

        foreach (self::TREE as $rootName => $children) {
            $root = Category::create(['name' => $rootName, 'description' => "Books about {$rootName}"]);

            foreach ($children as $childName => $titleParts) {
                $leaf = Category::create([
                    'name' => $childName,
                    'description' => "{$rootName} / {$childName}",
                    'parent_id' => $root->id,
                ]);
                $leaves[] = [$leaf, $titleParts];
            }
        }

        return $leaves;
    }

    private function createImages(Book $book, int $count): void
    {
        foreach (range(0, $count - 1) as $position) {
            BookImage::factory()->for($book)->create([
                'image_url' => "https://picsum.photos/seed/{$book->isbn}-{$position}/400/600",
                'is_thumbnail' => $position === 0,
                'sort_order' => $position,
            ]);
        }
    }
}
