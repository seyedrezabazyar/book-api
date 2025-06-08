<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB; // اضافه کردن این خط مهم است

class Config extends Model
{
    protected $fillable = [
        'name',
        'base_url',
        'source_type',
        'source_name',
        'timeout',
        'delay_seconds',
        'records_per_run',
        'page_delay',
        'start_page',
        'max_pages',
        'current_page',
        'last_source_id',
        'auto_resume',
        'fill_missing_fields',
        'update_descriptions',
        'config_data',
        'created_by',
        'total_processed',
        'total_success',
        'total_failed',
        'last_run_at',
        'is_running'
    ];

    protected $casts = [
        'config_data' => 'array',
        'timeout' => 'integer',
        'delay_seconds' => 'integer',
        'records_per_run' => 'integer',
        'page_delay' => 'integer',
        'start_page' => 'integer',
        'max_pages' => 'integer',
        'current_page' => 'integer',
        'last_source_id' => 'integer',
        'total_processed' => 'integer',
        'total_success' => 'integer',
        'total_failed' => 'integer',
        'last_run_at' => 'datetime',
        'is_running' => 'boolean',
        'auto_resume' => 'boolean',
        'fill_missing_fields' => 'boolean',
        'update_descriptions' => 'boolean',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function executionLogs(): HasMany
    {
        return $this->hasMany(ExecutionLog::class);
    }

    public function bookSources(): HasMany
    {
        return $this->hasMany(BookSource::class, 'source_type', 'source_name');
    }

    public function scopeActive($query)
    {
        return $query; // همه کانفیگ‌ها فعال هستند
    }

    public function isActive(): bool
    {
        return true;
    }

    /**
     * تعیین صفحه شروع هوشمند
     */
    public function getSmartStartPage(): int
    {
        // اگر start_page مشخص شده، از آن استفاده کن
        if ($this->start_page && $this->start_page > 0) {
            Log::info("🎯 استفاده از start_page تعیین شده", [
                'config_id' => $this->id,
                'start_page' => $this->start_page
            ]);
            return $this->start_page;
        }

        // اگر auto_resume فعال باشد، از آخرین ID ادامه بده
        if ($this->auto_resume && $this->last_source_id > 0) {
            $nextId = $this->last_source_id + 1;
            Log::info("🔄 ادامه خودکار از آخرین ID", [
                'config_id' => $this->id,
                'last_source_id' => $this->last_source_id,
                'next_start' => $nextId
            ]);
            return $nextId;
        }

        // در غیر این صورت از 1 شروع کن
        Log::info("🆕 شروع جدید از صفحه 1", [
            'config_id' => $this->id
        ]);
        return 1;
    }

    /**
     * بروزرسانی آخرین source_id پردازش شده
     */
    public function updateLastSourceId(int $sourceId): void
    {
        if ($sourceId > $this->last_source_id) {
            DB::transaction(function () use ($sourceId) {
                $this->update(['last_source_id' => $sourceId]);
            });

            Log::info("📈 آخرین source_id بروزرسانی شد", [
                'config_id' => $this->id,
                'old_last_id' => $this->last_source_id,
                'new_last_id' => $sourceId
            ]);
        }
    }

    /**
     * بررسی اینکه آیا ID خاصی قبلاً پردازش شده
     */
    public function isSourceIdProcessed(int $sourceId): bool
    {
        return BookSource::where('source_type', $this->source_type)
            ->where('source_id', $sourceId)
            ->whereHas('book', function ($query) {
                // فقط کتاب‌هایی که واقعاً ثبت شده‌اند
                $query->where('status', 'active');
            })
            ->exists();
    }

    /**
     * ثبت شکست در دریافت ID خاص
     */
    public function logSourceIdFailure(int $sourceId, string $reason): void
    {
        ScrapingFailure::create([
            'config_id' => $this->id,
            'url' => $this->buildApiUrl($sourceId),
            'error_message' => "ID {$sourceId} not found: {$reason}",
            'error_details' => [
                'source_id' => $sourceId,
                'source_type' => $this->source_type,
                'source_name' => $this->source_name,
                'reason' => $reason
            ],
            'http_status' => 404,
            'retry_count' => 0,
            'last_attempt_at' => now()
        ]);

        Log::warning("❌ Source ID شکست خورد", [
            'config_id' => $this->id,
            'source_id' => $sourceId,
            'reason' => $reason
        ]);
    }

    /**
     * ساخت URL API برای ID خاص
     */
    public function buildApiUrl(int $sourceId): string
    {
        $apiSettings = $this->getApiSettings();
        $baseUrl = rtrim($this->base_url, '/');
        $endpoint = $apiSettings['endpoint'] ?? '';

        $fullUrl = $baseUrl . ($endpoint ? '/' . ltrim($endpoint, '/') : '');

        // اضافه کردن ID به URL
        if (strpos($fullUrl, '{id}') !== false) {
            $fullUrl = str_replace('{id}', $sourceId, $fullUrl);
        } else {
            $params = ['id' => $sourceId];
            if (!empty($apiSettings['params'])) {
                $params = array_merge($params, $apiSettings['params']);
            }
            $fullUrl .= '?' . http_build_query($params);
        }

        return $fullUrl;
    }

    public function getApiSettings(): array
    {
        return $this->config_data['api'] ?? [];
    }

    public function getGeneralSettings(): array
    {
        return $this->config_data['general'] ?? [];
    }

    public function getCrawlingSettings(): array
    {
        $crawling = $this->config_data['crawling'] ?? [];

        // اضافه کردن تنظیمات جدید
        $crawling['max_pages'] = $this->max_pages;
        $crawling['start_page'] = $this->getSmartStartPage();
        $crawling['auto_resume'] = $this->auto_resume;
        $crawling['fill_missing_fields'] = $this->fill_missing_fields;
        $crawling['update_descriptions'] = $this->update_descriptions;

        return $crawling;
    }

    public static function getBookFields(): array
    {
        return [
            'title' => 'عنوان کتاب',
            'description' => 'توضیحات',
            'author' => 'نویسنده',
            'publisher' => 'ناشر',
            'category' => 'دسته‌بندی',
            'isbn' => 'شابک',
            'publication_year' => 'سال انتشار',
            'pages_count' => 'تعداد صفحات',
            'language' => 'زبان',
            'format' => 'فرمت فایل',
            'file_size' => 'حجم فایل',
            'image_url' => 'تصویر کتاب'
        ];
    }

    public function updateProgress(int $currentSourceId, array $stats): void
    {
        Log::info("🔄 شروع بروزرسانی progress", [
            'config_id' => $this->id,
            'source_id' => $currentSourceId,
            'incoming_stats' => $stats,
            'current_stats' => [
                'total_processed' => $this->total_processed,
                'total_success' => $this->total_success,
                'total_failed' => $this->total_failed,
                'last_source_id' => $this->last_source_id
            ]
        ]);

        try {
            DB::transaction(function () use ($currentSourceId, $stats) {
                $config = Config::lockForUpdate()->find($this->id);

                if (!$config) {
                    throw new \Exception("کانفیگ {$this->id} یافت نشد");
                }

                // بروزرسانی آمار
                $totalToAdd = is_numeric($stats['total'] ?? 0) ? (int)($stats['total'] ?? 0) : 0;
                $successToAdd = is_numeric($stats['success'] ?? 0) ? (int)($stats['success'] ?? 0) : 0;
                $failedToAdd = is_numeric($stats['failed'] ?? 0) ? (int)($stats['failed'] ?? 0) : 0;

                $config->increment('total_processed', $totalToAdd);
                $config->increment('total_success', $successToAdd);
                $config->increment('total_failed', $failedToAdd);

                // بروزرسانی source_id اگر بزرگتر باشد
                if ($currentSourceId > $config->last_source_id) {
                    $config->update(['last_source_id' => $currentSourceId]);
                }

                $config->update([
                    'current_page' => $currentSourceId,
                    'last_run_at' => now(),
                ]);
            });

            $this->refresh();

            Log::info("✅ progress بروزرسانی شد", [
                'config_id' => $this->id,
                'source_id' => $currentSourceId,
                'new_stats' => [
                    'total_processed' => $this->total_processed,
                    'total_success' => $this->total_success,
                    'total_failed' => $this->total_failed,
                    'last_source_id' => $this->last_source_id
                ]
            ]);
        } catch (\Exception $e) {
            Log::error("❌ خطا در بروزرسانی progress", [
                'config_id' => $this->id,
                'error' => $e->getMessage(),
                'stats' => $stats
            ]);
            throw $e;
        }
    }

    // سایر متدهای قبلی...
    public function syncStatsFromLogs(): void
    {
        $completedLogs = $this->executionLogs()
            ->whereIn('status', ['completed', 'stopped'])
            ->get();

        $totalProcessed = $completedLogs->sum('total_processed');
        $totalSuccess = $completedLogs->sum('total_success');
        $totalFailed = $completedLogs->sum('total_failed');

        $this->update([
            'total_processed' => $totalProcessed,
            'total_success' => $totalSuccess,
            'total_failed' => $totalFailed,
        ]);
    }

    public function resetProgress(): void
    {
        $this->update([
            'current_page' => $this->getSmartStartPage(),
            'total_processed' => 0,
            'total_success' => 0,
            'total_failed' => 0,
            'is_running' => false,
        ]);
    }

    public function getLatestExecutionLog(): ?ExecutionLog
    {
        return $this->executionLogs()->latest()->first();
    }

    public function getDisplayStats(): array
    {
        $latestLog = $this->getLatestExecutionLog();

        return [
            'total_executions' => $this->executionLogs()->count(),
            'successful_executions' => $this->executionLogs()->where('status', 'completed')->count(),
            'failed_executions' => $this->executionLogs()->where('status', 'failed')->count(),
            'stopped_executions' => $this->executionLogs()->where('status', 'stopped')->count(),
            'total_books_processed' => $this->total_processed,
            'total_books_success' => $this->total_success,
            'total_books_failed' => $this->total_failed,
            'total_processed' => $this->total_processed,
            'total_success' => $this->total_success,
            'total_failed' => $this->total_failed,
            'success_rate' => $this->total_processed > 0
                ? round(($this->total_success / $this->total_processed) * 100, 2)
                : 0,
            'latest_execution_status' => $latestLog?->status,
            'latest_execution_time' => $latestLog?->started_at,
            'is_currently_running' => $this->is_running,
            'last_source_id' => $this->last_source_id,
            'next_source_id' => $this->getSmartStartPage()
        ];
    }

    public function getExecutionStats(): array
    {
        return [
            'total_executions' => $this->executionLogs()->count(),
            'completed_executions' => $this->executionLogs()->where('status', 'completed')->count(),
            'failed_executions' => $this->executionLogs()->where('status', 'failed')->count(),
            'stopped_executions' => $this->executionLogs()->where('status', 'stopped')->count(),
            'running_executions' => $this->executionLogs()->where('status', 'running')->count(),
        ];
    }
}
