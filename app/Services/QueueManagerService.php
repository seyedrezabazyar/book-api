<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\File;

class QueueManagerService
{
    private static string $pidFile = 'storage/framework/queue_worker.pid';
    private static string $logFile = 'storage/logs/queue_worker.log';

    /**
     * بررسی وضعیت Worker
     */
    public static function getWorkerStatus(): array
    {
        try {
            $pidFile = storage_path('framework/queue_worker.pid');

            if (!File::exists($pidFile)) {
                return [
                    'is_running' => false,
                    'pid' => null,
                    'message' => 'فایل PID وجود ندارد'
                ];
            }

            $pid = (int) File::get($pidFile);

            if (empty($pid)) {
                return [
                    'is_running' => false,
                    'pid' => null,
                    'message' => 'PID خالی است'
                ];
            }

            // بررسی اینکه Process هنوز در حال اجرا است
            $isRunning = self::isProcessRunning($pid);

            if (!$isRunning) {
                // اگر process متوقف شده، فایل PID را پاک کن
                File::delete($pidFile);
                return [
                    'is_running' => false,
                    'pid' => null,
                    'message' => 'Worker متوقف شده'
                ];
            }

            return [
                'is_running' => true,
                'pid' => $pid,
                'message' => 'Worker در حال اجرا'
            ];
        } catch (\Exception $e) {
            Log::error('خطا در بررسی وضعیت Worker', ['error' => $e->getMessage()]);

            return [
                'is_running' => false,
                'pid' => null,
                'message' => 'خطا در بررسی وضعیت: ' . $e->getMessage()
            ];
        }
    }

    /**
     * شروع Worker
     */
    public static function startWorker(): bool
    {
        try {
            $status = self::getWorkerStatus();

            if ($status['is_running']) {
                Log::info('Worker قبلاً در حال اجرا است', ['pid' => $status['pid']]);
                return true;
            }

            // مطمئن شوید که دایرکتوری‌های مورد نیاز وجود دارند
            $storagePath = storage_path('framework');
            if (!File::exists($storagePath)) {
                File::makeDirectory($storagePath, 0755, true);
            }

            $logsPath = storage_path('logs');
            if (!File::exists($logsPath)) {
                File::makeDirectory($logsPath, 0755, true);
            }

            $pidFile = storage_path('framework/queue_worker.pid');
            $logFile = storage_path('logs/queue_worker.log');

            // ساخت دستور اجرای Worker
            $phpPath = self::getPhpPath();
            $artisanPath = base_path('artisan');

            $command = sprintf(
                '%s %s queue:work --verbose --timeout=300 --tries=2 --sleep=3 --max-time=3600 > %s 2>&1 & echo $! > %s',
                $phpPath,
                $artisanPath,
                $logFile,
                $pidFile
            );

            Log::info('شروع Worker با دستور', ['command' => $command]);

            // اجرای دستور
            $output = shell_exec($command);

            // کمی صبر کنیم تا Worker شروع شود
            sleep(2);

            // بررسی اینکه Worker واقعاً شروع شده
            $status = self::getWorkerStatus();

            if ($status['is_running']) {
                Log::info('Worker با موفقیت شروع شد', ['pid' => $status['pid']]);
                return true;
            } else {
                Log::error('Worker شروع نشد', ['output' => $output]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error('خطا در شروع Worker', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * توقف Worker
     */
    public static function stopWorker(): bool
    {
        try {
            $status = self::getWorkerStatus();

            if (!$status['is_running']) {
                Log::info('Worker قبلاً متوقف شده');
                return true;
            }

            $pid = $status['pid'];

            // ارسال سیگنال TERM برای توقف مناسب
            $killed = self::killProcess($pid, 'TERM');

            if (!$killed) {
                // اگر TERM کار نکرد، از KILL استفاده کن
                Log::warning('TERM کار نکرد، استفاده از KILL', ['pid' => $pid]);
                $killed = self::killProcess($pid, 'KILL');
            }

            if ($killed) {
                // پاک کردن فایل PID
                $pidFile = storage_path('framework/queue_worker.pid');
                if (File::exists($pidFile)) {
                    File::delete($pidFile);
                }

                Log::info('Worker با موفقیت متوقف شد', ['pid' => $pid]);
                return true;
            } else {
                Log::error('نتوانستیم Worker را متوقف کنیم', ['pid' => $pid]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error('خطا در توقف Worker', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * راه‌اندازی مجدد Worker
     */
    public static function restartWorker(): bool
    {
        try {
            Log::info('شروع راه‌اندازی مجدد Worker');

            // اول توقف
            $stopped = self::stopWorker();

            if (!$stopped) {
                Log::error('نتوانستیم Worker را متوقف کنیم برای راه‌اندازی مجدد');
                return false;
            }

            // کمی صبر
            sleep(2);

            // سپس شروع
            $started = self::startWorker();

            if ($started) {
                Log::info('Worker با موفقیت راه‌اندازی مجدد شد');
                return true;
            } else {
                Log::error('نتوانستیم Worker را مجدداً شروع کنیم');
                return false;
            }
        } catch (\Exception $e) {
            Log::error('خطا در راه‌اندازی مجدد Worker', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * اطمینان از اینکه Worker در حال اجرا است
     */
    public static function ensureWorkerIsRunning(): bool
    {
        $status = self::getWorkerStatus();

        if ($status['is_running']) {
            return true;
        }

        Log::info('Worker در حال اجرا نیست، شروع می‌کنیم...');
        return self::startWorker();
    }

    /**
     * بررسی اینکه Process در حال اجرا است
     */
    private static function isProcessRunning(int $pid): bool
    {
        try {
            // در لینوکس/مک
            if (PHP_OS_FAMILY === 'Darwin' || PHP_OS_FAMILY === 'Linux') {
                $output = shell_exec("ps -p {$pid} -o pid= 2>/dev/null");
                return !empty(trim($output));
            }

            // در ویندوز
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

    /**
     * کشتن Process
     */
    private static function killProcess(int $pid, string $signal = 'TERM'): bool
    {
        try {
            // در لینوکس/مک
            if (PHP_OS_FAMILY === 'Darwin' || PHP_OS_FAMILY === 'Linux') {
                $command = "kill -{$signal} {$pid} 2>/dev/null";
                $result = shell_exec($command);

                // صبر کنیم ببینیم Process متوقف شده
                sleep(2);
                return !self::isProcessRunning($pid);
            }

            // در ویندوز
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

    /**
     * یافتن مسیر PHP
     */
    private static function getPhpPath(): string
    {
        // اول سعی کنیم PHP_BINARY را استفاده کنیم
        if (defined('PHP_BINARY') && !empty(PHP_BINARY)) {
            return PHP_BINARY;
        }

        // سپس دستورات مختلف را امتحان کنیم
        $possiblePaths = ['php', '/usr/bin/php', '/usr/local/bin/php', 'php8.2', 'php8.1', 'php8.0'];

        foreach ($possiblePaths as $path) {
            $result = shell_exec("which {$path} 2>/dev/null");
            if (!empty(trim($result))) {
                return trim($result);
            }
        }

        // پیش‌فرض
        return 'php';
    }

    /**
     * آمار صف
     */
    public static function getQueueStats(): array
    {
        try {
            return [
                'pending_jobs' => \DB::table('jobs')->count(),
                'failed_jobs' => \DB::table('failed_jobs')->count(),
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
