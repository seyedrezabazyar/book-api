<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مدل پیشرفته برای مدیریت کانفیگ‌های دریافت اطلاعات
 */
class Config extends Model
{
    use HasFactory;

    protected $table = 'configs';

    protected $fillable = [
        'name',
        'description',
        'data_source_type',
        'base_url',
        'timeout',
        'max_retries',
        'delay',
        'config_data',
        'status',
        'created_by'
    ];

    protected $casts = [
        'config_data' => 'array',
        'timeout' => 'integer',
        'max_retries' => 'integer',
        'delay' => 'integer'
    ];

    /**
     * انواع منابع داده
     */
    const DATA_SOURCE_API = 'api';
    const DATA_SOURCE_CRAWLER = 'crawler';

    /**
     * وضعیت‌های مختلف کانفیگ
     */
    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_DRAFT = 'draft';

    /**
     * دریافت انواع منابع داده
     */
    public static function getDataSourceTypes(): array
    {
        return [
            self::DATA_SOURCE_API => 'API',
            self::DATA_SOURCE_CRAWLER => 'وب کراولر'
        ];
    }

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
     * روابط مدل
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * بررسی فعال بودن کانفیگ
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * بررسی نوع منبع داده
     */
    public function isApiSource(): bool
    {
        return $this->data_source_type === self::DATA_SOURCE_API;
    }

    public function isCrawlerSource(): bool
    {
        return $this->data_source_type === self::DATA_SOURCE_CRAWLER;
    }

    /**
     * دریافت متن وضعیت
     */
    public function getStatusTextAttribute(): string
    {
        return self::getStatuses()[$this->status] ?? 'نامشخص';
    }

    /**
     * دریافت متن نوع منبع
     */
    public function getDataSourceTypeTextAttribute(): string
    {
        return self::getDataSourceTypes()[$this->data_source_type] ?? 'نامشخص';
    }

    /**
     * دریافت تنظیمات API
     */
    public function getApiSettings(): array
    {
        if (!$this->isApiSource()) {
            return [];
        }

        return [
            'endpoint' => $this->config_data['api']['endpoint'] ?? '',
            'method' => $this->config_data['api']['method'] ?? 'GET',
            'headers' => $this->config_data['api']['headers'] ?? [],
            'params' => $this->config_data['api']['params'] ?? [],
            'auth_type' => $this->config_data['api']['auth_type'] ?? 'none',
            'auth_token' => $this->config_data['api']['auth_token'] ?? '',
            'field_mapping' => $this->config_data['api']['field_mapping'] ?? []
        ];
    }

    /**
     * دریافت تنظیمات Crawler
     */
    public function getCrawlerSettings(): array
    {
        if (!$this->isCrawlerSource()) {
            return [];
        }

        return [
            'selectors' => $this->config_data['crawler']['selectors'] ?? [],
            'pagination' => $this->config_data['crawler']['pagination'] ?? [],
            'filters' => $this->config_data['crawler']['filters'] ?? [],
            'wait_for_element' => $this->config_data['crawler']['wait_for_element'] ?? '',
            'javascript_enabled' => $this->config_data['crawler']['javascript_enabled'] ?? false
        ];
    }

    /**
     * دریافت تنظیمات عمومی
     */
    public function getGeneralSettings(): array
    {
        return [
            'user_agent' => $this->config_data['general']['user_agent'] ?? 'Mozilla/5.0 (compatible; LaravelBot/1.0)',
            'verify_ssl' => $this->config_data['general']['verify_ssl'] ?? true,
            'follow_redirects' => $this->config_data['general']['follow_redirects'] ?? true,
            'proxy' => $this->config_data['general']['proxy'] ?? '',
            'cookies' => $this->config_data['general']['cookies'] ?? []
        ];
    }

    /**
     * دریافت نقشه‌برداری فیلدهای کتاب
     */
    public function getBookFieldMapping(): array
    {
        if ($this->isApiSource()) {
            return $this->config_data['api']['field_mapping'] ?? [];
        }

        return $this->config_data['crawler']['selectors'] ?? [];
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
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('base_url', 'like', "%{$search}%");
            });
        }
        return $query;
    }

    /**
     * اسکوپ براساس نوع منبع
     */
    public function scopeByDataSourceType($query, $type)
    {
        return $query->where('data_source_type', $type);
    }

    /**
     * اعتبارسنجی کانفیگ براساس نوع منبع
     */
    public function validateConfig(): array
    {
        $errors = [];

        if ($this->isApiSource()) {
            $apiSettings = $this->getApiSettings();

            if (empty($apiSettings['endpoint'])) {
                $errors[] = 'آدرس endpoint API الزامی است';
            }

            if (empty($apiSettings['field_mapping'])) {
                $errors[] = 'نقشه‌برداری فیلدهای API الزامی است';
            }
        }

        if ($this->isCrawlerSource()) {
            $crawlerSettings = $this->getCrawlerSettings();

            if (empty($crawlerSettings['selectors'])) {
                $errors[] = 'تعریف سلکتورهای crawler الزامی است';
            }
        }

        return $errors;
    }

    /**
     * فیلدهای قابل استخراج برای کتاب‌ها
     */
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
            'download_url' => 'لینک دانلود',
            'image_url' => 'تصویر کتاب',
            'price' => 'قیمت',
            'rating' => 'امتیاز',
            'tags' => 'برچسب‌ها'
        ];
    }
}
