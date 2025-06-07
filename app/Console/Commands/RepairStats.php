<?php

namespace App\Console\Commands;

use App\Models\Config;
use App\Models\ExecutionLog;
use App\Models\Book;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairStats extends Command
{
    protected $signature = 'stats:repair
                          {--dry-run : فقط نمایش تغییرات بدون اعمال}
                          {--force : اعمال تغییرات بدون تأیید}';

    protected $description = 'تعمیر و اصلاح آمار نادرست کانفیگ‌ها و execution logs';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info('🔧 شروع تعمیر آمار...');

        if ($dryRun) {
            $this->warn('⚠️  حالت DRY-RUN: تغییرات اعمال نخواهد شد');
        }

        // 1. تعمیر execution logs
        $this->repairExecutionLogs($dryRun);

        // 2. تعمیر آمار کانفیگ‌ها
        $this->repairConfigStats($dryRun);

        // 3. بررسی تطابق با کتاب‌های واقعی
        $this->checkBookConsistency();

        // 4. تأیید نهایی
        if (!$dryRun && !$force) {
            if (!$this->confirm('آیا می‌خواهید تغییرات اعمال شود؟')) {
                $this->info('❌ عملیات لغو شد');
                return 1;
            }
        }

        $this->info('✅ تعمیر آمار تمام شد!');
        return 0;
    }

    private function repairExecutionLogs(bool $dryRun): void
    {
        $this->line('🔍 بررسی execution logs...');

        // یافتن logs در حال اجرا که کانفیگشان متوقف است
        $problematicLogs = ExecutionLog::where('status', 'running')
            ->whereHas('config', function($query) {
                $query->where('is_running', false);
            })
            ->get();

        if ($problematicLogs->count() > 0) {
            $this->warn("⚠️  {$problematicLogs->count()} execution log مشکل‌دار یافت شد:");

            foreach ($problematicLogs as $log) {
                $config = $log->config;
                $executionTime = $log->started_at ? now()->diffInSeconds($log->started_at) : 0;

                $this->line("  • Log {$log->id} (Config: {$config->name}): running → stopped");
                $this->line("    شروع: {$log->started_at}");
                $this->line("    مدت: {$executionTime}s");

                if (!$dryRun) {
                    // محاسبه آمار واقعی از کانفیگ
                    $log->update([
                        'status' => 'stopped',
                        'total_processed' => $config->total_processed,
                        'total_success' => $config->total_success,
                        'total_failed' => $config->total_failed,
                        'execution_time' => $executionTime,
                        'finished_at' => now(),
                        'error_message' => 'متوقف شده توسط کاربر (تعمیر شده)',
                    ]);

                    // اضافه کردن log entry
                    $logDetails = $log->log_details ?? [];
                    $logDetails[] = [
                        'timestamp' => now()->toISOString(),
                        'message' => 'آمار اصلاح شد',
                        'context' => [
                            'repaired_by' => 'stats:repair command',
                            'final_stats' => [
                                'total_processed' => $config->total_processed,
                                'total_success' => $config->total_success,
                                'total_failed' => $config->total_failed
                            ]
                        ]
                    ];
                    $log->update(['log_details' => $logDetails]);
                }
            }
        } else {
            $this->info('✅ همه execution logs سالم هستند');
        }
    }

    private function repairConfigStats(bool $dryRun): void
    {
        $this->line('🔍 بررسی آمار کانفیگ‌ها...');

        $configs = Config::with('executionLogs')->get();
        $repairedCount = 0;

        foreach ($configs as $config) {
            // محاسبه آمار صحیح از execution logs
            $completedLogs = $config->executionLogs()
                ->whereIn('status', ['completed', 'stopped'])
                ->get();

            $correctStats = [
                'total_processed' => $completedLogs->sum('total_processed'),
                'total_success' => $completedLogs->sum('total_success'),
                'total_failed' => $completedLogs->sum('total_failed'),
            ];

            $currentStats = [
                'total_processed' => $config->total_processed,
                'total_success' => $config->total_success,
                'total_failed' => $config->total_failed,
            ];

            $needsRepair = false;
            $changes = [];

            foreach ($correctStats as $key => $correctValue) {
                if ($currentStats[$key] !== $correctValue) {
                    $needsRepair = true;
                    $changes[$key] = [
                        'from' => $currentStats[$key],
                        'to' => $correctValue
                    ];
                }
            }

            if ($needsRepair) {
                $repairedCount++;
                $this->warn("⚠️  Config {$config->id} ({$config->name}) نیاز به تعمیر:");

                foreach ($changes as $field => $change) {
                    $this->line("    • {$field}: {$change['from']} → {$change['to']}");
                }

                if (!$dryRun) {
                    $config->update($correctStats);
                }
            }
        }

        if ($repairedCount === 0) {
            $this->info('✅ آمار همه کانفیگ‌ها صحیح است');
        } else {
            $message = $dryRun ?
                "⚠️  {$repairedCount} کانفیگ نیاز به تعمیر دارد" :
                "✅ {$repairedCount} کانفیگ تعمیر شد";
            $this->line($message);
        }
    }

    private function checkBookConsistency(): void
    {
        $this->line('📚 بررسی تطابق با کتاب‌های واقعی...');

        $totalBooks = Book::count();
        $totalConfigSuccess = Config::sum('total_success');

        $this->info("📊 کل کتاب‌ها در دیتابیس: {$totalBooks}");
        $this->info("📊 کل موفقیت‌های کانفیگ‌ها: {$totalConfigSuccess}");

        if ($totalBooks > $totalConfigSuccess) {
            $difference = $totalBooks - $totalConfigSuccess;
            $this->warn("⚠️  {$difference} کتاب اضافی در دیتابیس وجود دارد");
            $this->line("   این می‌تواند به دلیل:");
            $this->line("   • کتاب‌های import شده از منابع دیگر");
            $this->line("   • کتاب‌های ایجاد شده قبل از tracking");
            $this->line("   • خطا در tracking آمار");
        } elseif ($totalBooks < $totalConfigSuccess) {
            $difference = $totalConfigSuccess - $totalBooks;
            $this->error("❌ {$difference} کتاب کم در دیتابیس! (احتمال خطا در آمار)");
        } else {
            $this->info("✅ تطابق کامل: تعداد کتاب‌ها = آمار موفقیت");
        }

        // بررسی بر اساس تاریخ
        $configs = Config::orderBy('created_at')->get();
        foreach ($configs as $config) {
            $booksAfterConfig = Book::where('created_at', '>=', $config->created_at)->count();
            $this->line("  📅 Config {$config->name}: {$config->total_success} آمار / {$booksAfterConfig} کتاب بعد از ایجاد");
        }
    }
}
