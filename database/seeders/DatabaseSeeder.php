<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Category;
use App\Models\Author;
use App\Models\Publisher;
use App\Models\Book;
use App\Models\BookSource;
use App\Models\BookImage;
use App\Models\BookHash;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            ContentSeeder::class,
        ]);
    }
}

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // کاربر ادمین
        User::create([
            'name' => 'مدیر سیستم',
            'email' => 'admin@bookstore.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        // کاربر عادی
        User::create([
            'name' => 'کاربر تست',
            'email' => 'user@bookstore.com',
            'password' => Hash::make('password'),
            'role' => 'user',
            'email_verified_at' => now(),
        ]);
    }
}

class ContentSeeder extends Seeder
{
    public function run(): void
    {
        // ایجاد دسته‌بندی‌ها
        $categories = $this->createCategories();

        // ایجاد نویسندگان
        $authors = $this->createAuthors();

        // ایجاد ناشران
        $publishers = $this->createPublishers();

        // ایجاد کتاب‌های نمونه
        $this->createBooks($categories, $authors, $publishers);
    }

    private function createCategories(): array
    {
        $categoryNames = [
            'ادبیات فارسی', 'ادبیات جهان', 'تاریخ', 'فلسفه', 'علوم اجتماعی',
            'کامپیوتر و IT', 'ریاضی و فیزیک', 'روانشناسی', 'اقتصاد', 'هنر و معماری'
        ];

        $categories = [];
        foreach ($categoryNames as $name) {
            $categories[] = Category::create([
                'name' => $name,
                'slug' => Str::slug($name),
                'books_count' => 0
            ]);
        }

        return $categories;
    }

    private function createAuthors(): array
    {
        $authorNames = [
            'فردوسی', 'حافظ شیرازی', 'سعدی شیرازی', 'مولوی', 'صادق هدایت',
            'احمد شاملو', 'فروغ فرخزاد', 'جورج اورول', 'احمد محمدی', 'رضا امیری'
        ];

        $authors = [];
        foreach ($authorNames as $name) {
            $authors[] = Author::create([
                'name' => $name,
                'slug' => Str::slug($name),
                'books_count' => 0
            ]);
        }

        return $authors;
    }

    private function createPublishers(): array
    {
        $publisherNames = [
            'انتشارات نشر نو', 'انتشارات امیرکبیر', 'انتشارات علمی و فرهنگی',
            'انتشارات سخن', 'انتشارات جهان رایانه'
        ];

        $publishers = [];
        foreach ($publisherNames as $name) {
            $publishers[] = Publisher::create([
                'name' => $name,
                'slug' => Str::slug($name),
                'books_count' => 0
            ]);
        }

        return $publishers;
    }

    private function createBooks(array $categories, array $authors, array $publishers): void
    {
        $booksData = [
            [
                'title' => 'شاهنامه فردوسی',
                'description' => 'حماسه ملی ایران که داستان پهلوانان و شاهان ایران باستان را روایت می‌کند.',
                'category_index' => 0, // ادبیات فارسی
                'author_index' => 0,   // فردوسی
                'publisher_index' => 0,
                'isbn' => '978-964-123-456-7',
                'publication_year' => 2020,
                'pages_count' => 3000,
                'language' => 'fa',
                'format' => 'pdf',
                'file_size' => 25600000,
            ],
            [
                'title' => 'دیوان حافظ',
                'description' => 'مجموعه غزل‌های خواجه شمس‌الدین محمد حافظ شیرازی',
                'category_index' => 0, // ادبیات فارسی
                'author_index' => 1,   // حافظ شیرازی
                'publisher_index' => 1,
                'isbn' => '978-964-123-456-8',
                'publication_year' => 2019,
                'pages_count' => 500,
                'language' => 'fa',
                'format' => 'epub',
                'file_size' => 5120000,
            ],
            [
                'title' => 'آموزش برنامه‌نویسی PHP',
                'description' => 'راهنمای کامل یادگیری PHP',
                'category_index' => 5, // کامپیوتر و IT
                'author_index' => 8,   // احمد محمدی
                'publisher_index' => 4,
                'isbn' => '978-964-123-457-1',
                'publication_year' => 2023,
                'pages_count' => 450,
                'language' => 'fa',
                'format' => 'pdf',
                'file_size' => 15728640,
            ]
        ];

        foreach ($booksData as $bookData) {
            DB::transaction(function () use ($bookData, $categories, $authors, $publishers) {
                $contentHash = md5($bookData['title'] . $bookData['description'] . $bookData['isbn']);

                $book = Book::create([
                    'title' => $bookData['title'],
                    'description' => $bookData['description'],
                    'excerpt' => Str::limit($bookData['description'], 200),
                    'slug' => Str::slug($bookData['title'] . '_' . time()),
                    'isbn' => $bookData['isbn'],
                    'publication_year' => $bookData['publication_year'],
                    'pages_count' => $bookData['pages_count'],
                    'language' => $bookData['language'],
                    'format' => $bookData['format'],
                    'file_size' => $bookData['file_size'],
                    'category_id' => $categories[$bookData['category_index']]->id,
                    'publisher_id' => $publishers[$bookData['publisher_index']]->id,
                    'downloads_count' => rand(50, 5000),
                    'status' => 'active'
                ]);

                // اضافه کردن نویسنده
                $book->authors()->attach($authors[$bookData['author_index']]->id);

                // اضافه کردن hash
                BookHash::create([
                    'book_id' => $book->id,
                    'md5' => $contentHash,
                    'sha1' => sha1($bookData['title'] . $bookData['description']),
                    'sha256' => hash('sha256', $bookData['title'] . $bookData['description']),
                ]);

                // اضافه کردن تصویر
                BookImage::create([
                    'book_id' => $book->id,
                    'image_url' => 'https://via.placeholder.com/300x400.png?text=' . urlencode($book->title)
                ]);

                // اضافه کردن منبع
                BookSource::create([
                    'book_id' => $book->id,
                    'source_name' => 'sample_source',
                    'source_id' => (string)(1000 + $book->id),
                    'discovered_at' => now()
                ]);
            });
        }

        // بروزرسانی شمارنده‌ها
        $this->updateCounters($categories, $authors, $publishers);
    }

    private function updateCounters(array $categories, array $authors, array $publishers): void
    {
        foreach ($categories as $category) {
            $category->update(['books_count' => $category->books()->count()]);
        }

        foreach ($authors as $author) {
            $author->update(['books_count' => $author->books()->count()]);
        }

        foreach ($publishers as $publisher) {
            $publisher->update(['books_count' => $publisher->books()->count()]);
        }
    }
}
