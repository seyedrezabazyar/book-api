<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Config;
use App\Models\Book;
use App\Models\BookSource;
use App\Models\ExecutionLog;
use App\Services\ApiDataService;
use App\Services\CommandStatsTracker;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class BatchUpdateExistingBooksCommand extends Command
{
    protected $signature = 'books:batch-update
                          {config : ID کانفیگ برای آپدیت}
                          {--source-ids= : محدوده source_id های خاص (مثال: 1-100 یا 1,5,10)}
                          {--limit=500 : تعداد محدود کتاب‌ها برای آپدیت}
                          {--min-update-score=1 : حداقل امتیاز نیاز به آپدیت (1-10)}
                          {--only-empty : فقط کتاب‌هایی که فیلدهای مهم خالی دارند}
                          {--only-incomplete : فقط کتاب‌هایی که ناقص هستند}
                          {--force : آپدیت اجباری بدون تأیید}
                          {--dry-run : فقط نمایش کتاب‌هایی که نیاز به آپدیت دارند}
                          {--debug : نمایش اطلاعات تشخیصی}';

    protected $description = 'آپدیت دسته‌ای کتاب‌های موجود بر اساس فیلتر مشخص';

    private CommandStatsTracker $statsTracker;

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->displayWelcomeMessage();

        $configId = $this->argument('config');
        $config = Config::find($configId);

        if (!$config) {
            $this->error("❌ کانفیگ با ID {$configId} یافت نشد!");
            return Command::FAILURE;
        }

        $this->statsTracker = new CommandStatsTracker($this);

        try {
            // تحلیل کتاب‌های نیازمند آپدیت
            $analysisResult = $this->analyzeExistingBooks($config);

            if ($analysisResult['total_candidates'] === 0) {
                $this->info("✅ هیچ کتابی برای آپدیت یافت نشد!");
                return Command::SUCCESS;
            }

            $this->displayAnalysisResults($analysisResult);

            // در حالت dry-run فقط نتایج را نمایش می‌دهیم
            if ($this->option('dry-run')) {
                $this->info("🔍 حالت Dry-Run: هیچ تغییری انجام نمی‌شود");
                $this->displaySampleBooks($analysisResult['sample_books']);
                return Command::SUCCESS;
            }

            // تأیید از کاربر
            if (!$this->confirmOperation($config, $analysisResult)) {
                $this->info("عملیات لغو شد.");
                return Command::SUCCESS;
            }

            // دریافت لیست کتاب‌ها برای آپدیت
            $booksToUpdate = $this->getBooksToUpdate($config, $analysisResult);

            // ایجاد execution log
            $executionLog = $this->statsTracker->createExecutionLog($config);

            // شروع فرآیند آپدیت
            $this->performBatchUpdate($config, $booksToUpdate, $executionLog);

            // نمایش خلاصه نهایی
            $this->statsTracker->displayFinalSummary();

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ خطا در آپدیت دسته‌ای: " . $e->getMessage());
            Log::error("خطا در BatchUpdateExistingBooksCommand", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }

    private function displayWelcomeMessage(): void
    {
        $this->info("🔄 آپدیت دسته‌ای کتاب‌های موجود");
        $this->info("⏰ زمان شروع: " . now()->format('Y-m-d H:i:s'));
        $this->newLine();

        // نمایش تنظیمات فعال
        $activeFilters = [];
        if ($this->option('only-empty')) $activeFilters[] = "Only Empty Fields";
        if ($this->option('only-incomplete')) $activeFilters[] = "Only Incomplete";
        if ($this->option('source-ids')) $activeFilters[] = "Source IDs: " . $this->option('source-ids');
        if ($this->option('min-update-score') != 1) $activeFilters[] = "Min Score: " . $this->option('min-update-score');

        if (!empty($activeFilters)) {
            $this->info("🎯 فیلترهای فعال: " . implode(', ', $activeFilters));
        }

        if ($this->option('dry-run')) {
            $this->warn("🔍 حالت Dry-Run فعال است - هیچ تغییری انجام نخواهد شد");
        }

        $this->newLine();
    }

    /**
     * تحلیل کتاب‌های موجود که نیاز به آپدیت دارند
     */
    private function analyzeExistingBooks(Config $config): array
    {
        $this->info("🔍 تحلیل کتاب‌های نیازمند آپدیت...");

        $query = $this->buildBooksQuery($config);

        $totalBooks = $query->count();
        $this->info("📚 کل کتاب‌های این منبع: " . number_format($totalBooks));

        if ($totalBooks === 0) {
            return [
                'total_books' => 0,
                'total_candidates' => 0,
                'update_distribution' => [],
                'sample_books' => []
            ];
        }

        // تحلیل نیاز به آپدیت
        $candidates = [];
        $updateDistribution = [
            'score_1' => 0, 'score_2' => 0, 'score_3' => 0, 'score_4' => 0, 'score_5_plus' => 0
        ];

        $sampleBooks = [];
        $minScore = (int)$this->option('min-update-score');

        $progressBar = $this->output->createProgressBar(min($totalBooks, 1000));
        $progressBar->setFormat('تحلیل: %current%/%max% [%bar%] %percent:3s%%');

        // تحلیل نمونه‌ای برای عملکرد بهتر
        $booksToAnalyze = $query->limit(1000)->get();

        foreach ($booksToAnalyze as $book) {
            $updateScore = $this->calculateUpdateScore($book);

            if ($updateScore >= $minScore) {
                $candidates[] = $book->id;

                // توزیع امتیازات
                if ($updateScore >= 5) {
                    $updateDistribution['score_5_plus']++;
                } else {
                    $updateDistribution['score_' . $updateScore]++;
                }

                // نمونه کتاب‌ها برای نمایش
                if (count($sampleBooks) < 10) {
                    $sampleBooks[] = [
                        'id' => $book->id,
                        'title' => $book->title,
                        'score' => $updateScore,
                        'empty_fields' => $this->getEmptyFields($book),
                        'source_count' => $book->sources()->count()
                    ];
                }
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        // اگر تعداد کل کتاب‌ها بیشتر از نمونه بود، تخمین بزنیم
        $candidateRatio = count($candidates) / count($booksToAnalyze);
        $estimatedCandidates = $totalBooks > 1000 ?
            round($totalBooks * $candidateRatio) :
            count($candidates);

        return [
            'total_books' => $totalBooks,
            'analyzed_books' => count($booksToAnalyze),
            'total_candidates' => $estimatedCandidates,
            'actual_candidates' => count($candidates),
            'update_distribution' => $updateDistribution,
            'sample_books' => $sampleBooks,
            'candidate_ratio' => $candidateRatio
        ];
    }

    /**
     * محاسبه امتیاز نیاز به آپدیت
     */
    private function calculateUpdateScore(Book $book): int
    {
        $score = 0;

        // فیلدهای مهم خالی (امتیاز بالا)
        if (empty($book->description)) $score += 2;
        if (empty($book->publication_year)) $score += 1;
        if (empty($book->pages_count)) $score += 1;
        if (empty($book->isbn)) $score += 1;
        if (empty($book->publisher_id)) $score += 1;
        if (empty($book->file_size)) $score += 1;

        // نویسندگان
        if ($book->authors()->count() === 0) $score += 2;

        // هش‌ها
        if (!$book->hashes || empty($book->hashes->md5)) $score += 2;
        if ($book->hashes) {
            if (empty($book->hashes->sha1)) $score += 0.5;
            if (empty($book->hashes->sha256)) $score += 0.5;
            if (empty($book->hashes->btih)) $score += 0.5;
        }

        // دسته‌بندی عمومی
        if ($book->category && $book->category->name === 'عمومی') $score += 1;

        // توضیحات کوتاه
        if (!empty($book->description) && strlen($book->description) < 100) $score += 1;

        // تصاویر
        if ($book->images()->count() === 0) $score += 0.5;

        return min(10, (int)round($score));
    }

    /**
     * پیدا کردن فیلدهای خالی
     */
    private function getEmptyFields(Book $book): array
    {
        $emptyFields = [];

        if (empty($book->description)) $emptyFields[] = 'description';
        if (empty($book->publication_year)) $emptyFields[] = 'publication_year';
        if (empty($book->pages_count)) $emptyFields[] = 'pages_count';
        if (empty($book->isbn)) $emptyFields[] = 'isbn';
        if (empty($book->publisher_id)) $emptyFields[] = 'publisher';
        if (empty($book->file_size)) $emptyFields[] = 'file_size';
        if ($book->authors()->count() === 0) $emptyFields[] = 'authors';
        if (!$book->hashes || empty($book->hashes->md5)) $emptyFields[] = 'md5';

        return $emptyFields;
    }

    /**
     * ساخت query برای یافتن کتاب‌ها
     */
    private function buildBooksQuery(Config $config)
    {
        $query = Book::query()
            ->whereHas('sources', function ($q) use ($config) {
                $q->where('source_name', $config->source_name);
            })
            ->with(['sources', 'authors', 'hashes', 'images', 'category', 'publisher']);

        // فیلتر بر اساس source_ids
        if ($this->option('source-ids')) {
            $sourceIds = $this->parseSourceIds($this->option('source-ids'));
            $query->whereHas('sources', function ($q) use ($config, $sourceIds) {
                $q->where('source_name', $config->source_name)
                    ->whereIn('source_id', $sourceIds);
            });
        }

        // فیلتر فقط کتاب‌های با فیلدهای خالی
        if ($this->option('only-empty')) {
            $query->where(function ($q) {
                $q->whereNull('description')
                    ->orWhereNull('publication_year')
                    ->orWhereNull('pages_count')
                    ->orWhereNull('publisher_id')
                    ->orWhere('description', '')
                    ->orWhere('isbn', '')
                    ->orWhere('isbn', null);
            });
        }

        // فیلتر فقط کتاب‌های ناقص
        if ($this->option('only-incomplete')) {
            $query->where(function ($q) {
                $q->whereDoesntHave('authors')
                    ->orWhereDoesntHave('hashes')
                    ->orWhereHas('category', function ($catQ) {
                        $catQ->where('name', 'عمومی');
                    });
            });
        }

        $query->limit($this->option('limit'));

        return $query;
    }

    /**
     * پارس کردن source IDs
     */
    private function parseSourceIds(string $sourceIds): array
    {
        $ids = [];

        // تقسیم بر اساس کاما
        $parts = explode(',', $sourceIds);

        foreach ($parts as $part) {
            $part = trim($part);

            // بررسی محدوده (مثال: 1-100)
            if (strpos($part, '-') !== false) {
                [$start, $end] = explode('-', $part, 2);
                $start = (int)trim($start);
                $end = (int)trim($end);

                for ($i = $start; $i <= $end; $i++) {
                    $ids[] = (string)$i;
                }
            } else {
                // ID تکی
                $ids[] = $part;
            }
        }

        return array_unique($ids);
    }

    /**
     * نمایش نتایج تحلیل
     */
    private function displayAnalysisResults(array $result): void
    {
        $this->info("📊 نتایج تحلیل:");
        $this->table(['آمار', 'مقدار'], [
            ['کل کتاب‌های این منبع', number_format($result['total_books'])],
            ['تحلیل شده', number_format($result['analyzed_books'])],
            ['نیازمند آپدیت (تخمینی)', number_format($result['total_candidates'])],
            ['نرخ نیاز به آپدیت', round($result['candidate_ratio'] * 100, 1) . '%']
        ]);

        $this->newLine();
        $this->info("📈 توزیع امتیاز آپدیت:");
        foreach ($result['update_distribution'] as $scoreRange => $count) {
            if ($count > 0) {
                $percentage = round(($count / $result['actual_candidates']) * 100, 1);
                $this->line("   • {$scoreRange}: {$count} کتاب ({$percentage}%)");
            }
        }
    }

    /**
     * نمایش نمونه کتاب‌ها
     */
    private function displaySampleBooks(array $sampleBooks): void
    {
        if (empty($sampleBooks)) return;

        $this->newLine();
        $this->info("📖 نمونه کتاب‌های نیازمند آپدیت:");

        $tableData = [];
        foreach ($sampleBooks as $book) {
            $tableData[] = [
                $book['id'],
                \Illuminate\Support\Str::limit($book['title'], 40),
                $book['score'],
                implode(', ', array_slice($book['empty_fields'], 0, 3)),
                $book['source_count']
            ];
        }

        $this->table([
            'ID', 'عنوان', 'امتیاز', 'فیلدهای خالی', 'منابع'
        ], $tableData);
    }

    /**
     * تأیید عملیات از کاربر
     */
    private function confirmOperation(Config $config, array $analysisResult): bool
    {
        if ($this->option('force')) {
            return true;
        }

        $this->newLine();
        $this->warn("⚠️ این عملیات کتاب‌های موجود را آپدیت خواهد کرد!");
        $this->line("کانفیگ: {$config->name}");
        $this->line("منبع: {$config->source_name}");
        $this->line("تعداد کتاب‌های نیازمند آپدیت: " . number_format($analysisResult['total_candidates']));
        $this->line("محدودیت: " . $this->option('limit') . " کتاب");

        if ($this->option('min-update-score') > 1) {
            $this->line("حداقل امتیاز: " . $this->option('min-update-score'));
        }

        return $this->confirm('آیا می‌خواهید ادامه دهید؟');
    }

    /**
     * دریافت کتاب‌های نیازمند آپدیت
     */
    private function getBooksToUpdate(Config $config, array $analysisResult)
    {
        $query = $this->buildBooksQuery($config);
        $minScore = (int)$this->option('min-update-score');

        // اگر تعداد کاندیداهای واقعی کم باشد، همه را برگردان
        if ($analysisResult['actual_candidates'] <= $this->option('limit')) {
            return $query->get()->filter(function ($book) use ($minScore) {
                return $this->calculateUpdateScore($book) >= $minScore;
            });
        }

        // در غیر این صورت، محدود کن
        return $query->get()->filter(function ($book) use ($minScore) {
            return $this->calculateUpdateScore($book) >= $minScore;
        })->take($this->option('limit'));
    }

    /**
     * انجام آپدیت دسته‌ای
     */
    private function performBatchUpdate(Config $config, $books, ExecutionLog $executionLog): void
    {
        $apiService = new ApiDataService($config);
        $processedCount = 0;

        $progressBar = $this->output->createProgressBar($books->count());
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% | کتاب: %message% | ✅:%enhanced% 📋:%unchanged% ❌:%failed%');

        $currentStats = ['enhanced' => 0, 'unchanged' => 0, 'failed' => 0];

        foreach ($books as $book) {
            try {
                $bookSource = $book->sources()
                    ->where('source_name', $config->source_name)
                    ->first();

                if (!$bookSource) {
                    $this->warn("⚠️ منبع برای کتاب {$book->id} یافت نشد");
                    $currentStats['failed']++;
                    continue;
                }

                $sourceId = (int)$bookSource->source_id;
                $progressBar->setMessage($book->title ? \Illuminate\Support\Str::limit($book->title, 30) : "ID: {$book->id}");

                if ($this->option('debug')) {
                    $this->newLine();
                    $this->line("🔄 پردازش کتاب ID: {$book->id}, Source ID: {$sourceId}");
                    $this->line("   عنوان: " . \Illuminate\Support\Str::limit($book->title, 50));
                    $this->line("   امتیاز آپدیت: " . $this->calculateUpdateScore($book));
                }

                // پردازش کتاب با API
                $result = $apiService->processSourceId($sourceId, $executionLog);

                if ($result && isset($result['action'])) {
                    $this->statsTracker->updateStats($result);

                    switch ($result['action']) {
                        case 'enhanced':
                        case 'enriched':
                        case 'merged':
                        case 'reprocess_for_update':
                        case 'force_reprocess':
                            $currentStats['enhanced']++;
                            if ($this->option('debug')) {
                                $this->line("   ✅ آپدیت شد: " . $result['action']);
                            }
                            break;
                        case 'no_changes':
                        case 'already_processed':
                        case 'source_added':
                            $currentStats['unchanged']++;
                            break;
                        default:
                            $currentStats['failed']++;
                            break;
                    }
                } else {
                    $currentStats['failed']++;
                }

                // بروزرسانی progress bar
                $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% | کتاب: %message% | ✅:' .
                    $currentStats['enhanced'] . ' 📋:' . $currentStats['unchanged'] . ' ❌:' . $currentStats['failed']);

                $progressBar->advance();
                $processedCount++;

                // نمایش پیشرفت هر 25 کتاب
                if ($processedCount % 25 === 0) {
                    $this->displayIntermediateProgress($processedCount, $currentStats);
                }

                // تاخیر کوتاه
                usleep(750000); // 0.75 ثانیه

            } catch (\Exception $e) {
                $currentStats['failed']++;
                $this->error("❌ خطا در پردازش کتاب {$book->id}: " . $e->getMessage());

                if ($this->option('debug')) {
                    $this->line("جزئیات خطا: " . $e->getFile() . ':' . $e->getLine());
                }
            }
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->displayFinalResults($processedCount, $currentStats);
        $this->statsTracker->completeConfigExecution($config, $executionLog);
    }

    private function displayIntermediateProgress(int $processed, array $stats): void
    {
        if (!$this->option('debug')) {
            return;
        }

        $this->newLine();
        $this->info("📊 پیشرفت تا کنون:");
        $this->line("   • پردازش شده: {$processed}");
        $this->line("   • آپدیت شده: {$stats['enhanced']}");
        $this->line("   • بدون تغییر: {$stats['unchanged']}");
        $this->line("   • خطا: {$stats['failed']}");

        if ($processed > 0) {
            $successRate = round((($stats['enhanced']) / $processed) * 100, 1);
            $this->line("   • نرخ بهبود: {$successRate}%");
        }
    }

    private function displayFinalResults(int $total, array $stats): void
    {
        $this->info("🎉 آپدیت دسته‌ای تمام شد!");
        $this->line("=" . str_repeat("=", 50));

        $this->info("📊 نتایج نهایی:");
        $this->line("   • کل پردازش شده: " . number_format($total));
        $this->line("   • موفقیت‌آمیز آپدیت شده: " . number_format($stats['enhanced']));
        $this->line("   • بدون نیاز به تغییر: " . number_format($stats['unchanged']));
        $this->line("   • ناموفق: " . number_format($stats['failed']));

        if ($total > 0) {
            $successRate = round(($stats['enhanced'] / $total) * 100, 1);
            $this->line("   • نرخ بهبود: {$successRate}%");
        }

        $this->newLine();
        $this->info("✨ تمام کتاب‌های انتخاب شده بررسی و در صورت نیاز آپدیت شدند!");
    }
}
