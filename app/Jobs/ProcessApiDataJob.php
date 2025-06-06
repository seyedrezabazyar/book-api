<?php

namespace App\Jobs;

use App\Models\Config;
use App\Services\ApiDataService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessApiDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 ساعت timeout
    public $tries = 3; // تعداد تلاش در صورت خطا

    protected Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;

        // تنظیم صف مخصوص برای کانفیگ‌های کند
        if ($config->delay_seconds >= 5) {
            $this->onQueue('slow');
        } else {
            $this->onQueue('default');
        }
    }

    public function handle(): void
    {
        try {
            Log::info("شروع Job برای کانفیگ: {$this->config->name}", ['config_id' => $this->config->id]);

            // بررسی وضعیت کانفیگ
            $this->config->refresh();

            if (!$this->config->isActive()) {
                Log::info("کانفیگ غیرفعال است", ['config_id' => $this->config->id]);
                return;
            }

            // اجرای سرویس
            $service = new ApiDataService($this->config);
            $stats = $service->fetchData();

            Log::info("Job تمام شد", [
                'config_id' => $this->config->id,
                'stats' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error("خطا در Job", [
                'config_id' => $this->config->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // در صورت خطا، کانفیگ را از حالت running خارج کن
            $this->config->update(['is_running' => false]);

            throw $e; // برای retry mechanism
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Job نهایتاً ناموفق شد", [
            'config_id' => $this->config->id,
            'error' => $exception->getMessage()
        ]);

        // کانفیگ را از حالت running خارج کن
        $this->config->update(['is_running' => false]);
    }
}
