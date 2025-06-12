<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use App\Models\FailedRequest;
use App\Models\Config;
use App\Services\ApiDataService;
use App\Models\ExecutionLog;
use App\Console\Helpers\CommandDisplayHelper;

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

    private CommandDisplayHelper $displayHelper;

    public function __construct()
    {
        parent::__construct();
        $this->displayHelper = new CommandDisplayHelper($this);
    }

    public function handle(): int
    {
        $action = $this->argument('action');
        $this->displayHelper->displayWelcomeMessage("مدیریت درخواست‌های ناموفق - عملیات: {$action}");

        return match($action) {
            'stats' => $this->showStats(),
            'list' => $this->listFailedRequests(),
            'retry' => $this->retryFailedRequests(),
            'cleanup' => $this->cleanupOldRequests(),
            default => $this->handleInvalidAction($action)
        };
    }

    private function handleInvalidAction(string $action): int
    {
        $this->error("❌ عملیات نامعتبر: {$action}");
        $this->line("عملیات‌های معتبر: stats, list, retry, cleanup");
        return Command::FAILURE;
    }

    private function showStats(): int
    {
        $sourceName = $this->option('source');

        // آمار کلی
        $globalStats = [
            'کل درخواست‌های ناموفق' => FailedRequest::count(),
            'حل نشده' => FailedRequest::unresolved()->count(),
            'نیاز به تلاش مجدد' => FailedRequest::needsRetry()->count(),
            'حداکثر تلاش رسیده' => FailedRequest::where('retry_count', '>=', FailedRequest::MAX_RETRY_COUNT)
                ->where('is_resolved', false)->count()
        ];

        $this->displayHelper->displayStats($globalStats, 'آمار کلی درخواست‌های ناموفق');

        // آمار منبع خاص
        if ($sourceName) {
            $sourceStats = FailedRequest::getSourceStats($sourceName);
            $this->displayHelper->displayStats([
                'کل ناموفق' => $sourceStats['total_failed'],
                'حل شده' => $sourceStats['resolved_count'],
                'حل نشده' => $sourceStats['unresolved_count'],
                'حداکثر تلاش رسیده' => $sourceStats['max_retries_reached'],
                'نرخ حل شدن' => $sourceStats['retry_rate'] . '%'
            ], "آمار منبع: {$sourceName}");
        } else {
            // آمار برترین منابع
            $sourceStats = FailedRequest::select('source_name')
                ->selectRaw('COUNT(*) as total, COUNT(CASE WHEN is_resolved = 1 THEN 1 END) as resolved')
                ->groupBy('source_name')
                ->orderBy('total', 'desc')
                ->limit(10)
                ->get();

            if ($sourceStats->count() > 0) {
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

        $query = FailedRequest::query()->with('config')->unresolved();

        if ($sourceName) {
            $query->where('source_name', $sourceName);
        }

        if ($configId) {
            $query->where('config_id', $configId);
        }

        $failedRequests = $query->orderBy('first_failed_at', 'desc')->limit($limit)->get();

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

        if (!$sourceName && !$configId) {
            $this->error("❌ برای retry باید --source یا --config مشخص کنید");
            return Command::FAILURE;
        }

        // پیدا کردن کانفیگ
        $config = $this->findConfig($configId, $sourceName);
        if (!$config) {
            return Command::FAILURE;
        }

        $failedRequests = FailedRequest::where('source_name', $config->source_name)
            ->needsRetry()
            ->orderBy('first_failed_at')
            ->limit($limit)
            ->get();

        if ($failedRequests->isEmpty()) {
            $this->info("✅ هیچ درخواست ناموفقی برای تلاش مجدد یافت نشد!");
            return Command::SUCCESS;
        }

        $this->info("🔄 یافت شد: {$failedRequests->count()} درخواست برای تلاش مجدد");

        if (!$this->displayHelper->confirmOperation($config, [
            'تعداد درخواست‌ها' => $failedRequests->count()
        ], $this->option('force'))) {
            $this->info("عملیات لغو شد.");
            return Command::SUCCESS;
        }

        return $this->performRetry($config, $failedRequests);
    }

    private function findConfig(?string $configId, ?string $sourceName): ?Config
    {
        if ($configId) {
            $config = Config::find($configId);
            if (!$config) {
                $this->error("❌ کانفیگ با ID {$configId} یافت نشد!");
                return null;
            }
            return $config;
        }

        if ($sourceName) {
            $config = Config::where('source_name', $sourceName)->first();
            if (!$config) {
                $this->error("❌ کانفیگی با منبع {$sourceName} یافت نشد!");
                return null;
            }
            return $config;
        }

        return null;
    }

    private function performRetry(Config $config, $failedRequests): int
    {
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

                if ($this->isSuccessResult($result)) {
                    $successCount++;
                    $this->line("\n✅ Source ID {$sourceId} موفق شد!");
                } else {
                    $stillFailedCount++;
                }

            } catch (\Exception $e) {
                $stillFailedCount++;
                $this->line("\n❌ خطا در Source ID {$sourceId}: " . $e->getMessage());
            }

            $progressBar->advance();
            sleep(1);
        }

        $progressBar->finish();
        $this->newLine(2);

        $executionLog->update([
            'status' => 'completed',
            'finished_at' => now(),
            'total_processed' => $failedRequests->count(),
            'total_success' => $successCount,
            'total_failed' => $stillFailedCount
        ]);

        $this->displayHelper->displayFinalResults($failedRequests->count(), [
            'موفق' => $successCount,
            'هنوز ناموفق' => $stillFailedCount
        ], 'تلاش مجدد');

        return Command::SUCCESS;
    }

    private function isSuccessResult($result): bool
    {
        if (isset($result['stats']['total_success']) && $result['stats']['total_success'] > 0) {
            return true;
        }

        if (isset($result['action']) && in_array($result['action'], ['enhanced', 'enriched', 'merged'])) {
            return true;
        }

        return false;
    }

    private function cleanupOldRequests(): int
    {
        $days = (int)$this->option('days');

        $this->info("🧹 پاکسازی درخواست‌های حل شده قدیمی‌تر از {$days} روز");

        $oldCount = FailedRequest::where('is_resolved', true)
            ->where('updated_at', '<', now()->subDays($days))
            ->count();

        if ($oldCount === 0) {
            $this->info("✅ هیچ درخواست قدیمی برای پاکسازی یافت نشد!");
            return Command::SUCCESS;
        }

        $this->line("یافت شد: {$oldCount} درخواست حل شده قدیمی");

        if (!$this->option('force') && !$this->confirm("آیا می‌خواهید آنها را حذف کنید؟")) {
            $this->info("عملیات لغو شد.");
            return Command::SUCCESS;
        }

        $deletedCount = FailedRequest::cleanupOldResolved($days);

        $this->info("✅ {$deletedCount} درخواست قدیمی پاک شد!");

        return Command::SUCCESS;
    }
}
