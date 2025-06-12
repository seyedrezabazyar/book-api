<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Config;
use App\Models\MissingSource;
use App\Services\ApiDataService;
use App\Models\ExecutionLog;

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

    public function handle(): int
    {
        $action = $this->argument('action');

        $this->info("🔧 مدیریت source های ناموجود - عملیات: {$action}");

        switch ($action) {
            case 'stats':
                return $this->showStats();

            case 'list':
                return $this->listMissingSources();

            case 'retry':
                return $this->retryMissingSources();

            case 'cleanup':
                return $this->cleanupOldSources();

            default:
                $this->error("❌ عملیات نامعتبر: {$action}");
                $this->line("عملیات‌های معتبر: stats, list, retry, cleanup");
                return Command::FAILURE;
        }
    }

    private function showStats(): int
    {
        $this->info("📊 آمار source های ناموجود:");

        $configId = $this->option('config');
        $sourceName = $this->option('source');

        if ($configId) {
            $config = Config::find($configId);
            if (!$config) {
                $this->error("❌ کانفیگ با ID {$configId} یافت نشد!");
                return Command::FAILURE;
            }

            $this->displayConfigStats($config);
        } elseif ($sourceName) {
            $this->displaySourceStats($sourceName);
        } else {
            $this->displayGlobalStats();
        }

        return Command::SUCCESS;
    }

    private function displayConfigStats(Config $config): void
    {
        $stats = MissingSource::getStatsForConfig($config->id);

        $this->table(['آمار', 'مقدار'], [
            ['منبع', $config->source_name],
            ['کل ناموجود', number_format($stats['total_missing'])],
            ['دائماً ناموجود', number_format($stats['permanently_missing'])],
            ['یافت نشد (404)', number_format($stats['not_found'])],
            ['خطای API', number_format($stats['api_errors'])],
            ['اولین ID ناموجود', $stats['first_missing_id'] ?? 'هیچ'],
            ['آخرین ID ناموجود', $stats['last_missing_id'] ?? 'هیچ']
        ]);

        if ($stats['total_missing'] > 0) {
            $this->newLine();
            $this->info("📋 نمونه source های ناموجود:");
            $missingList = MissingSource::getMissingList($config->id, 10);

            if (!empty($missingList)) {
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
    }

    private function displaySourceStats(string $sourceName): void
    {
        $configs = Config::where('source_name', $sourceName)->get();

        if ($configs->isEmpty()) {
            $this->warn("⚠️ هیچ کانفیگی با منبع {$sourceName} یافت نشد!");
            return;
        }

        $this->info("📈 آمار منبع: {$sourceName}");

        foreach ($configs as $config) {
            $stats = MissingSource::getStatsForConfig($config->id);

            if ($stats['total_missing'] > 0) {
                $this->line("  • کانفیگ {$config->id}: {$stats['total_missing']} ناموجود");
            }
        }
    }

    private function displayGlobalStats(): void
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
            return;
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
        $force = $this->option('force');

        if (!$configId) {
            $this->error("❌ برای retry باید --config مشخص کنید");
            return Command::FAILURE;
        }

        $config = Config::find($configId);
        if (!$config) {
            $this->error("❌ کانفیگ با ID {$configId} یافت نشد!");
            return Command::FAILURE;
        }

        // فقط source هایی که دائمی نیستند
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

        if (!$force && !$this->confirm("آیا می‌خواهید تلاش مجدد را شروع کنید؟")) {
            $this->info("عملیات لغو شد.");
            return Command::SUCCESS;
        }

        // ایجاد execution log موقت
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

                if (isset($result['stats']['total_success']) && $result['stats']['total_success'] > 0) {
                    $successCount++;
                    $this->line("\n✅ Source ID {$sourceId} موفق شد!");
                } elseif (isset($result['action']) && in_array($result['action'], ['enhanced', 'enriched', 'merged'])) {
                    $successCount++;
                    $this->line("\n🔧 Source ID {$sourceId} بهبود یافت!");
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

        // تکمیل execution log
        $executionLog->update([
            'status' => 'completed',
            'finished_at' => now(),
            'total_processed' => $missingSources->count(),
            'total_success' => $successCount,
            'total_failed' => $stillMissingCount
        ]);

        $this->info("🎉 تلاش مجدد تمام شد:");
        $this->line("   ✅ موفق: {$successCount}");
        $this->line("   ❌ هنوز ناموجود: {$stillMissingCount}");

        return Command::SUCCESS;
    }

    private function cleanupOldSources(): int
    {
        $days = (int)$this->option('days');
        $force = $this->option('force');

        $this->info("🧹 پاکسازی source های ناموجود قدیمی‌تر از {$days} روز");

        $oldCount = MissingSource::where('first_checked_at', '<', now()->subDays($days))
            ->where('is_permanently_missing', true)
            ->count();

        if ($oldCount === 0) {
            $this->info("✅ هیچ source قدیمی برای پاکسازی یافت نشد!");
            return Command::SUCCESS;
        }

        $this->line("یافت شد: {$oldCount} source قدیمی");

        if (!$force && !$this->confirm("آیا می‌خواهید آنها را حذف کنید؟")) {
            $this->info("عملیات لغو شد.");
            return Command::SUCCESS;
        }

        $deletedCount = MissingSource::cleanupOld($days);

        $this->info("✅ {$deletedCount} source قدیمی پاک شد!");

        return Command::SUCCESS;
    }
}
