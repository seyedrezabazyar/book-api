<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Config extends Model
{
    protected $fillable = [
        'name', 'description', 'base_url', 'timeout', 'delay_seconds',
        'records_per_run', 'config_data', 'status', 'created_by'
    ];

    protected $casts = [
        'config_data' => 'array',
        'timeout' => 'integer',
        'delay_seconds' => 'integer',
        'records_per_run' => 'integer',
    ];

    // وضعیت‌ها
    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_DRAFT = 'draft';

    // روابط
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    // متدهای کمکی
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isApiSource(): bool
    {
        return true; // فقط API پشتیبانی می‌کنیم
    }

    // دریافت تنظیمات API
    public function getApiSettings(): array
    {
        return $this->config_data['api'] ?? [];
    }

    // دریافت تنظیمات عمومی
    public function getGeneralSettings(): array
    {
        return $this->config_data['general'] ?? [];
    }

    // انواع وضعیت
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
