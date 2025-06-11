<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;

class QueueManagerService
{
    private static string $pidFile = 'storage/framework/queue_worker.pid';
    private static string $logFile = 'storage/logs/queue_worker.log';

    public static function getWorkerStatus(): array
    {
        try {
            $pidFile = storage_path('framework/queue_worker.pid');

            if (!File::exists($pidFile)) {
                return ['is_running' => false, 'pid' => null, 'message' => 'فایل PID وجود ندارد'];
            }

            $pid = (int) File::get($pidFile);

            if (empty($pid)) {
                return ['is_running' => false, 'pid' => null, 'message' => 'PID خالی است'];
            }

            $isRunning = self::isProcessRunning($pid);

            if (!$isRunning) {
                File::delete($pidFile);
                return ['is_running' => false, 'pid' => null, 'message' => 'Worker متوقف شده'];
            }

            return ['is_running' => true, 'pid' => $pid, 'message' => 'Worker در حال اجرا'];

        } catch (\Exception $e) {
            Log::error('خطا در بررسی وضعیت Worker', ['error' => $e->getMessage()]);
            return ['is_running' => false, 'pid' => null, 'message' => 'خطا در بررسی وضعیت: ' . $e->getMessage()];
        }
    }

    public static function startWorker(): bool
    {
        try {
            $status = self::getWorkerStatus();

            if ($status['is_running']) {
                Log::info('Worker قبلاً در حال اجرا است', ['pid' => $status['pid']]);
                return true;
            }

            self::ensureDirectoriesExist();

            $pidFile = storage_path('framework/queue_worker.pid');
            $logFile = storage_path('logs/queue_worker.log');
            $phpPath = self::getPhpPath();
            $artisanPath = base_path('artisan');

            $command = sprintf(
                '%s %s queue:work --verbose --timeout=300 --tries=2 --sleep=3 --max-time=3600 > %s 2>&1 & echo $! > %s',
                $phpPath, $artisanPath, $logFile, $pidFile
            );

            Log::info('شروع Worker با دستور', ['command' => $command]);

            shell_exec($command);
            sleep(2);

            $status = self::getWorkerStatus();
            $success = $status['is_running'];

            if ($success) {
                Log::info('Worker با موفقیت شروع شد', ['pid' => $status['pid']]);
            } else {
                Log::error('Worker شروع نشد');
            }

            return $success;

        } catch (\Exception $e) {
            Log::error('خطا در شروع Worker', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public static function stopWorker(): bool
    {
        try {
            $status = self::getWorkerStatus();

            if (!$status['is_running']) {
                Log::info('Worker قبلاً متوقف شده');
                return true;
            }

            $pid = $status['pid'];
            $killed = self::killProcess($pid, 'TERM') ?: self::killProcess($pid, 'KILL');

            if ($killed) {
                $pidFile = storage_path('framework/queue_worker.pid');
                if (File::exists($pidFile)) {
                    File::delete($pidFile);
                }
                Log::info('Worker با موفقیت متوقف شد', ['pid' => $pid]);
                return true;
            }

            Log::error('نتوانستیم Worker را متوقف کنیم', ['pid' => $pid]);
            return false;

        } catch (\Exception $e) {
            Log::error('خطا در توقف Worker', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public static function restartWorker(): bool
    {
        try {
            Log::info('شروع راه‌اندازی مجدد Worker');

            if (!self::stopWorker()) {
                Log::error('نتوانستیم Worker را متوقف کنیم برای راه‌اندازی مجدد');
                return false;
            }

            sleep(2);
            $started = self::startWorker();

            if ($started) {
                Log::info('Worker با موفقیت راه‌اندازی مجدد شد');
            } else {
                Log::error('نتوانستیم Worker را مجدداً شروع کنیم');
            }

            return $started;

        } catch (\Exception $e) {
            Log::error('خطا در راه‌اندازی مجدد Worker', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public static function ensureWorkerIsRunning(): bool
    {
        $status = self::getWorkerStatus();
        return $status['is_running'] ?: self::startWorker();
    }

    private static function ensureDirectoriesExist(): void
    {
        $paths = [storage_path('framework'), storage_path('logs')];

        foreach ($paths as $path) {
            if (!File::exists($path)) {
                File::makeDirectory($path, 0755, true);
            }
        }
    }

    private static function isProcessRunning(int $pid): bool
    {
        try {
            if (PHP_OS_FAMILY === 'Darwin' || PHP_OS_FAMILY === 'Linux') {
                $output = shell_exec("ps -p {$pid} -o pid= 2>/dev/null");
                return !empty(trim($output));
            }

            if (PHP_OS_FAMILY === 'Windows') {
                $output = shell_exec("tasklist /FI \"PID eq {$pid}\" 2>nul");
                return strpos($output, (string) $pid) !== false;
            }

            return false;
        } catch (\Exception $e) {
            Log::error('خطا در بررسی Process', ['pid' => $pid, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private static function killProcess(int $pid, string $signal = 'TERM'): bool
    {
        try {
            if (PHP_OS_FAMILY === 'Darwin' || PHP_OS_FAMILY === 'Linux') {
                shell_exec("kill -{$signal} {$pid} 2>/dev/null");
                sleep(2);
                return !self::isProcessRunning($pid);
            }

            if (PHP_OS_FAMILY === 'Windows') {
                $command = $signal === 'KILL' ? "taskkill /F /PID {$pid}" : "taskkill /PID {$pid}";
                shell_exec($command . " 2>nul");
                sleep(2);
                return !self::isProcessRunning($pid);
            }

            return false;
        } catch (\Exception $e) {
            Log::error('خطا در کشتن Process', ['pid' => $pid, 'signal' => $signal, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private static function getPhpPath(): string
    {
        if (defined('PHP_BINARY') && !empty(PHP_BINARY)) {
            return PHP_BINARY;
        }

        $possiblePaths = ['php', '/usr/bin/php', '/usr/local/bin/php', 'php8.2', 'php8.1', 'php8.0'];

        foreach ($possiblePaths as $path) {
            $result = shell_exec("which {$path} 2>/dev/null");
            if (!empty(trim($result))) {
                return trim($result);
            }
        }

        return 'php';
    }

    public static function getQueueStats(): array
    {
        try {
            return [
                'pending_jobs' => DB::table('jobs')->count(),
                'failed_jobs' => DB::table('failed_jobs')->count(),
                'worker_status' => self::getWorkerStatus()
            ];
        } catch (\Exception $e) {
            Log::error('خطا در دریافت آمار صف', ['error' => $e->getMessage()]);
            return [
                'pending_jobs' => 0,
                'failed_jobs' => 0,
                'worker_status' => ['is_running' => false, 'pid' => null]
            ];
        }
    }
}
