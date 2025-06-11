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

class UpdateExistingBooksCommand extends Command
{
    protected $signature = 'books:update-existing
                          {config : ID کانفیگ برای آپدیت}
                          {--source-ids= : لیست source_id های خاص (جدا شده با کاما)}
                          {--limit=100 : تعداد محدود کتاب‌ها برای آپدیت}
                          {--force : اجرای اجباری بدون تأیید}
                          {--only-empty : فقط کتاب‌هایی که فیلدهای خالی دارند}
                          {--debug : نمایش اطلاعات تشخیصی}';

    protected $description = 'آپدیت هوشمند کتاب‌های موجود با داده‌های جدید از API';

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
            // تأیید از کاربر
            if (!$this->confirmOperation($config)) {
                $this->info("عملیات لغو شد.");
                return Command::SUCCESS;
            }

            // دریافت لیست کتاب‌ها برای آپدیت
            $booksToUpdate = $this->getBooksToUpdate($config);

            if ($booksToUpdate->isEmpty()) {
                $this->info("✅ هیچ کتابی برای آپدیت یافت نشد!");
                return Command::SUCCESS;
            }

            $this->info("📚 تعداد کتاب‌های یافت شده برای آپدیت: " . $booksToUpdate->count());

            // ایجاد execution log
            $executionLog = $this->statsTracker->createExecutionLog($config);

            // شروع فرآیند آپدیت
            $this->performBooksUpdate($config, $booksToUpdate, $executionLog);

            // نمایش خلاصه نهایی
            $this->statsTracker->displayFinalSummary();

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ خطا در آپدیت کتاب‌ها: " . $e->getMessage());
            Log::error("خطا در UpdateExistingBooksCommand", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }

    private function displayWelcomeMessage(): void
    {
        $this->info("🔄 آپدیت هوشمند کتاب‌های موجود");
        $this->info("⏰ زمان شروع: " . now()->format('Y-m-d H:i:s'));
        $this->newLine();

        if ($this->option('debug')) {
            $this->line("🧠 ویژگی‌های آپدیت هوشمند:");
            $this->line("   ✨ تشخیص فیلدهای خالی و پر کردن آنها");
            $this->line("   🔄 بهبود فیلدهای ناقص (توضیحات، تعداد صفحات، ...)");
            $this->line("   📚 اضافه کردن نویسندگان و ISBN های جدید");
            $this->line("   🔐 تکمیل هش‌های مفقود");
            $this->line("   🖼️ اضافه کردن تصاویر جدید");
            $this->line("   📊 حفظ داده‌های موجود بهتر");
            $this->newLine();
        }
    }

    private function confirmOperation(Config $config): bool
    {
        if ($this->option('force')) {
            return true;
        }

        $this->warn("⚠️ این عملیات کتاب‌های موجود را آپدیت خواهد کرد!");
        $this->line("کانفیگ: {$config->name}");
        $this->line("منبع: {$config->source_name}");
        $this->line("محدودیت: " . $this->option('limit') . " کتاب");

        if ($this->option('only-empty')) {
            $this->line("حالت: فقط کتاب‌های با فیلدهای خالی");
        }

        if ($this->option('source-ids')) {
            $sourceIds = explode(',', $this->option('source-ids'));
            $this->line("Source IDs خاص: " . implode(', ', $sourceIds));
        }

        return $this->confirm('آیا می‌خواهید ادامه دهید؟');
    }

    private function getBooksToUpdate(Config $config)
    {
        $query = Book::query()
            ->whereHas('sources', function ($q) use ($config) {
                $q->where('source_name', $config->source_name);
            })
            ->with(['sources', 'authors', 'hashes', 'images', 'category', 'publisher']);

        // اگر source_id های خاص مشخص شده
        if ($this->option('source-ids')) {
            $sourceIds = array_map('trim', explode(',', $this->option('source-ids')));
            $query->whereHas('sources', function ($q) use ($config, $sourceIds) {
                $q->where('source_name', $config->source_name)
                    ->whereIn('source_id', $sourceIds);
            });
        }

        // اگر فقط کتاب‌های با فیلدهای خالی
        if ($this->option('only-empty')) {
            $query->where(function ($q) {
                $q->whereNull('description')
                    ->orWhereNull('publication_year')
                    ->orWhereNull('pages_count')
                    ->orWhereNull('file_size')
                    ->orWhereNull('publisher_id')
                    ->orWhere('description', '')
                    ->orWhere('isbn', '')
                    ->orWhere('isbn', null);
            });
        }

        $query->limit($this->option('limit'));

        if ($this->option('debug')) {
            $this->line("🔍 Query شرایط:");
            $this->line("   • منبع: {$config->source_name}");
            $this->line("   • محدودیت: " . $this->option('limit'));
            $this->line("   • فقط خالی: " . ($this->option('only-empty') ? 'بله' : 'خیر'));
        }

        return $query->get();
    }

    private function performBooksUpdate(Config $config, $books, ExecutionLog $executionLog): void
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
                    continue;
                }

                $sourceId = (int)$bookSource->source_id;
                $progressBar->setMessage($book->title ? Str::limit($book->title, 30) : "ID: {$book->id}");

                if ($this->option('debug')) {
                    $this->newLine();
                    $this->line("🔄 پردازش کتاب ID: {$book->id}, Source ID: {$sourceId}");
                    $this->line("   عنوان: " . Str::limit($book->title, 50));
                }

                // پردازش کتاب با API
                $result = $apiService->processSourceId($sourceId, $executionLog);

                if ($result && isset($result['action'])) {
                    $this->statsTracker->updateStats($result);

                    switch ($result['action']) {
                        case 'enhanced':
                        case 'enriched':
                        case 'merged':
                            $currentStats['enhanced']++;
                            if ($this->option('debug')) {
                                $this->line("   ✅ آپدیت شد: " . $result['action']);
                            }
                            break;
                        case 'no_changes':
                        case 'already_processed':
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
                $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% | کتاب: %message% | ✅:' . $currentStats['enhanced'] . ' 📋:' . $currentStats['unchanged'] . ' ❌:' . $currentStats['failed']);

                $progressBar->advance();
                $processedCount++;

                // نمایش پیشرفت هر 10 کتاب
                if ($processedCount % 10 === 0) {
                    $this->displayIntermediateProgress($processedCount, $currentStats);
                }

                // تاخیر کوتاه
                usleep(500000); // 0.5 ثانیه

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
        $this->info("🎉 آپدیت کتاب‌ها تمام شد!");
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
