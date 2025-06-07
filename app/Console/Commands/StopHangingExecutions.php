<?php

namespace App\Console\Commands;

use App\Models\Config;
use App\Models\ExecutionLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StopHangingExecutions extends Command
{
    protected $signature = 'execution:stop-hanging
                          {--force : متوقف کردن اجباری بدون تأیید}
                          {--timeout=300 : اجراهای بیش از این مدت (ثانیه) معلق در نظر گرفته می‌شوند}';

    protected $description = 'متوقف کردن اجراهای معلق و تمیز کردن وضعیت';

    public function handle()
    {
        $force = $this->option('force');
        $timeout = (int)$this->option('timeout');

        $this->info('🔍 جستجوی اجراهای معلق...');

        // 1. یافتن کانفیگ‌هایی که is_running = true اما ExecutionLog ندارند
        $orphanedConfigs = $this->findOrphanedConfigs();

        // 2. یافتن ExecutionLog هایی که status = running اما کانفیگشان متوقف است
        $orphanedLogs = $this->findOrphanedLogs();

        // 3. یافتن اجراهای طولانی مدت
        $longRunningExecutions = $this->findLongRunningExecutions($timeout);

        // 4. حذف Jobs معلق
        $hangingJobs = $this->findHangingJobs();

        $totalIssues = count($orphanedConfigs) + count($orphanedLogs) + count($longRunningExecutions) + $hangingJobs;

        if ($totalIssues === 0) {
            $this->info('✅ هیچ مشکلی یافت نشد!');
            return 0;
        }

        $this->warn("⚠️ {$totalIssues} مشکل یافت شد:");
        $this->line("   • {count($orphanedConfigs)} کانفیگ یتیم");
        $this->line("   • {count($orphanedLogs)} ExecutionLog یتیم");
        $this->line("   • {count($longRunningExecutions)} اجرای طولانی‌مدت");
        $this->line("   • {$hangingJobs} Job معلق");

        if (!$force && !$this->confirm('آیا می‌خواهید این مشکلات را اصلاح کنید؟')) {
            $this->info('❌ عملیات لغو شد');
            return 1;
        }

        // اصلاح مشکلات
        $this->fixOrphanedConfigs($orphanedConfigs);
        $this->fixOrphanedLogs($orphanedLogs);
        $this->fixLongRunningExecutions($longRunningExecutions);
        $this->cleanupHangingJobs();

        $this->info('✅ تمامی مشکلات اصلاح شد!');
        return 0;
    }

    private function findOrphanedConfigs(): array
    {
        return Config::where('is_running', true)
            ->whereDoesntHave('executionLogs', function($query) {
                $query->where('status', 'running');
            })
            ->get()
            ->toArray();
    }

    private function findOrphanedLogs(): array
    {
        return ExecutionLog::where('status', 'running')
            ->whereHas('config', function($query) {
                $query->where('is_running', false);
            })
            ->get()
            ->toArray();
    }

    private function findLongRunningExecutions(int $timeout): array
    {
        return ExecutionLog::where('status', 'running')
            ->where('started_at', '<', now()->subSeconds($timeout))
            ->get()
            ->toArray();
    }

    private function findHangingJobs(): int
    {
        return DB::table('jobs')
            ->where('payload', 'like', '%ProcessSinglePageJob%')
            ->count();
    }

    private function fixOrphanedConfigs(array $configs): void
    {
        if (empty($configs)) return;

        $this->info('🔧 اصلاح کانفیگ‌های یتیم...');

        foreach ($configs as $configData) {
            $config = Config::find($configData['id']);
            if ($config) {
                $config->update(['is_running' => false]);
                $this->line("   ✅ Config {$config->id} ({$config->name}) متوقف شد");

                Log::info("🔧 کانفیگ یتیم اصلاح شد", [
                    'config_id' => $config->id,
                    'name' => $config->name
                ]);
            }
        }
    }

    private function fixOrphanedLogs(array $logs): void
    {
        if (empty($logs)) return;

        $this->info('🔧 اصلاح ExecutionLog های یتیم...');

        foreach ($logs as $logData) {
            $log = ExecutionLog::find($logData['id']);
            if ($log) {
                $executionTime = $log->started_at ? now()->diffInSeconds($log->started_at) : 0;

                $log->update([
                    'status' => ExecutionLog::STATUS_STOPPED,
                    'stop_reason' => 'اصلاح شده - کانفیگ متوقف بود',
                    'error_message' => 'ExecutionLog یتیم اصلاح شد',
                    'execution_time' => $executionTime,
                    'finished_at' => now()
                ]);

                $log->addLogEntry('🔧 ExecutionLog یتیم اصلاح شد', [
                    'fixed_by' => 'stop-hanging command',
                    'execution_time' => $executionTime,
                    'fixed_at' => now()->toISOString()
                ]);

                $this->line("   ✅ ExecutionLog {$log->execution_id} اصلاح شد");

                Log::info("🔧 ExecutionLog یتیم اصلاح شد", [
                    'execution_id' => $log->execution_id,
                    'execution_time' => $executionTime
                ]);
            }
        }
    }

    private function fixLongRunningExecutions(array $executions): void
    {
        if (empty($executions)) return;

        $this->info('🔧 اصلاح اجراهای طولانی‌مدت...');

        foreach ($executions as $executionData) {
            $execution = ExecutionLog::find($executionData['id']);
            if ($execution) {
                $executionTime = $execution->started_at ? now()->diffInSeconds($execution->started_at) : 0;
                $config = $execution->config;

                // متوقف کردن کانفیگ
                if ($config && $config->is_running) {
                    $config->update(['is_running' => false]);
                }

                // متوقف کردن ExecutionLog
                $execution->update([
                    'status' => ExecutionLog::STATUS_STOPPED,
                    'stop_reason' => 'متوقف شده - اجرای طولانی‌مدت',
                    'error_message' => "اجرای بیش از {$executionTime}s متوقف شد",
                    'execution_time' => $executionTime,
                    'finished_at' => now()
                ]);

                $execution->addLogEntry('⏰ اجرای طولانی‌مدت متوقف شد', [
                    'execution_time' => $executionTime,
                    'stopped_by' => 'stop-hanging command',
                    'stopped_at' => now()->toISOString()
                ]);

                $this->line("   ✅ اجرای طولانی {$execution->execution_id} متوقف شد ({$executionTime}s)");

                Log::info("⏰ اجرای طولانی‌مدت متوقف شد", [
                    'execution_id' => $execution->execution_id,
                    'execution_time' => $executionTime,
                    'config_id' => $config ? $config->id : null
                ]);
            }
        }
    }

    private function cleanupHangingJobs(): void
    {
        $this->info('🗑️ پاکسازی Jobs معلق...');

        // حذف Jobs مرتبط با کانفیگ‌هایی که دیگر running نیستند
        $runningConfigIds = Config::where('is_running', true)->pluck('id')->toArray();

        $deletedJobs = 0;

        if (empty($runningConfigIds)) {
            // اگر هیچ کانفیگی running نیست، همه Jobs را حذف کن
            $deletedJobs = DB::table('jobs')->where('payload', 'like', '%ProcessSinglePageJob%')->delete();
        } else {
            // فقط Jobs مرتبط با کانفیگ‌های غیرفعال را حذف کن
            foreach (Config::where('is_running', false)->get() as $config) {
                $deleted = DB::table('jobs')
                    ->where('payload', 'like', '%"configId":' . $config->id . '%')
                    ->delete();
                $deletedJobs += $deleted;
            }
        }

        if ($deletedJobs > 0) {
            $this->line("   🗑️ {$deletedJobs} Job معلق حذف شد");
            Log::info("🗑️ Jobs معلق پاکسازی شد", ['deleted_count' => $deletedJobs]);
        } else {
            $this->line("   ✅ هیچ Job معلقی یافت نشد");
        }
    }
}
