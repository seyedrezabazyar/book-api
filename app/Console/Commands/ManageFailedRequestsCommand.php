<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\FailedRequest;
use App\Models\Config;
use App\Services\ApiDataService;
use App\Models\ExecutionLog;

class ManageFailedRequestsCommand extends Command
{
    protected $signature = 'failed-requests:manage
                          {action : نوع عملیات (stats|retry|cleanup|list)}
                          {--source= : نام منبع برای فیلتر}
                          {--config= : ID کانفیگ برای فیلتر}
                          {--limit=50 : تعداد محدود نتایج}
                          {--force : اجرای اجباری عملیات}
                          {--days=30 : تعداد روز برای cleanup}';

    protected $description = 'مدیریت و بازیابی درخواست‌های ناموفق';

    public function handle(): int
    {
        $action = $this->argument('action');

        $this->info("🔧 مدیریت درخواست‌های ناموفق - عملیات: {$action}");

        switch ($action) {
            case 'stats':
                return $this->showStats();

            case 'list':
                return $this->listFailedRequests();

            case 'retry':
                return $this->retryFailedRequests();

            case 'cleanup':
                return $this->cleanupOldRequests();

            default:
                $this->error("❌ عملیات نامعتبر: {$action}");
                $this->line("عملیات‌های معتبر: stats, list, retry, cleanup");
                return Command::FAILURE;
        }
    }

    private function showStats(): int
    {
        $this->info("📊 آمار درخواست‌های ناموفق:");

        // آمار کلی
        $totalFailed = FailedRequest::count();
        $unresolvedCount = FailedRequest::unresolved()->count();
        $needsRetryCount = FailedRequest::needsRetry()->count();
        $maxRetriesReached = FailedRequest::where('retry_count', '>=', FailedRequest::MAX_RETRY_COUNT)
            ->where('is_resolved', false)
            ->count();

        $this->table(['آمار', 'تعداد'], [
            ['کل درخواست‌های ناموفق', number_format($totalFailed)],
            ['حل نشده', number_format($unresolvedCount)],
            ['نیاز به تلاش مجدد', number_format($needsRetryCount)],
            ['حداکثر تلاش رسیده', number_format($maxRetriesReached)]
        ]);

        // آمار بر اساس منبع
        if ($sourceName = $this->option('source')) {
            $sourceStats = FailedRequest::getSourceStats($sourceName);
            $this->newLine();
            $this->info("📈 آمار منبع: {$sourceName}");
            $this->table(['آمار منبع', 'مقدار'], [
                ['کل ناموفق', number_format($sourceStats['total_failed'])],
                ['حل شده', number_format($sourceStats['resolved_count'])],
                ['حل نشده', number_format($sourceStats['unresolved_count'])],
                ['حداکثر تلاش رسیده', number_format($sourceStats['max_retries_reached'])],
                ['نرخ حل شدن', $sourceStats['retry_rate'] . '%']
            ]);
        } else {
            // نمایش آمار تمام منابع
            $sourceStats = FailedRequest::select('source_name')
                ->selectRaw('COUNT(*) as total, COUNT(CASE WHEN is_resolved = 1 THEN 1 END) as resolved')
                ->groupBy('source_name')
                ->orderBy('total', 'desc')
                ->limit(10)
                ->get();

            if ($sourceStats->count() > 0) {
                $this->newLine();
                $this->info("📋 آمار برترین منابع:");
                $tableData = [];
                foreach ($sourceStats as $stat) {
                    $resolveRate = $stat->total > 0 ? round(($stat->resolved / $stat->total) * 100, 1) : 0;
                    $tableData[] = [
                        $stat->source_name,
                        number_format($stat->total),
                        number_format($stat->resolved),
                        $resolveRate . '%'
                    ];
                }
                $this->table(['منبع', 'کل ناموفق', 'حل شده', 'نرخ حل'], $tableData);
            }
        }

        return Command::SUCCESS;
    }

    private function listFailedRequests(): int
    {
        $limit = (int)$this->option('limit');
        $sourceName = $this->option('source');
        $configId = $this->option('config');

        $query = FailedRequest::query()->with('config');

        if ($sourceName) {
            $query->where('source_name', $sourceName);
        }

        if ($configId) {
            $query->where('config_id', $configId);
        }

        // فقط حل نشده‌ها
        $query->unresolved();

        $failedRequests = $query->orderBy('first_failed_at', 'desc')
            ->limit($limit)
            ->get();

        if ($failedRequests->isEmpty()) {
            $this->info("✅ هیچ درخواست ناموفق حل نشده‌ای یافت نشد!");
            return Command::SUCCESS;
        }

        $this->info("📋 لیست درخواست‌های ناموفق (آخرین {$limit} مورد):");

        $tableData = [];
        foreach ($failedRequests as $request) {
            $tableData[] = [
                $request->source_name,
                $request->source_id,
                $request->retry_count . '/' . FailedRequest::MAX_RETRY_COUNT,
                $request->shouldRetry() ? '✅' : '❌',
                Str::limit($request->error_message, 40),
                $request->first_failed_at->diffForHumans()
            ];
        }

        $this->table([
            'منبع', 'Source ID', 'تلاش', 'قابل تلاش؟', 'خطا', 'اولین خطا'
        ], $tableData);

        return Command::SUCCESS;
    }

