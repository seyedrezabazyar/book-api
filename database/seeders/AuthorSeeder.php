<?php

namespace Database\Seeders;

use App\Models\Author;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AuthorSeeder extends Seeder
{
    public function run(): void
    {
        $authors = [
            'فردوسی',
            'حافظ شیرازی',
            'سعدی شیرازی',
            'مولوی',
            'صادق هدایت',
            'احمد شاملو',
            'فروغ فرخزاد',
            'جورج اورول',
            'احمد محمدی',
            'رضا امیری'
        ];

        foreach ($authors as $authorName) {
            Author::create([
                'name' => $authorName,
                'slug' => Str::slug($authorName),
                'books_count' => 0
            ]);
        }
    }
}
