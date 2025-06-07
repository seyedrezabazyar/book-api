<?php

namespace App\Console\Commands;

use App\Models\Config;
use App\Models\ExecutionLog;
use App\Models\Book;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncConfigStats extends Command
{
    protected $signature = 'config:sync-stats
                          {--config= : شناسه کانفیگ خاص برای همگام‌سازی}
                          {--all : همگام‌سازی همه کانفیگ‌ها}
                          {--show-details : نمایش جزئیات بیشتر}';

    protected $description = 'همگام‌سازی آمار کانفیگ‌ها با execution logs و کتاب‌های واقعی';

    public function handle()
    {
        $this->info('🔄 شروع همگام‌سازی آمار...');

        $configId = $this->option('config');
        $all = $this->option('all');
        $showDetails = $this->option('show-details');

        if ($configId) {
            $config = Config::find($configId);
            if (!$config) {
                $this->error("❌ کانفیگ با شناسه {$configId} یافت نشد!");
                return 1;
            }
            $this->syncSingleConfig($config, $showDetails);
        } elseif ($all) {
            $configs = Config::all();
            $this->info("📊 همگام‌سازی {$configs->count()} کانفیگ...");

            foreach ($configs as $config) {
                $this->syncSingleConfig($config, $showDetails);
            }
        } else {
            $this->error('❌ لطفاً --config=ID یا --all را مشخص کنید');
            return 1;
        }

        $this->info('✅ همگام‌سازی آمار تمام شد!');
        return 0;
    }

    private function syncSingleConfig(Config $config, bool $showDetails = false)
    {
        $this->line("🔧 همگام‌سازی کانفیگ: {$config->name} (ID: {$config->id})");

        // آمار قبلی
        $oldStats = [
            'total_processed' => $config->total_processed,
            'total_success' => $config->total_success,
            'total_failed' => $config->total_failed,
        ];

        // همگام‌سازی از execution logs
        $config->syncStatsFromLogs();
        $config->refresh();

        // آمار جدید
        $newStats = [
            'total_processed' => $config->total_processed,
            'total_success' => $config->total_success,
            'total_failed' => $config->total_failed,
        ];

        // آمار execution logs
        $executionStats = $this->getExecutionStats($config);

        // آمار کتاب‌های واقعی
        $bookStats = $this->getBookStats($config);

        if ($showDetails) {
            $this->displayDetailedStats($config, $oldStats, $newStats, $executionStats, $bookStats);
        } else {
            $this->displaySummaryStats($config, $oldStats, $newStats, $executionStats, $bookStats);
        }
    }

    private function getExecutionStats(Config $config): array
    {
        return [
            'total_executions' => ExecutionLog::where('config_id', $config->id)->count(),
            'completed' => ExecutionLog::where('config_id', $config->id)->where('status', 'completed')->count(),
            'stopped' => ExecutionLog::where('config_id', $config->id)->where('status', 'stopped')->count(),
            'failed' => ExecutionLog::where('config_id', $config->id)->where('status', 'failed')->count(),
            'running' => ExecutionLog::where('config_id', $config->id)->where('status', 'running')->count(),
            'total_from_logs' => ExecutionLog::where('config_id', $config->id)
                ->whereIn('status', ['completed', 'stopped'])
                ->sum('total_processed'),
            'success_from_logs' => ExecutionLog::where('config_id', $config->id)
                ->whereIn('status', ['completed', 'stopped'])
                ->sum('total_success'),
            'failed_from_logs' => ExecutionLog::where('config_id', $config->id)
                ->whereIn('status', ['completed', 'stopped'])
                ->sum('total_failed'),
        ];
    }

    private function getBookStats(Config $config): array
    {
        // فرض می‌کنیم کتاب‌هایی که بعد از ایجاد کانفیگ اضافه شده‌اند، از این کانفیگ هستند
        $booksAfterConfig = Book::where('created_at', '>=', $config->created_at)->count();

        // آمار کلی کتاب‌ها
        $totalBooks = Book::count();

        return [
            'books_after_config' => $booksAfterConfig,
            'total_books_in_db' => $totalBooks,
            'books_today' => Book::whereDate('created_at', today())->count(),
            'books_this_week' => Book::whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
        ];
    }

    private function displaySummaryStats(Config $config, array $oldStats, array $newStats, array $executionStats, array $bookStats)
    {
        $changed = $oldStats !== $newStats;

        if ($changed) {
            $this->line("  📈 آمار کانفیگ بروزرسانی شد:");
            $this->line("    • کل پردازش: {$oldStats['total_processed']} → {$newStats['total_processed']}");
            $this->line("    • موفق: {$oldStats['total_success']} → {$newStats['total_success']}");
            $this->line("    • خطا: {$oldStats['total_failed']} → {$newStats['total_failed']}");
        } else {
            $this->line("  ✅ آمار کانفیگ همگام است");
        }

        $this->line("  📊 اجراها: {$executionStats['total_executions']} (✅{$executionStats['completed']} ⏹️{$executionStats['stopped']} ❌{$executionStats['failed']})");
        $this->line("  📚 کتاب‌های مرتبط: {$bookStats['books_after_config']} از {$bookStats['total_books_in_db']} کل");
    }

    private function displayDetailedStats(Config $config, array $oldStats, array $newStats, array $executionStats, array $bookStats)
    {
        $this->line("┌─ جزئیات کامل کانفیگ: {$config->name}");
        $this->line("├─ شناسه: {$config->id}");
        $this->line("├─ وضعیت: {$config->status}");
        $this->line("├─ ایجاد شده: {$config->created_at->format('Y/m/d H:i:s')}");
        $this->line("│");
        $this->line("├─ آمار کانفیگ (قبل → بعد):");
        $this->line("│  ├─ کل پردازش: {$oldStats['total_processed']} → {$newStats['total_processed']}");
        $this->line("│  ├─ موفق: {$oldStats['total_success']} → {$newStats['total_success']}");
        $this->line("│  └─ خطا: {$oldStats['total_failed']} → {$newStats['total_failed']}");
        $this->line("│");
        $this->line("├─ آمار execution logs:");
        $this->line("│  ├─ کل اجراها: {$executionStats['total_executions']}");
        $this->line("│  ├─ تمام شده: {$executionStats['completed']}");
        $this->line("│  ├─ متوقف شده: {$executionStats['stopped']}");
        $this->line("│  ├─ ناموفق: {$executionStats['failed']}");
        $this->line("│  ├─ در حال اجرا: {$executionStats['running']}");
        $this->line("│  ├─ کل از logs: {$executionStats['total_from_logs']}");
        $this->line("│  ├─ موفق از logs: {$executionStats['success_from_logs']}");
        $this->line("│  └─ خطا از logs: {$executionStats['failed_from_logs']}");
        $this->line("│");
        $this->line("├─ آمار کتاب‌ها:");
        $this->line("│  ├─ کل در دیتابیس: {$bookStats['total_books_in_db']}");
        $this->line("│  ├─ بعد از این کانفیگ: {$bookStats['books_after_config']}");
        $this->line("│  ├─ امروز: {$bookStats['books_today']}");
        $this->line("│  └─ این هفته: {$bookStats['books_this_week']}");
        $this->line("│");

        // بررسی تطابق
        $configMatches = ($newStats['total_processed'] == $executionStats['total_from_logs'] &&
            $newStats['total_success'] == $executionStats['success_from_logs']);

        if ($configMatches) {
            $this->line("└─ ✅ آمار کانفیگ و logs همگام هستند");
        } else {
            $this->line("└─ ⚠️  عدم تطابق آمار کانفیگ و logs!");
            $this->warn("   تفاوت پردازش: " . ($newStats['total_processed'] - $executionStats['total_from_logs']));
            $this->warn("   تفاوت موفقیت: " . ($newStats['total_success'] - $executionStats['success_from_logs']));
        }

        $this->line("");
    }
}
