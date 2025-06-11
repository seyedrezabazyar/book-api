<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

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
        'is_running',
        'is_active'
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
        'is_active' => 'boolean',
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

    /**
     * تعیین صفحه شروع هوشمند - اصلاح شده
     * اولویت اول با start_page مشخص شده توسط کاربر است
     */
    public function getSmartStartPage(): int
    {
        // اولویت 1: اگر start_page توسط کاربر مشخص شده (هر عدد مثبت)، از آن استفاده کن
        if ($this->start_page && $this->start_page > 0) {
            Log::info("🎯 شروع از start_page تعیین شده توسط کاربر", [
                'config_id' => $this->id,
                'start_page' => $this->start_page,
                'user_override' => true
            ]);
            return $this->start_page;
        }

        // اولویت 2: آخرین ID از book_sources برای این منبع (حالت هوشمند)
        $lastIdFromSources = $this->getLastSourceIdFromBookSources();

        if ($lastIdFromSources > 0) {
            $nextId = $lastIdFromSources + 1;
            Log::info("📊 شروع هوشمند از آخرین ID در book_sources", [
                'config_id' => $this->id,
                'source_name' => $this->source_name,
                'last_id_from_sources' => $lastIdFromSources,
                'next_start' => $nextId,
                'smart_mode' => true
            ]);
            return $nextId;
        }

        // اولویت 3: اگر auto_resume فعال باشد و last_source_id موجود باشد
        if ($this->auto_resume && $this->last_source_id > 0) {
            $nextId = $this->last_source_id + 1;
            Log::info("🔄 ادامه از last_source_id", [
                'config_id' => $this->id,
                'last_source_id' => $this->last_source_id,
                'next_start' => $nextId,
                'auto_resume' => true
            ]);
            return $nextId;
        }

        // پیش‌فرض: از 1 شروع کن
        Log::info("🆕 شروع جدید از ID 1 (پیش‌فرض)", [
            'config_id' => $this->id,
            'source_name' => $this->source_name,
            'default_start' => true
        ]);
        return 1;
    }

    /**
     * دریافت آخرین ID ثبت شده در book_sources برای این منبع - اصلاح شده
     */
    public function getLastSourceIdFromBookSources(): int
    {
        try {
            // استفاده از BookSource model با orderByRaw صحیح
            $lastSourceRecord = \App\Models\BookSource::where('source_name', $this->source_name)
                ->whereRaw('source_id REGEXP "^[0-9]+$"') // فقط source_id های عددی
                ->orderByRaw('CAST(source_id AS UNSIGNED) DESC')
                ->first();

            $result = $lastSourceRecord ? (int)$lastSourceRecord->source_id : 0;

            Log::info("🔍 بررسی آخرین ID در book_sources", [
                'config_id' => $this->id,
                'source_name' => $this->source_name,
                'last_id' => $result,
                'found_record' => $lastSourceRecord ? true : false,
                'total_records' => \App\Models\BookSource::where('source_name', $this->source_name)->count()
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error("❌ خطا در دریافت آخرین ID از book_sources", [
                'config_id' => $this->id,
                'source_name' => $this->source_name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Fallback: استفاده از query ساده‌تر
            try {
                $maxId = \App\Models\BookSource::where('source_name', $this->source_name)
                    ->whereRaw('source_id REGEXP "^[0-9]+$"')
                    ->max(DB::raw('CAST(source_id AS UNSIGNED)')); // استفاده از DB facade

                return $maxId ? (int)$maxId : 0;
            } catch (\Exception $fallbackError) {
                Log::error("❌ خطا در fallback query", [
                    'config_id' => $this->id,
                    'fallback_error' => $fallbackError->getMessage()
                ]);
                return 0;
            }
        }
    }

    /**
     * متد جدید: بررسی اینکه آیا start_page توسط کاربر مشخص شده یا خیر
     */
    public function hasUserDefinedStartPage(): bool
    {
        return $this->start_page !== null && $this->start_page > 0;
    }

    /**
     * متد جدید: دریافت start_page برای نمایش در فرم
     */
    public function getStartPageForForm(): ?int
    {
        // اگر start_page مشخص شده، آن را برگردان (حتی اگر 1 باشد)
        if ($this->start_page !== null && $this->start_page > 0) {
            return $this->start_page;
        }

        // اگر مشخص نشده، null برگردان (فیلد فرم خالی خواهد بود)
        return null;
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

    /**
     * بروزرسانی آمار با منطق بهبود یافته - کاملاً اصلاح شده
     */
    public function updateProgress(int $sourceId, array $stats): void
    {
        try {
            $self = $this; // کپی کردن reference برای استفاده در closure

            DB::transaction(function () use ($sourceId, $stats, $self) {
                // استخراج آمار با کلیدهای مختلف - تعریف متغیرها داخل closure
                $totalToAdd = $self->extractStatValue($stats, ['total_processed', 'total']);
                $successToAdd = $self->extractStatValue($stats, ['total_success', 'success']);
                $failedToAdd = $self->extractStatValue($stats, ['total_failed', 'failed']);

                // بروزرسانی آمار
                if ($totalToAdd > 0) {
                    $self->increment('total_processed', $totalToAdd);
                }
                if ($successToAdd > 0) {
                    $self->increment('total_success', $successToAdd);
                }
                if ($failedToAdd > 0) {
                    $self->increment('total_failed', $failedToAdd);
                }

                // بروزرسانی آخرین ID اگر بزرگتر باشد
                if ($sourceId > ($self->last_source_id ?? 0)) {
                    $self->update([
                        'last_source_id' => $sourceId,
                        'current_page' => $sourceId,
                        'last_run_at' => now()
                    ]);
                }

                // لاگ کردن آمار داخل transaction
                Log::debug("📊 آمار کانفیگ بروزرسانی شد", [
                    'config_id' => $self->id,
                    'source_id' => $sourceId,
                    'stats_added' => [
                        'total_processed' => $totalToAdd,
                        'total_success' => $successToAdd,
                        'total_failed' => $failedToAdd
                    ]
                ]);
            });

            // لاگ نهایی خارج از transaction
            Log::debug("✅ آمار کانفیگ تکمیل شد", [
                'config_id' => $this->id,
                'source_id' => $sourceId,
                'new_totals' => [
                    'total_processed' => $this->fresh()->total_processed,
                    'total_success' => $this->fresh()->total_success,
                    'total_failed' => $this->fresh()->total_failed
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("❌ خطا در بروزرسانی آمار کانفیگ", [
                'config_id' => $this->id,
                'source_id' => $sourceId,
                'stats' => $stats,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * استخراج مقدار آمار با کلیدهای مختلف
     */
    private function extractStatValue(array $stats, array $possibleKeys): int
    {
        foreach ($possibleKeys as $key) {
            if (isset($stats[$key]) && is_numeric($stats[$key])) {
                return (int)$stats[$key];
            }
        }
        return 0;
    }

    /**
     * همگام‌سازی آمار از لاگ‌ها - بهبود یافته
     */
    public function syncStatsFromLogs(): void
    {
        try {
            $stats = $this->executionLogs()
                ->whereIn('status', ['completed', 'stopped'])
                ->selectRaw('
                    SUM(total_processed) as total_processed,
                    SUM(total_success) as total_success,
                    SUM(total_failed) as total_failed,
                    SUM(total_enhanced) as total_enhanced,
                    SUM(total_duplicate) as total_duplicate
                ')
                ->first();

            if ($stats && $stats->total_processed > 0) {
                $updateData = [
                    'total_processed' => $stats->total_processed,
                    'total_success' => $stats->total_success,
                    'total_failed' => $stats->total_failed,
                ];

                $this->update($updateData);

                Log::info("🔄 آمار کانفیگ از لاگ‌ها همگام‌سازی شد", [
                    'config_id' => $this->id,
                    'synced_stats' => $updateData,
                    'total_enhanced' => $stats->total_enhanced,
                    'total_duplicate' => $stats->total_duplicate
                ]);
            }
        } catch (\Exception $e) {
            Log::error("❌ خطا در همگام‌سازی آمار از لاگ‌ها", [
                'config_id' => $this->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * دریافت آمار کامل شامل enhanced
     */
    public function getCompleteStats(): array
    {
        try {
            $stats = $this->executionLogs()
                ->whereIn('status', ['completed', 'stopped'])
                ->selectRaw('
                    SUM(total_processed) as total_processed,
                    SUM(total_success) as total_success,
                    SUM(total_failed) as total_failed,
                    SUM(total_enhanced) as total_enhanced,
                    SUM(total_duplicate) as total_duplicate
                ')
                ->first();

            $totalEnhanced = $stats ? ($stats->total_enhanced ?? 0) : 0;
            $totalDuplicate = $stats ? ($stats->total_duplicate ?? 0) : 0;

            $realSuccessCount = $this->total_success + $totalEnhanced;
            $realSuccessRate = $this->total_processed > 0 ?
                round(($realSuccessCount / $this->total_processed) * 100, 2) : 0;

            return [
                'total_processed' => $this->total_processed,
                'total_success' => $this->total_success,
                'total_failed' => $this->total_failed,
                'total_enhanced' => $totalEnhanced,
                'total_duplicate' => $totalDuplicate,
                'real_success_count' => $realSuccessCount,
                'real_success_rate' => $realSuccessRate,
                'enhancement_rate' => $this->total_processed > 0 ?
                    round(($totalEnhanced / $this->total_processed) * 100, 2) : 0
            ];
        } catch (\Exception $e) {
            Log::error("❌ خطا در دریافت آمار کامل", [
                'config_id' => $this->id,
                'error' => $e->getMessage()
            ]);

            return [
                'total_processed' => $this->total_processed ?? 0,
                'total_success' => $this->total_success ?? 0,
                'total_failed' => $this->total_failed ?? 0,
                'total_enhanced' => 0,
                'total_duplicate' => 0,
                'real_success_count' => $this->total_success ?? 0,
                'real_success_rate' => 0,
                'enhancement_rate' => 0
            ];
        }
    }

    /**
     * تنظیمات API
     */
    public function getApiSettings(): array
    {
        return $this->config_data['api'] ?? [];
    }

    public function getGeneralSettings(): array
    {
        return $this->config_data['general'] ?? [];
    }

    /**
     * فیلدهای قابل نقشه‌برداری
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
            'image_url' => 'تصویر کتاب',
            'sha1' => 'SHA1 Hash',
            'sha256' => 'SHA256 Hash',
            'crc32' => 'CRC32 Hash',
            'ed2k' => 'ED2K Hash',
            'btih' => 'BitTorrent Info Hash',
            'magnet' => 'Magnet Link'
        ];
    }
}
