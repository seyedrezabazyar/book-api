<?php

namespace App\Jobs;

use App\Models\Config;
use App\Models\ExecutionLog;
use App\Services\ApiDataService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessSinglePageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 دقیقه
    public $tries = 2;

    protected Config $config;
    protected int $pageNumber;
    protected string $executionId;

    public function __construct(Config $config, int $pageNumber, string $executionId)
    {
        $this->config = $config;
        $this->pageNumber = $pageNumber;
        $this->executionId = $executionId;

        // همیشه در صف default قرار بگیرد
        $this->onQueue('default');

        // تأخیر بین job ها بر اساس تنظیمات کانفیگ
        if ($config->page_delay > 0 && $pageNumber > 1) {
            $this->delay(now()->addSeconds($config->page_delay * ($pageNumber - 1)));
        }
    }

    public function handle(): void
    {
        try {
            Log::info("🚀 شروع پردازش صفحه {$this->pageNumber}", [
                'config_id' => $this->config->id,
                'execution_id' => $this->executionId
            ]);

            // Refresh کانفیگ برای اطمینان از آخرین وضعیت
            $this->config->refresh();

            if (!$this->config->isActive()) {
                Log::info("⚠️ کانفیگ غیرفعال شده، Job متوقف می‌شود", [
                    'config_id' => $this->config->id,
                    'page' => $this->pageNumber
                ]);
                return;
            }

            // پیدا کردن لاگ اجرا
            $executionLog = ExecutionLog::where('execution_id', $this->executionId)->first();

            if (!$executionLog) {
                Log::error("❌ لاگ اجرا یافت نشد", ['execution_id' => $this->executionId]);
                return;
            }

            // چک کردن pageNumber برای پایان
            if ($this->pageNumber === -1) {
                // این Job برای پایان دادن به اجرا است
                $this->completeExecution($executionLog);
                return;
            }

            // اجرای سرویس برای یک صفحه
            $service = new ApiDataService($this->config);
            $pageStats = $service->processPage($this->pageNumber, $executionLog);

            // بروزرسانی پیشرفت
            $this->config->updateProgress($this->pageNumber, $pageStats);

            Log::info("✅ صفحه {$this->pageNumber} پردازش شد", [
                'config_id' => $this->config->id,
                'stats' => $pageStats
            ]);

        } catch (\Exception $e) {
            Log::error("❌ خطا در پردازش صفحه {$this->pageNumber}", [
                'config_id' => $this->config->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("❌ پردازش صفحه {$this->pageNumber} نهایتاً ناموفق شد", [
            'config_id' => $this->config->id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        // کانفیگ را از حالت running خارج کن
        $this->config->update(['is_running' => false]);
    }

    private function completeExecution(ExecutionLog $executionLog): void
    {
        try {
            // آمار نهایی از کانفیگ
            $finalStats = [
                'total' => $this->config->total_processed,
                'success' => $this->config->total_success,
                'failed' => $this->config->total_failed,
                'duplicate' => 0,
                'execution_time' => now()->diffInSeconds($executionLog->started_at)
            ];

            $executionLog->markCompleted($finalStats);
            $this->config->update(['is_running' => false]);

            Log::info("🎉 اجرا کامل شد", [
                'config_id' => $this->config->id,
                'execution_id' => $this->executionId,
                'final_stats' => $finalStats
            ]);

        } catch (\Exception $e) {
            Log::error("❌ خطا در تکمیل اجرا", [
                'error' => $e->getMessage(),
                'execution_id' => $this->executionId
            ]);
        }
    }
}
