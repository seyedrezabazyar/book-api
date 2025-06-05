<?php

namespace App\Jobs;

use App\Models\Config;
use App\Models\ScrapingFailure;
use App\Services\ScrapingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessScrapingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 دقیقه
    public $tries = 1; // فقط یک بار تلاش

    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function handle(): void
    {
        // بررسی امکان ادامه
        if (!$this->config->fresh()->canStart() && !$this->config->isRunning()) {
            Log::info("کانفیگ متوقف شده: {$this->config->name}");
            return;
        }

        try {
            $service = new ScrapingService($this->config);

            // پردازش تعداد مشخص شده رکورد
            for ($i = 0; $i < $this->config->records_per_run; $i++) {
                // بررسی وضعیت در هر مرحله
                if (!$this->config->fresh()->isRunning()) {
                    Log::info("اسکرپ متوقف شد: {$this->config->name}");
                    break;
                }

                $result = $service->processNext();

                if (!$result) {
                    // پایان داده‌ها یا خطا
                    $this->config->stop();
                    Log::info("اسکرپ تمام شد: {$this->config->name}");
                    break;
                }

                // تاخیر بین رکوردها (اگر بیش از یک رکورد در هر اجرا)
                if ($i < $this->config->records_per_run - 1) {
                    sleep(1);
                }
            }

            // برنامه‌ریزی اجرای بعدی
            if ($this->config->fresh()->isRunning()) {
                ProcessScrapingJob::dispatch($this->config)
                    ->delay(now()->addSeconds($this->config->delay_seconds));
            }

        } catch (\Exception $e) {
            Log::error("خطا در اسکرپ: {$this->config->name}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // ثبت شکست
            ScrapingFailure::logFailure(
                $this->config->id,
                $this->config->current_url ?? $this->config->base_url,
                $e->getMessage(),
                [
                    'line' => $e->getLine(),
                    'file' => $e->getFile(),
                    'trace' => $e->getTraceAsString()
                ]
            );

            $this->config->updateStats(false);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("شکست Job اسکرپ: {$this->config->name}", [
            'error' => $exception->getMessage()
        ]);

        $this->config->stop();

        ScrapingFailure::logFailure(
            $this->config->id,
            $this->config->current_url ?? $this->config->base_url,
            'شکست Job: ' . $exception->getMessage(),
            ['trace' => $exception->getTraceAsString()]
        );
    }
}
