<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * مدل ساده برای مدیریت کانفیگ‌ها
 */
class Config extends Model
{
    use HasFactory;

    protected $table = 'configs';

    protected $fillable = [
        'name',
        'description',
        'config_data',
        'status',
        'created_by'
    ];

    protected $casts = [
        'config_data' => 'array'
    ];

    /**
     * وضعیت‌های مختلف کانفیگ
     */
    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_DRAFT = 'draft';

    /**
     * دریافت تمام وضعیت‌های ممکن
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_ACTIVE => 'فعال',
            self::STATUS_INACTIVE => 'غیرفعال',
            self::STATUS_DRAFT => 'پیش‌نویس'
        ];
    }

    /**
     * بررسی فعال بودن کانفیگ
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * دریافت متن وضعیت
     */
    public function getStatusTextAttribute(): string
    {
        return self::getStatuses()[$this->status] ?? 'نامشخص';
    }

    /**
     * اسکوپ برای دریافت کانفیگ‌های فعال
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * اسکوپ برای جستجو در نام و توضیحات
     */
    public function scopeSearch($query, $search)
    {
        if (!empty($search)) {
            return $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }
        return $query;
    }
}