    private function retryFailedRequests(): int
    {
        $sourceName = $this->option('source');
        $configId = $this->option('config');
        $limit = (int)$this->option('limit');
        $force = $this->option('force');

        if (!$sourceName && !$configId) {
            $this->error("❌ برای retry باید --source یا --config مشخص کنید");
            return Command::FAILURE;
        }

        // پیدا کردن کانفیگ
        $config = null;
        if ($configId) {
            $config = Config::find($configId);
            if (!$config) {
                $this->error("❌ کانفیگ با ID {$configId} یافت نشد!");
                return Command::FAILURE;
            }
            $sourceName = $config->source_name;
        } else {
            // پیدا کردن کانفیگ بر اساس source name
            $config = Config::where('source_name', $sourceName)->first();
            if (!$config) {
                $this->error("❌ کانفیگی با منبع {$sourceName} یافت نشد!");
                return Command::FAILURE;
            }
        }

        // پیدا کردن failed requests قابل retry
        $failedRequests = FailedRequest::where('source_name', $sourceName)
            ->needsRetry()
            ->orderBy('first_failed_at')
            ->limit($limit)
            ->get();

        if ($failedRequests->isEmpty()) {
            $this->info("✅ هیچ درخواست ناموفقی برای تلاش مجدد یافت نشد!");
            return Command::SUCCESS;
        }

        $this->info("🔄 یافت شد: {$failedRequests->count()} درخواست برای تلاش مجدد");

        if (!$force && !$this->confirm("آیا می‌خواهید تلاش مجدد را شروع کنید؟")) {
            $this->info("عملیات لغو شد.");
            return Command::SUCCESS;
        }

        // ایجاد execution log موقت
        $executionLog = ExecutionLog::create([
            'config_id' => $config->id,
            'execution_id' => 'retry_' . time(),
            'status' => 'running',
            'started_at' => now(),
            'last_activity_at' => now()
        ]);

        $apiService = new ApiDataService($config);
        $successCount = 0;
        $stillFailedCount = 0;

        $progressBar = $this->output->createProgressBar($failedRequests->count());
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% | %message%');

        foreach ($failedRequests as $failedRequest) {
            $sourceId = (int)$failedRequest->source_id;
            $progressBar->setMessage("Source ID: {$sourceId}");

            try {
                $result = $apiService->processSourceId($sourceId, $executionLog);

                if (isset($result['stats']['total_success']) && $result['stats']['total_success'] > 0) {
                    $successCount++;
                    $this->line("\n✅ Source ID {$sourceId} موفق شد!");
                } elseif (isset($result['action']) && in_array($result['action'], ['enhanced', 'enriched', 'merged'])) {
                    $successCount++;
                    $this->line("\n🔧 Source ID {$sourceId} بهبود یافت!");
                } else {
                    $stillFailedCount++;
                }

            } catch (\Exception $e) {
                $stillFailedCount++;
                $this->line("\n❌ خطا در Source ID {$sourceId}: " . $e->getMessage());
            }

            $progressBar->advance();
            sleep(1); // تاخیر کوتاه
        }

        $progressBar->finish();
        $this->newLine(2);

        // تکمیل execution log
        $executionLog->update([
            'status' => 'completed',
            'finished_at' => now(),
            'total_processed' => $failedRequests->count(),
            'total_success' => $successCount,
            'total_failed' => $stillFailedCount
        ]);

        $this->info("🎉 تلاش مجدد تمام شد:");
        $this->line("   ✅ موفق: {$successCount}");
        $this->line("   ❌ هنوز ناموفق: {$stillFailedCount}");

        return Command::SUCCESS;
    }

    private function cleanupOldRequests(): int
    {
        $days = (int)$this->option('days');
        $force = $this->option('force');

        $this->info("🧹 پاکسازی درخواست‌های حل شده قدیمی‌تر از {$days} روز");

        // شمارش قبل از حذف
        $oldCount = FailedRequest::where('is_resolved', true)
            ->where('updated_at', '<', now()->subDays($days))
            ->count();

        if ($oldCount === 0) {
            $this->info("✅ هیچ درخواست قدیمی برای پاکسازی یافت نشد!");
            return Command::SUCCESS;
        }

        $this->line("یافت شد: {$oldCount} درخواست حل شده قدیمی");

        if (!$force && !$this->confirm("آیا می‌خواهید آنها را حذف کنید؟")) {
            $this->info("عملیات لغو شد.");
            return Command::SUCCESS;
        }

        $deletedCount = FailedRequest::cleanupOldResolved($days);

        $this->info("✅ {$deletedCount} درخواست قدیمی پاک شد!");

        return Command::SUCCESS;
    }
}
