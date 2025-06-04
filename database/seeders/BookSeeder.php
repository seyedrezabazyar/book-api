<?php

namespace Database\Seeders;

use App\Models\Book;
use App\Models\Category;
use App\Models\Author;
use App\Models\Publisher;
use App\Models\BookSource;
use App\Models\BookImage;
use App\Models\BookHash;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class BookSeeder extends Seeder
{
    public function run(): void
    {
        $books = [
            [
                'title' => 'شاهنامه فردوسی',
                'description' => 'حماسه ملی ایران که داستان پهلوانان و شاهان ایران باستان را روایت می‌کند.',
                'excerpt' => 'بزرگ‌ترین اثر حماسی ادبیات فارسی',
                'publication_year' => 2020,
                'pages_count' => 3000,
                'isbn' => '978-964-123-456-7',
                'language' => 'fa',
                'format' => 'pdf',
                'file_size' => 25600000,
                'category_name' => 'ادبیات فارسی',
                'publisher_name' => 'انتشارات نشر نو',
                'authors' => ['فردوسی'],
                'lgrs_ids' => ['12345', '54321']
            ],
            [
                'title' => 'دیوان حافظ',
                'description' => 'مجموعه غزل‌های خواجه شمس‌الدین محمد حافظ شیرازی',
                'excerpt' => 'شاعر بزرگ قرن هشتم هجری',
                'publication_year' => 2019,
                'pages_count' => 500,
                'isbn' => '978-964-123-456-8',
                'language' => 'fa',
                'format' => 'epub',
                'file_size' => 5120000,
                'category_name' => 'ادبیات فارسی',
                'publisher_name' => 'انتشارات امیرکبیر',
                'authors' => ['حافظ شیرازی'],
                'lgrs_ids' => ['12346']
            ],
            [
                'title' => 'بوف کور',
                'description' => 'اثر مشهور صادق هدایت',
                'excerpt' => 'شاهکار ادبیات مدرن فارسی',
                'publication_year' => 2018,
                'pages_count' => 80,
                'isbn' => '978-964-123-456-9',
                'language' => 'fa',
                'format' => 'pdf',
                'file_size' => 2048000,
                'category_name' => 'ادبیات فارسی',
                'publisher_name' => 'انتشارات علمی و فرهنگی',
                'authors' => ['صادق هدایت'],
                'lgrs_ids' => ['54322']
            ],
            [
                'title' => '1984',
                'description' => 'رمان دیستوپیایی جورج اورول',
                'excerpt' => 'کلاسیک ادبیات جهان',
                'publication_year' => 2017,
                'pages_count' => 328,
                'isbn' => '978-964-123-457-0',
                'language' => 'fa',
                'format' => 'pdf',
                'file_size' => 7340032,
                'category_name' => 'ادبیات جهان',
                'publisher_name' => 'انتشارات سخن',
                'authors' => ['جورج اورول'],
                'lgrs_ids' => ['11111', '22222']
            ],
            [
                'title' => 'آموزش برنامه‌نویسی PHP',
                'description' => 'راهنمای کامل یادگیری PHP',
                'excerpt' => 'کتاب جامع برنامه‌نویسی',
                'publication_year' => 2023,
                'pages_count' => 450,
                'isbn' => '978-964-123-457-1',
                'language' => 'fa',
                'format' => 'pdf',
                'file_size' => 15728640,
                'category_name' => 'کامپیوتر و IT',
                'publisher_name' => 'انتشارات جهان رایانه',
                'authors' => ['احمد محمدی'],
                'lgrs_ids' => ['33333']
            ]
        ];

        foreach ($books as $index => $bookData) {
            DB::beginTransaction();

            try {
                // پیدا کردن دسته‌بندی و ناشر
                $category = Category::where('name', $bookData['category_name'])->first();
                $publisher = Publisher::where('name', $bookData['publisher_name'])->first();

                if (!$category) {
                    throw new \Exception("Category not found: " . $bookData['category_name']);
                }
                if (!$publisher) {
                    throw new \Exception("Publisher not found: " . $bookData['publisher_name']);
                }

                // تولید content_hash
                $content = $bookData['title'] . $bookData['description'] . ($bookData['isbn'] ?? '');
                $contentHash = md5($content);

                // ایجاد کتاب
                $bookRecord = Book::create([
                    'title' => $bookData['title'],
                    'description' => $bookData['description'],
                    'excerpt' => $bookData['excerpt'],
                    'publication_year' => $bookData['publication_year'],
                    'pages_count' => $bookData['pages_count'],
                    'isbn' => $bookData['isbn'],
                    'language' => $bookData['language'],
                    'format' => $bookData['format'],
                    'file_size' => $bookData['file_size'],
                    'content_hash' => $contentHash,
                    'slug' => Str::slug($bookData['title']),
                    'category_id' => $category->id,
                    'publisher_id' => $publisher->id,
                    'status' => 'active',
                    'downloads_count' => rand(50, 5000)
                ]);

                if (!$bookRecord || !$bookRecord->id) {
                    throw new \Exception("Failed to create book: " . $bookData['title']);
                }

                echo "ایجاد کتاب با شناسه: " . $bookRecord->id . " - " . $bookRecord->title . "\n";

                // اضافه کردن نویسندگان
                foreach ($bookData['authors'] as $authorName) {
                    $author = Author::where('name', $authorName)->first();
                    if ($author) {
                        DB::table('book_author')->insert([
                            'book_id' => $bookRecord->id,
                            'author_id' => $author->id,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);
                    }
                }

                // اضافه کردن منابع LGRS با ساختار جدید
                if (!empty($bookData['lgrs_ids'])) {
                    $priority = 1;
                    foreach ($bookData['lgrs_ids'] as $lgrsId) {
                        BookSource::create([
                            'book_id' => $bookRecord->id,
                            'source_type' => 'lgrs',
                            'source_id' => $lgrsId,
                            'source_url' => null,
                            'source_updated_at' => now(),
                            'is_active' => true,
                            'priority' => $priority,
                        ]);
                        $priority++;
                    }
                }

                // اضافه کردن تصویر
                BookImage::create([
                    'book_id' => $bookRecord->id,
                    'image_url' => 'https://via.placeholder.com/300x400.png?text=' . urlencode($bookRecord->title)
                ]);

                // اضافه کردن hash ها
                BookHash::create([
                    'book_id' => $bookRecord->id,
                    'book_hash' => $contentHash,
                    'md5' => $contentHash,
                    'sha1' => sha1($content),
                    'sha256' => hash('sha256', $content),
                    'crc32' => sprintf('%08x', crc32($content)),
                    'btih' => sha1('btih_' . $content)
                ]);

                DB::commit();

            } catch (\Exception $e) {
                DB::rollBack();
                echo "خطا در ایجاد کتاب " . ($index + 1) . ": " . $e->getMessage() . "\n";
                // ادامه به کتاب بعدی به جای توقف کامل
                continue;
            }
        }

        // به‌روزرسانی شمارنده‌ها
        $this->updateCounters();
    }

    private function updateCounters(): void
    {
        try {
            Category::all()->each(function($category) {
                $category->update(['books_count' => Book::where('category_id', $category->id)->count()]);
            });

            Author::all()->each(function($author) {
                $count = DB::table('book_author')->where('author_id', $author->id)->count();
                $author->update(['books_count' => $count]);
            });

            Publisher::all()->each(function($publisher) {
                $publisher->update(['books_count' => Book::where('publisher_id', $publisher->id)->count()]);
            });
        } catch (\Exception $e) {
            echo "هشدار: خطا در به‌روزرسانی شمارنده‌ها: " . $e->getMessage() . "\n";
        }
    }
}
