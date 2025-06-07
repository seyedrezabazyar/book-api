<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Config extends Model
{
    protected $fillable = [
        'name', 'description', 'base_url', 'timeout', 'delay_seconds',
        'records_per_run', 'page_delay', 'crawl_mode', 'start_page',
        'config_data', 'status', 'created_by', 'current_page', 'total_processed',
        'total_success', 'total_failed', 'last_run_at', 'is_running'
    ];

    protected $casts = [
        'config_data' => 'array',
        'timeout' => 'integer',
        'delay_seconds' => 'integer',
        'records_per_run' => 'integer',
        'page_delay' => 'integer',
        'start_page' => 'integer',
        'current_page' => 'integer',
        'total_processed' => 'integer',
        'total_success' => 'integer',
        'total_failed' => 'integer',
        'last_run_at' => 'datetime',
        'is_running' => 'boolean',
    ];

    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_DRAFT = 'draft';

    const CRAWL_CONTINUE = 'continue';
    const CRAWL_RESTART = 'restart';
    const CRAWL_UPDATE = 'update';

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function executionLogs(): HasMany
    {
        return $this->hasMany(ExecutionLog::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
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
        return $this->config_data['crawling'] ?? [];
    }

    public static function getStatuses(): array
    {
        return [
            self::STATUS_ACTIVE => 'فعال',
            self::STATUS_INACTIVE => 'غیرفعال',
            self::STATUS_DRAFT => 'پیش‌نویس'
        ];
    }

    public static function getCrawlModes(): array
    {
        return [
            self::CRAWL_CONTINUE => 'ادامه از آخرین صفحه',
            self::CRAWL_RESTART => 'شروع مجدد از ابتدا',
            self::CRAWL_UPDATE => 'به‌روزرسانی صفحات قبلی'
        ];
    }

    public function getStatusTextAttribute(): string
    {
        return self::getStatuses()[$this->status] ?? 'نامشخص';
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

    public function updateProgress(int $currentPage, array $stats): void
    {
        Log::info("🔄 شروع بروزرسانی progress", [
            'config_id' => $this->id,
            'page' => $currentPage,
            'incoming_stats' => $stats,
            'current_stats' => [
                'total_processed' => $this->total_processed,
                'total_success' => $this->total_success,
                'total_failed' => $this->total_failed
            ]
        ]);

        try {
            // اضافه کردن آمار جدید به آمار قبلی با استفاده از DB transaction
            DB::transaction(function () use ($currentPage, $stats) {
                // قفل کردن سطر برای جلوگیری از race condition
                $config = Config::lockForUpdate()->find($this->id);

                if (!$config) {
                    throw new \Exception("کانفیگ {$this->id} یافت نشد");
                }

                // بروزرسانی آمار - اطمینان از integer بودن مقادیر
                $totalToAdd = is_numeric($stats['total'] ?? 0) ? (int)($stats['total'] ?? 0) : 0;
                $successToAdd = is_numeric($stats['success'] ?? 0) ? (int)($stats['success'] ?? 0) : 0;
                $failedToAdd = is_numeric($stats['failed'] ?? 0) ? (int)($stats['failed'] ?? 0) : 0;

                $config->increment('total_processed', $totalToAdd);
                $config->increment('total_success', $successToAdd);
                $config->increment('total_failed', $failedToAdd);

                // بروزرسانی سایر فیلدها
                $config->update([
                    'current_page' => $currentPage,
                    'last_run_at' => now(),
                ]);
            });

            // رفرش مدل برای گرفتن آخرین مقادیر
            $this->refresh();

            Log::info("✅ progress بروزرسانی شد", [
                'config_id' => $this->id,
                'page' => $currentPage,
                'new_stats' => [
                    'total_processed' => $this->total_processed,
                    'total_success' => $this->total_success,
                    'total_failed' => $this->total_failed
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

    /**
     * بروزرسانی آمار کلی از جمع execution logs
     */
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

    /**
     * بروزرسانی آمار از تعداد واقعی کتاب‌ها در دیتابیس
     */
    public function syncStatsFromBooks(): array
    {
        // شمارش کتاب‌هایی که از این کانفیگ آمده‌اند
        // فرض می‌کنیم تمام کتاب‌هایی که بعد از ایجاد این کانفیگ اضافه شده‌اند، از این کانفیگ هستند
        $booksCreatedAfterConfig = \App\Models\Book::where('created_at', '>=', $this->created_at)->count();

        // یا اگر سیستم tracking بهتری داریم، از آن استفاده کنیم
        $actualStats = [
            'total_books_in_db' => $booksCreatedAfterConfig,
            'config_total_processed' => $this->total_processed,
            'config_total_success' => $this->total_success,
            'difference' => $booksCreatedAfterConfig - $this->total_success
        ];

        return $actualStats;
    }

    public function resetProgress(): void
    {
        $this->update([
            'current_page' => $this->start_page ?? 1,
            'total_processed' => 0,
            'total_success' => 0,
            'total_failed' => 0,
            'is_running' => false,
        ]);
    }

    /**
     * دریافت آخرین execution log
     */
    public function getLatestExecutionLog(): ?ExecutionLog
    {
        return $this->executionLogs()->latest()->first();
    }

    /**
     * دریافت آمار کلی برای نمایش در UI
     */
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
            'is_currently_running' => $this->is_running
        ];
    }

    /**
     * دریافت آمار execution logs
     */
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
