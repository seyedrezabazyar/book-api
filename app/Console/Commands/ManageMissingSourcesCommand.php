<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Config;
use App\Models\MissingSource;
use App\Services\ApiDataService;
use App\Models\ExecutionLog;
use App\Console\Helpers\CommandDisplayHelper;

class ManageMissingSourcesCommand extends Command
{
    protected $signature = 'missing-sources:manage
                          {action : نوع عملیات (stats|list|retry|cleanup)}
                          {--config= : ID کانفیگ برای فیلتر}
                          {--source= : نام منبع برای فیلتر}
                          {--limit=50 : تعداد محدود نتایج}
                          {--force : اجرای اجباری}
                          {--days=90 : تعداد روز برای cleanup}';

    protected $description = 'مدیریت source های ناموجود';

    private CommandDisplayHelper $displayHelper;

    public function __construct()
    {
        parent::__construct();
        $this->displayHelper = new CommandDisplayHelper($this);
    }

    public function handle(): int
    {
        $action = $this->argument('action');
        $this->displayHelper->displayWelcomeMessage("مدیریت source های ناموجود - عملیات: {$action}");

        return match($action) {
            'stats' => $this->showStats(),
            'list' => $this->listMissingSources(),
            'retry' => $this->retryMissingSources(),
            'cleanup' => $this->cleanupOldSources(),
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
        $configId = $this->option('config');
        $sourceName = $this->option('source');

        if ($configId) {
            return $this->displayConfigStats($configId);
        } elseif ($sourceName) {
            return $this->displaySourceStats($sourceName);
        } else {
            return $this->displayGlobalStats();
        }
    }

    private function displayConfigStats(string $configId): int
    {
        $config = Config::find($configId);
        if (!$config) {
            $this->error("❌ کانفیگ با ID {$configId} یافت نشد!");
            return Command::FAILURE;
        }

        $stats = MissingSource::getStatsForConfig($config->id);

        $this->displayHelper->displayStats([
            'منبع' => $config->source_name,
            'کل ناموجود' => $stats['total_missing'],
            'دائماً ناموجود' => $stats['permanently_missing'],
            'یافت نشد (404)' => $stats['not_found'],
            'خطای API' => $stats['api_errors'],
            'اولین ID ناموجود' => $stats['first_missing_id'] ?? 'هیچ',
            'آخرین ID ناموجود' => $stats['last_missing_id'] ?? 'هیچ'
        ], "آمار کانفیگ {$configId}");

        if ($stats['total_missing'] > 0) {
            $this->displayMissingSampleList($config->id);
        }

        return Command::SUCCESS;
    }

    private function displayMissingSampleList(int $configId): void
    {
        $missingList = MissingSource::getMissingList($configId, 10);

        if (!empty($missingList)) {
            $this->newLine();
            $this->info("📋 نمونه source های ناموجود:");
            $tableData = [];
            foreach ($missingList as $item) {
                $tableData[] = [
                    $item['source_id'],
                    $item['reason'],
                    $item['check_count'],
                    $item['is_permanently_missing'] ? 'بله' : 'خیر',
                    $item['last_checked_at']
                ];
            }

            $this->table([
                'Source ID', 'دلیل', 'تعداد چک', 'دائمی؟', 'آخرین چک'
            ], $tableData);
        }
    }

    private function displaySourceStats(string $sourceName): int
    {
        $configs = Config::where('source_name', $sourceName)->get();

        if ($configs->isEmpty()) {
            $this->warn("⚠️ هیچ کانفیگی با منبع {$sourceName} یافت نشد!");
            return Command::SUCCESS;
        }

        $this->info("📈 آمار منبع: {$sourceName}");

        $totalStats = ['total_missing' => 0, 'permanently_missing' => 0];

        foreach ($configs as $config) {
            $stats = MissingSource::getStatsForConfig($config->id);

            if ($stats['total_missing'] > 0) {
                $this->line("  • کانفیگ {$config->id}: {$stats['total_missing']} ناموجود");
                $totalStats['total_missing'] += $stats['total_missing'];
                $totalStats['permanently_missing'] += $stats['permanently_missing'];
            }
        }

        $this->displayHelper->displayStats($totalStats, "جمع کل منبع {$sourceName}");
        return Command::SUCCESS;
    }

    private function displayGlobalStats(): int
    {
        $globalStats = MissingSource::selectRaw('
            source_name,
            COUNT(*) as total_missing,
            COUNT(CASE WHEN is_permanently_missing = 1 THEN 1 END) as permanently_missing,
            COUNT(CASE WHEN reason = "not_found" THEN 1 END) as not_found,
            COUNT(CASE WHEN reason = "api_error" THEN 1 END) as api_errors
        ')
            ->groupBy('source_name')
            ->orderBy('total_missing', 'desc')
            ->get();

        if ($globalStats->isEmpty()) {
            $this->info("✅ هیچ source ناموجودی ثبت نشده!");
            return Command::SUCCESS;
        }

        $this->info("🌍 آمار کلی source های ناموجود:");

        $tableData = [];
        foreach ($globalStats as $stat) {
            $tableData[] = [
                $stat->source_name,
                number_format($stat->total_missing),
                number_format($stat->permanently_missing),
                number_format($stat->not_found),
                number_format($stat->api_errors)
            ];
        }

        $this->table([
            'منبع', 'کل ناموجود', 'دائمی', '404', 'خطای API'
        ], $tableData);

        return Command::SUCCESS;
    }

    private function listMissingSources(): int
    {
        $limit = (int)$this->option('limit');
        $configId = $this->option('config');

        if (!$configId) {
            $this->error("❌ برای list باید --config مشخص کنید");
            return Command::FAILURE;
        }

        $config = Config::find($configId);
        if (!$config) {
            $this->error("❌ کانفیگ با ID {$configId} یافت نشد!");
            return Command::FAILURE;
        }

        $missingSources = MissingSource::where('config_id', $configId)
            ->orderBy('source_id')
            ->limit($limit)
            ->get();

        if ($missingSources->isEmpty()) {
            $this->info("✅ هیچ source ناموجودی برای این کانفیگ یافت نشد!");
            return Command::SUCCESS;
        }

        $this->info("📋 لیست source های ناموجود (آخرین {$limit} مورد):");

        $tableData = [];
        foreach ($missingSources as $missing) {
            $tableData[] = [
                $missing->source_id,
                $missing->reason,
                $missing->check_count,
                $missing->is_permanently_missing ? 'بله' : 'خیر',
                $missing->http_status ?? 'N/A',
                $missing->last_checked_at->diffForHumans()
            ];
        }

        $this->table([
            'Source ID', 'دلیل', 'تعداد چک', 'دائمی؟', 'HTTP Status', 'آخرین چک'
        ], $tableData);

        return Command::SUCCESS;
    }

    private function retryMissingSources(): int
    {
        $configId = $this->option('config');
        $limit = (int)$this->option('limit');

        if (!$configId) {
            $this->error("❌ برای retry باید --config مشخص کنید");
            return Command::FAILURE;
        }

        $config = Config::find($configId);
        if (!$config) {
            $this->error("❌ کانفیگ با ID {$configId} یافت نشد!");
            return Command::FAILURE;
        }

        $missingSources = MissingSource::where('config_id', $configId)
            ->where('is_permanently_missing', false)
            ->orderBy('source_id')
            ->limit($limit)
            ->get();

        if ($missingSources->isEmpty()) {
            $this->info("✅ هیچ source ناموجود قابل retry یافت نشد!");
            return Command::SUCCESS;
        }

        $this->info("🔄 یافت شد: {$missingSources->count()} source برای تلاش مجدد");

        if (!$this->displayHelper->confirmOperation($config, [
            'تعداد source ها' => $missingSources->count()
        ], $this->option('force'))) {
            $this->info("عملیات لغو شد.");
            return Command::SUCCESS;
        }

        return $this->performSourceRetry($config, $missingSources);
    }

    private function performSourceRetry(Config $config, $missingSources): int
    {
        $executionLog = ExecutionLog::create([
            'config_id' => $config->id,
            'execution_id' => 'retry_missing_' . time(),
            'status' => 'running',
            'started_at' => now(),
            'last_activity_at' => now()
        ]);

        $apiService = new ApiDataService($config);
        $successCount = 0;
        $stillMissingCount = 0;

        $progressBar = $this->output->createProgressBar($missingSources->count());
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% | %message%');

        foreach ($missingSources as $missingSource) {
            $sourceId = (int)$missingSource->source_id;
            $progressBar->setMessage("Source ID: {$sourceId}");

            try {
                $result = $apiService->processSourceId($sourceId, $executionLog);

                if ($this->isSuccessResult($result)) {
                    $successCount++;
                    $this->line("\n✅ Source ID {$sourceId} موفق شد!");
                } else {
                    $stillMissingCount++;
                }

            } catch (\Exception $e) {
                $stillMissingCount++;
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
            'total_processed' => $missingSources->count(),
            'total_success' => $successCount,
            'total_failed' => $stillMissingCount
        ]);

        $this->displayHelper->displayFinalResults($missingSources->count(), [
            'موفق' => $successCount,
            'هنوز ناموجود' => $stillMissingCount
        ], 'تلاش مجدد source های ناموجود');

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

    private function cleanupOldSources(): int
    {
        $days = (int)$this->option('days');

        $this->info("🧹 پاکسازی source های ناموجود قدیمی‌تر از {$days} روز");

        $oldCount = MissingSource::where('first_checked_at', '<', now()->subDays($days))
            ->where('is_permanently_missing', true)
            ->count();

        if ($oldCount === 0) {
            $this->info("✅ هیچ source قدیمی برای پاکسازی یافت نشد!");
            return Command::SUCCESS;
        }

        $this->line("یافت شد: {$oldCount} source قدیمی");

        if (!$this->option('force') && !$this->confirm("آیا می‌خواهید آنها را حذف کنید؟")) {
            $this->info("عملیات لغو شد.");
            return Command::SUCCESS;
        }

        $deletedCount = MissingSource::cleanupOld($days);

        $this->info("✅ {$deletedCount} source قدیمی پاک شد!");

        return Command::SUCCESS;
    }
}
