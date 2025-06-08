<?php

namespace App\Console\Commands;

use App\Models\Config;
use App\Models\ExecutionLog;
use App\Services\ApiDataService;
use App\Jobs\ProcessSinglePageJob;
use App\Helpers\SourceIdManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessMissingIds extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'crawl:missing-ids
                            {config : Config ID to process}
                            {--start=1 : Start ID for range}
                            {--end= : End ID for range (default: last_source_id)}
                            {--limit=100 : Maximum IDs to process}
                            {--delay=3 : Delay between requests in seconds}
                            {--dry-run : Show what would be processed without actually doing it}
                            {--background : Run in background using queue}';

    /**
     * The console command description.
     */
    protected $description = 'پردازش ID های مفقود برای یک کانفیگ خاص';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $configId = $this->argument('config');
        $startId = (int) $this->option('start');
        $endId = $this->option('end');
        $limit = (int) $this->option('limit');
        $delay = (int) $this->option('delay');
        $dryRun = $this->option('dry-run');
        $background = $this->option('background');

        // دریافت کانفیگ
        $config = Config::find($configId);
        if (!$config) {
            $this->error("❌ کانفیگ با ID {$configId} یافت نشد!");
            return 1;
        }

        // تعیین endId اگر مشخص نشده
        if (!$endId) {
            $endId = $config->last_source_id;
        } else {
            $endId = (int) $endId;
        }

        $this->info("🔍 جستجوی ID های مفقود برای کانفیگ: {$config->name}");
        $this->info("   منبع: {$config->source_name}");
        $this->info("   بازه: {$startId} تا {$endId}");
        $this->info("   حداکثر: {$limit} ID");

        // یافتن ID های مفقود
        $missingIds = SourceIdManager::findMissingIds($config, $startId, $endId, $limit);

        if (empty($missingIds)) {
            $this->info("✅ هیچ ID مفقودی در این بازه یافت نشد!");
            return 0;
        }

        $this->warn("📋 {" . count($missingIds) . "} ID مفقود یافت شد:");

        // نمایش نمونه ID های مفقود
        $sample = array_slice($missingIds, 0, 10);
        $this->line("   " . implode(', ', $sample));
        if (count($missingIds) > 10) {
            $this->line("   ... و " . (count($missingIds) - 10) . " مورد دیگر");
        }

        if ($dryRun) {
            $this->info("👀 حالت Dry Run - هیچ پردازشی انجام نمی‌شود");
            return 0;
        }

        // تأیید کاربر
        if (!$this->confirm("آیا می‌خواهید این ID ها را پردازش کنید؟")) {
            $this->info("❌ لغو شد توسط کاربر");
            return 0;
        }

        // بررسی اینکه کانفیگ در حال اجرا نباشد
        if ($config->is_running) {
            $this->error("❌ کانفیگ در حال اجرا است! ابتدا آن را متوقف کنید.");
            return 1;
        }

        if ($background) {
            return $this->processInBackground($config, $missingIds, $delay);
        } else {
            return $this->processDirectly($config, $missingIds, $delay);
        }
    }

    /**
     * پردازش در پس‌زمینه با صف
     */
    private function processInBackground(Config $config, array $missingIds, int $delay): int
    {
        $this->info("🚀 شروع پردازش در پس‌زمینه...");

        try {
            // ایجاد ExecutionLog
            $executionLog = ExecutionLog::createNew($config);
            $executionLog->addLogEntry("🔧 شروع پردازش ID های مفقود", [
                'missing_ids_count' => count($missingIds),
                'sample_ids' => array_slice($missingIds, 0, 10),
                'mode' => 'missing_ids_recovery'
            ]);

            // علامت‌گذاری کانفیگ به عنوان در حال اجرا
            $config->update(['is_running' => true]);

            // ایجاد Jobs برای هر ID مفقود
            foreach ($missingIds as $sourceId) {
                ProcessSinglePageJob::dispatch($config->id, $sourceId, $executionLog->execution_id)
                    ->delay(now()->addSeconds($delay * array_search($sourceId, $missingIds)));
            }

            // Job پایان اجرا
            ProcessSinglePageJob::dispatch($config->id, -1, $executionLog->execution_id)
                ->delay(now()->addSeconds($delay * count($missingIds) + 60));

            $this->info("✅ {" . count($missingIds) . "} Job در صف قرار گرفت");
            $this->info("🆔 شناسه اجرا: {$executionLog->execution_id}");
            $this->info("⏱️ تخمین زمان: " . round((count($missingIds) * $delay) / 60, 1) . " دقیقه");

            return 0;
        } catch (\Exception $e) {
            $this->error("❌ خطا در شروع پردازش: " . $e->getMessage());
            $config->update(['is_running' => false]);
            return 1;
        }
    }

    /**
     * پردازش مستقیم
     */
    private function processDirectly(Config $config, array $missingIds, int $delay): int
    {
        $this->info("⚡ شروع پردازش مستقیم...");

        try {
            $apiService = new ApiDataService($config);
            $executionLog = ExecutionLog::createNew($config);

            $executionLog->addLogEntry("⚡ شروع پردازش مستقیم ID های مفقود", [
                'missing_ids_count' => count($missingIds),
                'sample_ids' => array_slice($missingIds, 0, 10),
                'mode' => 'missing_ids_direct'
            ]);

            $config->update(['is_running' => true]);

            $progress = $this->output->createProgressBar(count($missingIds));
            $progress->setFormat('very_verbose');
            $progress->start();

            $stats = ['total' => 0, 'success' => 0, 'failed' => 0, 'duplicate' => 0];

            foreach ($missingIds as $sourceId) {
                try {
                    $result = $apiService->processSourceId($sourceId, $executionLog);

                    if (isset($result['stats'])) {
                        $stats['total'] += $result['stats']['total'] ?? 0;
                        $stats['success'] += $result['stats']['success'] ?? 0;
                        $stats['failed'] += $result['stats']['failed'] ?? 0;
                        $stats['duplicate'] += $result['stats']['duplicate'] ?? 0;
                    }

                    $progress->advance();

                    if ($delay > 0) {
                        sleep($delay);
                    }
                } catch (\Exception $e) {
                    $stats['failed']++;
                    $this->error("\n❌ خطا در پردازش ID {$sourceId}: " . $e->getMessage());
                    $progress->advance();
                }
            }

            $progress->finish();
            $this->newLine(2);

            // تکمیل ExecutionLog
            $finalStats = [
                'total' => $stats['total'],
                'success' => $stats['success'],
                'failed' => $stats['failed'],
                'duplicate' => $stats['duplicate'],
                'execution_time' => $executionLog->started_at ? now()->diffInSeconds($executionLog->started_at) : 0
            ];

            $executionLog->markCompleted($finalStats);
            $config->update(['is_running' => false]);

            // نمایش نتایج
            $this->info("✅ پردازش تمام شد!");
            $this->table(
                ['متریک', 'تعداد'],
                [
                    ['کل پردازش شده', $stats['total']],
                    ['موفق', $stats['success']],
                    ['تکراری', $stats['duplicate']],
                    ['خطا', $stats['failed']],
                    ['نرخ موفقیت', $stats['total'] > 0 ? round(($stats['success'] / $stats['total']) * 100, 1) . '%' : '0%']
                ]
            );

            return 0;
        } catch (\Exception $e) {
            $this->error("❌ خطا در پردازش: " . $e->getMessage());
            $config->update(['is_running' => false]);

            if (isset($executionLog)) {
                $executionLog->markFailed($e->getMessage());
            }

            return 1;
        }
    }
}
