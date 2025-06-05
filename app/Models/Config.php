<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Config extends Model
{
    protected $fillable = [
        'name', 'description', 'data_source_type', 'base_url',
        'timeout', 'max_retries', 'delay_seconds', 'records_per_run',
        'config_data', 'status', 'current_url', 'total_processed',
        'total_success', 'total_failed', 'last_run_at', 'is_running', 'created_by'
    ];

    protected $casts = [
        'config_data' => 'array',
        'last_run_at' => 'datetime',
        'is_running' => 'boolean',
        'timeout' => 'integer',
        'max_retries' => 'integer',
        'delay_seconds' => 'integer',
        'records_per_run' => 'integer',
        'total_processed' => 'integer',
        'total_success' => 'integer',
        'total_failed' => 'integer'
    ];

    // وضعیت‌ها
    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_DRAFT = 'draft';

    // نوع منابع
    const DATA_SOURCE_API = 'api';
    const DATA_SOURCE_CRAWLER = 'crawler';

    // روابط
    public function failures(): HasMany
    {
        return $this->hasMany(ScrapingFailure::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeRunning($query)
    {
        return $query->where('is_running', true);
    }

    public function scopeSearch($query, $search)
    {
        if (!empty($search)) {
            return $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('base_url', 'like', "%{$search}%");
            });
        }
        return $query;
    }

    // متدهای کمکی
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isRunning(): bool
    {
        return $this->is_running;
    }

    public function isApiSource(): bool
    {
        return $this->data_source_type === self::DATA_SOURCE_API;
    }

    public function isCrawlerSource(): bool
    {
        return $this->data_source_type === self::DATA_SOURCE_CRAWLER;
    }

    public function canStart(): bool
    {
        return $this->isActive() && !$this->isRunning();
    }

    public function canStop(): bool
    {
        return $this->isRunning();
    }

    // شروع اسکرپر
    public function start(): void
    {
        $this->update([
            'is_running' => true,
            'last_run_at' => now()
        ]);
    }

    // متوقف کردن اسکرپر
    public function stop(): void
    {
        $this->update(['is_running' => false]);
    }

    // ریست کردن پیشرفت (شروع از اول)
    public function reset(): void
    {
        $this->update([
            'current_url' => null,
            'total_processed' => 0,
            'total_success' => 0,
            'total_failed' => 0,
            'is_running' => false
        ]);
    }

    // به‌روزرسانی آمار
    public function updateStats(bool $success = true): void
    {
        $this->increment('total_processed');

        if ($success) {
            $this->increment('total_success');
        } else {
            $this->increment('total_failed');
        }

        $this->update(['last_run_at' => now()]);
    }

    // درصد پیشرفت (اختیاری)
    public function getProgressPercentage(): int
    {
        if (!$this->current_url || $this->total_processed === 0) {
            return 0;
        }
        // محاسبه براساس URL یا سایر معیارها
        return min(100, ($this->total_success / max(1, $this->total_processed)) * 100);
    }

    // نرخ موفقیت
    public function getSuccessRate(): float
    {
        if ($this->total_processed === 0) return 0;
        return round(($this->total_success / $this->total_processed) * 100, 1);
    }

    // دریافت آمار کامل
    public function getStats(): array
    {
        return [
            'total_processed' => $this->total_processed,
            'total_success' => $this->total_success,
            'total_failed' => $this->total_failed,
            'success_rate' => $this->getSuccessRate(),
            'last_run_at' => $this->last_run_at?->format('Y-m-d H:i:s'),
            'is_running' => $this->is_running,
            'current_url' => $this->current_url,
            'unresolved_failures' => $this->failures()->where('is_resolved', false)->count()
        ];
    }

    // انواع منابع داده
    public static function getDataSourceTypes(): array
    {
        return [
            self::DATA_SOURCE_API => 'API',
            self::DATA_SOURCE_CRAWLER => 'وب کراولر'
        ];
    }

    // وضعیت‌های مختلف
    public static function getStatuses(): array
    {
        return [
            self::STATUS_ACTIVE => 'فعال',
            self::STATUS_INACTIVE => 'غیرفعال',
            self::STATUS_DRAFT => 'پیش‌نویس'
        ];
    }

    // متن وضعیت
    public function getStatusTextAttribute(): string
    {
        return self::getStatuses()[$this->status] ?? 'نامشخص';
    }

    // متن نوع منبع
    public function getDataSourceTypeTextAttribute(): string
    {
        return self::getDataSourceTypes()[$this->data_source_type] ?? 'نامشخص';
    }

    // فیلدهای قابل استخراج
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
}
