<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\Config;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * کامند پاکسازی کتاب‌های تکراری و بازسازی hash ها
 */
class CleanupDuplicatesCommand extends Command
{
    protected $signature = 'books:cleanup-duplicates
                            {--config= : شناسه کانفیگ خاص}
                            {--dry-run : فقط نمایش بدون تغییر}
                            {--rebuild-hash : بازسازی همه hash ها}';

    protected $description = 'پاکسازی کتاب‌های تکراری و بازسازی hash های content';

    public function handle(): int
    {
        $this->info('🧹 شروع پاکسازی کتاب‌های تکراری...');
        $this->newLine();

        $dryRun = $this->option('dry-run');
        $rebuildHash = $this->option('rebuild-hash');
        $configId = $this->option('config');

        if ($dryRun) {
            $this->warn('⚠️  حالت Dry Run - هیچ تغییری اعمال نخواهد شد');
            $this->newLine();
        }

        // بازسازی hash ها اگر درخواست شده
        if ($rebuildHash) {
            $this->rebuildContentHashes($dryRun, $configId);
        }

        // یافتن و پاکسازی تکراری‌ها
        $this->findAndCleanDuplicates($dryRun, $configId);

        return Command::SUCCESS;
    }

    /**
     * بازسازی content hash برای کتاب‌ها
     */
    private function rebuildContentHashes(bool $dryRun, ?string $configId): void
    {
        $this->info('🔧 بازسازی content hash ها...');

        $query = Book::query();
        if ($configId) {
            // محدود کردن به کتاب‌هایی که از کانفیگ خاص آمده‌اند
            // (بر اساس book_sources یا روش دیگری که داشته باشید)
            $this->info("محدود شده به کانفیگ: {$configId}");
        }

        $books = $query->get();
        $updated = 0;

        $progressBar = $this->output->createProgressBar($books->count());
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% -- %message%');
        $progressBar->setMessage('آماده‌سازی...');
        $progressBar->start();

        foreach ($books as $book) {
            $progressBar->setMessage("پردازش: {$book->title}");

            // محاسبه hash جدید
            $hashComponents = [
                'title' => trim(strtolower($book->title)),
                'author' => trim(strtolower($book->authors->pluck('name')->implode(', '))),
                'isbn' => preg_replace('/[^0-9X]/', '', $book->isbn ?? ''),
                'publication_year' => $book->publication_year ?? '',
            ];

            // اضافه کردن شناسه کانفیگ اگر مشخص شده
            if ($configId) {
                $hashComponents['config_id'] = $configId;
            }

            $newHash = md5(implode('|', array_filter($hashComponents)));

            if ($book->content_hash !== $newHash) {
                if (!$dryRun) {
                    $book->update(['content_hash' => $newHash]);
                    // به‌روزرسانی book_hashes نیز
                    $book->hashes()->update(['book_hash' => $newHash, 'md5' => $newHash]);
                }
                $updated++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $action = $dryRun ? 'نیاز به به‌روزرسانی دارند' : 'به‌روزرسانی شدند';
        $this->info("✅ {$updated} کتاب {$action}");
        $this->newLine();
    }

    /**
     * یافتن و پاکسازی کتاب‌های تکراری
     */
    private function findAndCleanDuplicates(bool $dryRun, ?string $configId): void
    {
        $this->info('🔍 جستجوی کتاب‌های تکراری...');

        // روش 1: تکراری بر اساس content_hash
        $this->findHashDuplicates($dryRun, $configId);

        // روش 2: تکراری بر اساس عنوان + نویسنده
        $this->findTitleAuthorDuplicates($dryRun, $configId);

        // روش 3: تکراری بر اساس ISBN
        $this->findIsbnDuplicates($dryRun, $configId);
    }

    /**
     * یافتن تکراری‌های hash
     */
    private function findHashDuplicates(bool $dryRun, ?string $configId): void
    {
        $this->info('📄 بررسی تکراری‌های content_hash...');

        $duplicateHashes = DB::table('books')
            ->select('content_hash', DB::raw('COUNT(*) as count'))
            ->whereNotNull('content_hash')
            ->where('content_hash', '!=', '')
            ->groupBy('content_hash')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicateHashes->isEmpty()) {
            $this->info('✅ هیچ تکراری بر اساس hash یافت نشد');
            return;
        }

        $this->warn("⚠️  {$duplicateHashes->count()} hash تکراری یافت شد");

        $totalRemoved = 0;

        foreach ($duplicateHashes as $duplicate) {
            $books = Book::where('content_hash', $duplicate->content_hash)
                ->orderBy('created_at')
                ->get();

            $this->line("Hash: {$duplicate->content_hash} ({$duplicate->count} کتاب)");

            // نگه داشتن اولین کتاب، حذف بقیه
            $keepBook = $books->first();
            $duplicateBooks = $books->skip(1);

            foreach ($duplicateBooks as $book) {
                $this->line("  - حذف: {$book->title} (ID: {$book->id})");

                if (!$dryRun) {
                    $this->removeBookSafely($book);
                }
                $totalRemoved++;
            }

            $this->line("  ✅ نگه‌داری: {$keepBook->title} (ID: {$keepBook->id})");
            $this->newLine();
        }

        $action = $dryRun ? 'برای حذف شناسایی شدند' : 'حذف شدند';
        $this->info("🗑️  {$totalRemoved} کتاب تکراری {$action}");
        $this->newLine();
    }

    /**
     * یافتن تکراری‌های عنوان + نویسنده
     */
    private function findTitleAuthorDuplicates(bool $dryRun, ?string $configId): void
    {
        $this->info('📚 بررسی تکراری‌های عنوان + نویسنده...');

        // یافتن کتاب‌هایی با عنوان مشابه
        $duplicateTitles = DB::table('books')
            ->select('title', DB::raw('COUNT(*) as count'))
            ->groupBy('title')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicateTitles->isEmpty()) {
            $this->info('✅ هیچ تکراری بر اساس عنوان یافت نشد');
            return;
        }

        $totalRemoved = 0;

        foreach ($duplicateTitles as $duplicate) {
            $books = Book::where('title', $duplicate->title)
                ->with('authors')
                ->orderBy('created_at')
                ->get();

            // گروه‌بندی بر اساس نویسندگان
            $groupedByAuthor = $books->groupBy(function ($book) {
                return $book->authors->pluck('name')->sort()->implode('|');
            });

            foreach ($groupedByAuthor as $authorKey => $authorBooks) {
                if ($authorBooks->count() > 1) {
                    $this->line("عنوان: {$duplicate->title}");
                    $this->line("نویسنده: {$authorKey}");

                    $keepBook = $authorBooks->first();
                    $duplicateBooks = $authorBooks->skip(1);

                    foreach ($duplicateBooks as $book) {
                        $this->line("  - حذف: ID {$book->id}");

                        if (!$dryRun) {
                            $this->removeBookSafely($book);
                        }
                        $totalRemoved++;
                    }

                    $this->line("  ✅ نگه‌داری: ID {$keepBook->id}");
                    $this->newLine();
                }
            }
        }

        $action = $dryRun ? 'برای حذف شناسایی شدند' : 'حذف شدند';
        $this->info("🗑️  {$totalRemoved} کتاب تکراری اضافی {$action}");
        $this->newLine();
    }

