<?php

namespace App\Jobs;

use App\Models\Config;
use App\Models\ExecutionLog;
use App\Models\Book;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class UpdateStatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 دقیقه
    public $tries = 2;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        try {
            Log::info('🔄 شروع بروزرسانی دوره‌ای آمار');

            // جلوگیری از اجرای همزمان
            $lockKey = 'update_stats_job_running';
            if (Cache::has($lockKey)) {
                Log::info('⏭️ Job دیگری در حال اجرا است، رد شد');
                return;
            }

            Cache::put($lockKey, true, 300); // 5 دقیقه lock

            $this->updateExecutionLogsStats();
            $this->updateConfigStats();
            $this->updateGlobalStats();

            Cache::forget($lockKey);
            Log::info('✅ بروزرسانی دوره‌ای آمار تمام شد');

        } catch (\Exception $e) {
            Cache::forget('update_stats_job_running');
            Log::error('❌ خطا در بروزرسانی آمار', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * بروزرسانی آمار execution logs در حال اجرا
     */
    private function updateExecutionLogsStats(): void
    {
        $runningLogs = ExecutionLog::where('status', 'running')->get();

        foreach ($runningLogs as $log) {
            $config = $log->config;

            if (!$config) {
                continue;
            }

            // اگر کانفیگ در حال اجرا نیست، log را متوقف کن
            if (!$config->is_running) {
                $executionTime = $log->started_at ? now()->diffInSeconds($log->started_at) : 0;

                $log->update([
                    'status' => 'stopped',
                    'total_processed' => $config->total_processed,
                    'total_success' => $config->total_success,
                    'total_failed' => $config->total_failed,
                    'execution_time' => $executionTime,
                    'finished_at' => now(),
                    'error_message' => 'متوقف شده (بروزرسانی خودکار)'
                ]);

                $log->addLogEntry('آمار نهایی بروزرسانی شد', [
                    'updated_by' => 'UpdateStatsJob',
                    'final_stats' => [
                        'total_processed' => $config->total_processed,
                        'total_success' => $config->total_success,
                        'total_failed' => $config->total_failed
                    ]
                ]);

                Log::info("📊 Execution log {$log->id} اصلاح شد: running → stopped");
            }
        }
    }

    /**
     * بروزرسانی آمار کانفیگ‌ها از execution logs
     */
    private function updateConfigStats(): void
    {
        $configs = Config::all();

        foreach ($configs as $config) {
            $oldStats = [
                'total_processed' => $config->total_processed,
                'total_success' => $config->total_success,
                'total_failed' => $config->total_failed
            ];

            // همگام‌سازی از execution logs
            $config->syncStatsFromLogs();
            $config->refresh();

            $newStats = [
                'total_processed' => $config->total_processed,
                'total_success' => $config->total_success,
                'total_failed' => $config->total_failed
            ];

            if ($oldStats !== $newStats) {
                Log::info("📊 Config {$config->id} آمار بروزرسانی شد", [
                    'old_stats' => $oldStats,
                    'new_stats' => $newStats
                ]);
            }
        }
    }

    /**
     * بروزرسانی آمار کلی و cache
     */
    private function updateGlobalStats(): void
    {
        $globalStats = [
            'total_configs' => Config::count(),
            'active_configs' => Config::where('status', 'active')->count(),
            'running_configs' => Config::where('is_running', true)->count(),
            'total_books' => Book::count(),
            'total_executions' => ExecutionLog::count(),
            'successful_executions' => ExecutionLog::where('status', 'completed')->count(),
            'stopped_executions' => ExecutionLog::where('status', 'stopped')->count(),
            'failed_executions' => ExecutionLog::where('status', 'failed')->count(),
            'total_processed_books' => Config::sum('total_processed'),
            'total_successful_books' => Config::sum('total_success'),
            'books_today' => Book::whereDate('created_at', today())->count(),
            'books_this_week' => Book::whereBetween('created_at', [
                now()->startOfWeek(),
                now()->endOfWeek()
            ])->count(),
            'updated_at' => now()->toISOString()
        ];

        // ذخیره در cache برای 1 ساعت
        Cache::put('global_stats', $globalStats, 3600);

        Log::info('📊 آمار کلی بروزرسانی شد', $globalStats);
    }

    /**
     * دریافت آمار کلی (برای استفاده در controller ها)
     */
    public static function getGlobalStats(): array
    {
        return Cache::remember('global_stats', 3600, function () {
            return [
                'total_configs' => Config::count(),
                'active_configs' => Config::where('status', 'active')->count(),
                'running_configs' => Config::where('is_running', true)->count(),
                'total_books' => Book::count(),
                'total_executions' => ExecutionLog::count(),
                'successful_executions' => ExecutionLog::where('status', 'completed')->count(),
                'stopped_executions' => ExecutionLog::where('status', 'stopped')->count(),
                'failed_executions' => ExecutionLog::where('status', 'failed')->count(),
                'total_processed_books' => Config::sum('total_processed'),
                'total_successful_books' => Config::sum('total_success'),
                'books_today' => Book::whereDate('created_at', today())->count(),
                'books_this_week' => Book::whereBetween('created_at', [
                    now()->startOfWeek(),
                    now()->endOfWeek()
                ])->count(),
                'updated_at' => now()->toISOString()
            ];
        });
    }

    public function failed(\Throwable $exception): void
    {
        Cache::forget('update_stats_job_running');
        Log::error('❌ UpdateStatsJob نهایتاً ناموفق شد', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
