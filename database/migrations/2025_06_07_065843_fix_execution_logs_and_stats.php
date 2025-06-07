<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->info('🔧 شروع اصلاح آمار execution logs و configs...');

        // 1. اصلاح execution logs موجود
        $this->fixExistingExecutionLogs();

        // 2. همگام‌سازی آمار کانفیگ‌ها
        $this->syncConfigStats();

        // 3. بررسی تطابق نهایی
        $this->validateDataConsistency();

        $this->info('✅ اصلاح آمار تمام شد!');
    }

    private function fixExistingExecutionLogs(): void
    {
        $this->info('🔍 بررسی execution logs...');

        // یافتن logs در حال اجرا که کانفیگشان متوقف است
        $runningLogs = DB::table('execution_logs')
            ->where('status', 'running')
            ->get();

        if ($runningLogs->isEmpty()) {
            $this->info('✅ همه execution logs صحیح هستند');
            return;
        }

        foreach ($runningLogs as $log) {
            $config = DB::table('configs')->where('id', $log->config_id)->first();

            if (!$config) {
                $this->warn("⚠️ کانفیگ {$log->config_id} برای log {$log->id} یافت نشد");
                continue;
            }

            if (!$config->is_running) {
                // محاسبه زمان اجرا
                $executionTime = $log->started_at ?
                    now()->diffInSeconds(\Carbon\Carbon::parse($log->started_at)) : 0;

                // آمار نهایی از کانفیگ
                $finalStats = [
                    'total_processed' => $config->total_processed ?: 0,
                    'total_success' => $config->total_success ?: 0,
                    'total_failed' => $config->total_failed ?: 0,
                    'success_rate' => $config->total_processed > 0 ?
                        round(($config->total_success / $config->total_processed) * 100, 2) : 0,
                ];

                // بروزرسانی log
                DB::table('execution_logs')
                    ->where('id', $log->id)
                    ->update([
                        'status' => 'stopped',
                        'total_processed' => $finalStats['total_processed'],
                        'total_success' => $finalStats['total_success'],
                        'total_failed' => $finalStats['total_failed'],
                        'success_rate' => $finalStats['success_rate'],
                        'execution_time' => $executionTime,
                        'finished_at' => now(),
                        'last_activity_at' => now(),
                        'stop_reason' => 'متوقف شده توسط کاربر',
                        'error_message' => 'اصلاح شده در migration - execution log از running به stopped تغییر کرد',
                        'final_summary' => json_encode([
                            'fixed_in_migration' => true,
                            'original_status' => 'running',
                            'final_stats' => $finalStats,
                            'fixed_at' => now()->toISOString(),
                        ]),
                        'updated_at' => now()
                    ]);

                $this->info("✅ Log {$log->id} اصلاح شد: running → stopped ({$finalStats['total_success']} کتاب موفق)");
            }
        }
    }

    private function syncConfigStats(): void
    {
        $this->info('📊 همگام‌سازی آمار کانفیگ‌ها...');

        $configs = DB::table('configs')->get();
        $repairedCount = 0;

        foreach ($configs as $config) {
            // محاسبه آمار صحیح از execution logs
            $logStats = DB::table('execution_logs')
                ->where('config_id', $config->id)
                ->whereIn('status', ['completed', 'stopped'])
                ->selectRaw('
                    SUM(total_processed) as total_processed,
                    SUM(total_success) as total_success,
                    SUM(total_failed) as total_failed
                ')
                ->first();

            $correctStats = [
                'total_processed' => $logStats->total_processed ?: 0,
                'total_success' => $logStats->total_success ?: 0,
                'total_failed' => $logStats->total_failed ?: 0,
            ];

            $currentStats = [
                'total_processed' => $config->total_processed ?: 0,
                'total_success' => $config->total_success ?: 0,
                'total_failed' => $config->total_failed ?: 0,
            ];

            // بررسی نیاز به بروزرسانی
            $needsUpdate = false;
            $updates = [];

            foreach ($correctStats as $field => $correctValue) {
                if ($correctValue > $currentStats[$field]) {
                    $needsUpdate = true;
                    $updates[$field] = $correctValue;
                }
            }

            if ($needsUpdate) {
                DB::table('configs')
                    ->where('id', $config->id)
                    ->update(array_merge($updates, ['updated_at' => now()]));

                $repairedCount++;
                $this->info("📊 کانفیگ {$config->id} ({$config->name}) بروزرسانی شد:");
                foreach ($updates as $field => $newValue) {
                    $oldValue = $currentStats[$field];
                    $this->info("   • {$field}: {$oldValue} → {$newValue}");
                }
            }
        }

        if ($repairedCount === 0) {
            $this->info('✅ آمار همه کانفیگ‌ها صحیح است');
        } else {
            $this->info("✅ {$repairedCount} کانفیگ بروزرسانی شد");
        }
    }

    private function validateDataConsistency(): void
    {
        $this->info('🔍 بررسی تطابق نهایی...');

        // آمار کلی
        $totalBooks = DB::table('books')->count();
        $totalConfigSuccess = DB::table('configs')->sum('total_success');
        $totalLogSuccess = DB::table('execution_logs')
            ->whereIn('status', ['completed', 'stopped'])
            ->sum('total_success');

        $this->info("📚 کل کتاب‌های دیتابیس: {$totalBooks}");
        $this->info("📊 کل موفقیت کانفیگ‌ها: {$totalConfigSuccess}");
        $this->info("📊 کل موفقیت execution logs: {$totalLogSuccess}");

        // بررسی تطابق
        if ($totalConfigSuccess === $totalLogSuccess) {
            $this->info('✅ آمار کانفیگ‌ها و logs همگام هستند');
        } else {
            $diff = abs($totalConfigSuccess - $totalLogSuccess);
            $this->warn("⚠️ اختلاف {$diff} بین آمار کانفیگ‌ها و logs");
        }

        // تطابق با کتاب‌های واقعی
        if ($totalBooks >= $totalConfigSuccess) {
            $this->info('✅ تعداد کتاب‌ها منطقی است');
        } else {
            $diff = $totalConfigSuccess - $totalBooks;
            $this->error("❌ {$diff} کتاب کم در دیتابیس!");
        }

        // نمایش آمار هر کانفیگ
        $configs = DB::table('configs')->orderBy('created_at')->get();
        $this->info("\n📋 آمار نهایی هر کانفیگ:");
        foreach ($configs as $config) {
            $booksAfterConfig = DB::table('books')
                ->where('created_at', '>=', $config->created_at)
                ->count();

            $this->info("   • {$config->name}: {$config->total_success} موفق / {$booksAfterConfig} کتاب بعد از ایجاد");
        }
    }

    private function info(string $message): void
    {
        echo $message . "\n";
    }

    private function warn(string $message): void
    {
        echo "⚠️ " . $message . "\n";
    }

    private function error(string $message): void
    {
        echo "❌ " . $message . "\n";
    }

    public function down(): void
    {
        // برای این migration، rollback معنی‌دار نیست
        // چون اصلاحات انجام شده مفید و ضروری هستند
    }
};