    /**
     * یافتن تکراری‌های ISBN
     */
    private function findIsbnDuplicates(bool $dryRun, ?string $configId): void
    {
        $this->info('📖 بررسی تکراری‌های ISBN...');

        $duplicateIsbns = DB::table('books')
            ->select('isbn', DB::raw('COUNT(*) as count'))
            ->whereNotNull('isbn')
            ->where('isbn', '!=', '')
            ->groupBy('isbn')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicateIsbns->isEmpty()) {
            $this->info('✅ هیچ تکراری بر اساس ISBN یافت نشد');
            return;
        }

        $totalRemoved = 0;

        foreach ($duplicateIsbns as $duplicate) {
            $books = Book::where('isbn', $duplicate->isbn)
                ->orderBy('created_at')
                ->get();

            $this->line("ISBN: {$duplicate->isbn} ({$duplicate->count} کتاب)");

            // نگه داشتن اولین کتاب
            $keepBook = $books->first();
            $duplicateBooks = $books->skip(1);

            foreach ($duplicateBooks as $book) {
                $this->line("  - حذف: {$book->title} (ID: {$book->id})");

                if (!$dryRun) {
                    $this->removeBookSafely($book);
                }
                $totalRemoved++;
            }

            $this->line("  ✅ نگه‌داری: {$keepBook->title} (ID: {$keepBook->id})");
            $this->newLine();
        }

        $action = $dryRun ? 'برای حذف شناسایی شدند' : 'حذف شدند';
        $this->info("🗑️  {$totalRemoved} کتاب تکراری {$action}");
        $this->newLine();
    }

    /**
     * حذف ایمن کتاب با تمام روابط
     */
    private function removeBookSafely(Book $book): void
    {
        try {
            DB::beginTransaction();

            // حذف روابط
            $book->authors()->detach();
            $book->images()->delete();
            $book->hashes()->delete();
            $book->sources()->delete();

            // حذف کتاب
            $book->delete();

            DB::commit();

            Log::info("کتاب تکراری حذف شد", [
                'book_id' => $book->id,
                'title' => $book->title
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("خطا در حذف کتاب تکراری", [
                'book_id' => $book->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * نمایش آمار نهایی
     */
    private function showFinalStats(): void
    {
        $this->newLine();
        $this->info('📊 آمار نهایی:');

        $totalBooks = Book::count();
        $uniqueHashes = Book::whereNotNull('content_hash')->distinct('content_hash')->count();
        $duplicateHashes = $totalBooks - $uniqueHashes;

        $this->table(
            ['معیار', 'تعداد'],
            [
                ['کل کتاب‌ها', number_format($totalBooks)],
                ['Hash های یکتا', number_format($uniqueHashes)],
                ['تکراری احتمالی', number_format($duplicateHashes)],
                ['درصد یکتایی', round(($uniqueHashes / max($totalBooks, 1)) * 100, 2) . '%']
            ]
        );
    }
}
