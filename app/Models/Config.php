<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        $this->update([
            'current_page' => $currentPage,
            'total_processed' => $stats['total'],
            'total_success' => $stats['success'],
            'total_failed' => $stats['failed'],
            'last_run_at' => now(),
        ]);
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
}
