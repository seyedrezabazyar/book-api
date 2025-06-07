<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->info('🔧 اصلاح سریع زمان و آمار execution logs...');

        // 1. اصلاح زمان‌های منفی
        $this->fixNegativeExecutionTimes();

        // 2. همگام‌سازی آمار logs با کانفیگ‌ها
        $this->syncLogStatsWithConfigs();

        // 3. اصلاح وضعیت logs
        $this->fixLogStatuses();

        $this->info('✅ اصلاح سریع تمام شد!');
    }

    private function fixNegativeExecutionTimes(): void
    {
        $this->info('⏱️ اصلاح زمان‌های منفی...');

        $logsWithNegativeTime = DB::table('execution_logs')
            ->where('execution_time', '<', 0)
            ->orWhereNull('execution_time')
            ->get();

        foreach ($logsWithNegativeTime as $log) {
            $correctTime = 0;

            // محاسبه زمان صحیح
            if ($log->started_at && $log->finished_at) {
                $startedAt = \Carbon\Carbon::parse($log->started_at);
                $finishedAt = \Carbon\Carbon::parse($log->finished_at);

                // اگر finished_at بعد از started_at باشد
                if ($finishedAt->gt($startedAt)) {
                    $correctTime = $finishedAt->diffInSeconds($startedAt);
                } else {
                    // اگر ترتیب اشتباه است، finished_at را اصلاح کن
                    $correctTime = now()->diffInSeconds($startedAt);
                    DB::table('execution_logs')
                        ->where('id', $log->id)
                        ->update(['finished_at' => now()]);
                }
            } elseif ($log->started_at) {
                $startedAt = \Carbon\Carbon::parse($log->started_at);
                $correctTime = now()->diffInSeconds($startedAt);

                // اگر finished_at موجود نیست، آن را تنظیم کن
                if (!$log->finished_at) {
                    DB::table('execution_logs')
                        ->where('id', $log->id)
                        ->update(['finished_at' => now()]);
                }
            }

            // بروزرسانی زمان اجرا
            if ($correctTime >= 0) {
                DB::table('execution_logs')
                    ->where('id', $log->id)
                    ->update([
                        'execution_time' => $correctTime,
                        'updated_at' => now()
                    ]);

                $this->info("✅ Log {$log->id}: زمان اجرا اصلاح شد به {$correctTime}s");
            }
        }
    }

    private function syncLogStatsWithConfigs(): void
    {
        $this->info('📊 همگام‌سازی آمار logs با کانفیگ‌ها...');

        $configs = DB::table('configs')->get();

        foreach ($configs as $config) {
            // آمار کانفیگ
            $configStats = [
                'total_processed' => $config->total_processed ?: 0,
                'total_success' => $config->total_success ?: 0,
                'total_failed' => $config->total_failed ?: 0,
            ];

            // یافتن logs با آمار ناقص
            $incompleteLog = DB::table('execution_logs')
                ->where('config_id', $config->id)
                ->where(function($query) {
                    $query->where('total_processed', 0)
                        ->orWhere('total_success', 0);
                })
                ->whereIn('status', ['stopped', 'completed'])
                ->orderBy('created_at', 'desc')
                ->first();

            if ($incompleteLog && $configStats['total_success'] > 0) {
                // محاسبه نرخ موفقیت
                $successRate = $configStats['total_processed'] > 0
                    ? round(($configStats['total_success'] / $configStats['total_processed']) * 100, 2)
                    : 0;

                DB::table('execution_logs')
                    ->where('id', $incompleteLog->id)
                    ->update([
                        'total_processed' => $configStats['total_processed'],
                        'total_success' => $configStats['total_success'],
                        'total_failed' => $configStats['total_failed'],
                        'success_rate' => $successRate,
                        'updated_at' => now()
                    ]);

                $this->info("📊 Log {$incompleteLog->id} (Config: {$config->name}): آمار همگام‌سازی شد");
                $this->info("   • پردازش: {$configStats['total_processed']}");
                $this->info("   • موفق: {$configStats['total_success']}");
                $this->info("   • خطا: {$configStats['total_failed']}");
            }
        }
    }

    private function fixLogStatuses(): void
    {
        $this->info('🔧 اصلاح وضعیت logs...');

        // یافتن logs در حال اجرا که کانفیگشان متوقف است
        $runningLogs = DB::table('execution_logs as el')
            ->join('configs as c', 'el.config_id', '=', 'c.id')
            ->where('el.status', 'running')
            ->where('c.is_running', false)
            ->select('el.*')
            ->get();

        foreach ($runningLogs as $log) {
            DB::table('execution_logs')
                ->where('id', $log->id)
                ->update([
                    'status' => 'stopped',
                    'stop_reason' => 'اصلاح شده در migration',
                    'error_message' => 'وضعیت از running به stopped اصلاح شد',
                    'updated_at' => now()
                ]);

            $this->info("✅ Log {$log->id}: وضعیت از running به stopped اصلاح شد");
        }
    }

    private function info(string $message): void
    {
        echo $message . "\n";
    }

    public function down(): void
    {
        // این migration اصلاحی است و rollback ندارد
    }
};
