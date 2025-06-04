<?php

namespace Database\Seeders;

use App\Models\Publisher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PublisherSeeder extends Seeder
{
    public function run(): void
    {
        $publishers = [
            'انتشارات نشر نو',
            'انتشارات امیرکبیر',
            'انتشارات علمی و فرهنگی',
            'انتشارات سخن',
            'انتشارات جهان رایانه',
            'انتشارات ثالث',
            'انتشارات آگه',
            'انتشارات نیلوفر',
            'انتشارات چشمه',
            'انتشارات سروش'
        ];

        foreach ($publishers as $publisherName) {
            Publisher::create([
                'name' => $publisherName,
                'slug' => Str::slug($publisherName),
                'books_count' => 0
            ]);
        }
    }
}
