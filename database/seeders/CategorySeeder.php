<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'ادبیات فارسی',
            'ادبیات جهان',
            'تاریخ',
            'فلسفه',
            'علوم اجتماعی',
            'کامپیوتر و IT',
            'ریاضی و فیزیک',
            'روانشناسی',
            'اقتصاد',
            'هنر و معماری',
        ];

        foreach ($categories as $categoryName) {
            Category::create([
                'name' => $categoryName,
                'slug' => Str::slug($categoryName),
                'books_count' => 0
            ]);
        }
    }
}
