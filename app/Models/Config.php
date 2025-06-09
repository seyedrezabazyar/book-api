<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

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

    /**
     * تعیین صفحه شروع هوشمند
     */
    public function getSmartStartPage(): int
    {
        // اولویت 1: اگر start_page مشخص شده، از آن استفاده کن
        if ($this->start_page && $this->start_page > 0) {
            Log::info("🎯 شروع از start_page تعیین شده", [
                'config_id' => $this->id,
                'start_page' => $this->start_page
            ]);
            return $this->start_page;
        }

        // اولویت 2: اگر auto_resume فعال باشد، از آخرین ID ادامه بده
        if ($this->auto_resume && $this->last_source_id > 0) {
            $nextId = $this->last_source_id + 1;
            Log::info("🔄 ادامه خودکار از آخرین ID", [
                'config_id' => $this->id,
                'last_source_id' => $this->last_source_id,
                'next_start' => $nextId
            ]);
            return $nextId;
        }

        // اولویت 3: آخرین ID از book_sources برای این منبع
        $lastIdFromSources = BookSource::where('source_name', $this->source_name)
            ->whereRaw('source_id REGEXP "^[0-9]+$"')
            ->orderByRaw('CAST(source_id AS UNSIGNED) DESC')
            ->value('source_id');

        if ($lastIdFromSources > 0) {
            $nextId = (int)$lastIdFromSources + 1;
            Log::info("📊 استفاده از آخرین ID در منبع", [
                'config_id' => $this->id,
                'source_name' => $this->source_name,
                'last_id_from_sources' => $lastIdFromSources,
                'next_start' => $nextId
            ]);
            return $nextId;
        }

        // پیش‌فرض: از 1 شروع کن
        Log::info("🆕 شروع جدید از ID 1", [
            'config_id' => $this->id,
            'source_name' => $this->source_name
        ]);
        return 1;
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
     * بروزرسانی آخرین source_id و آمار
     */
    public function updateProgress(int $sourceId, array $stats): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($sourceId, $stats) {
            $this->increment('total_processed', $stats['total'] ?? 0);
            $this->increment('total_success', $stats['success'] ?? 0);
            $this->increment('total_failed', $stats['failed'] ?? 0);

            // بروزرسانی آخرین ID اگر بزرگتر باشد
            if ($sourceId > $this->last_source_id) {
                $this->update([
                    'last_source_id' => $sourceId,
                    'current_page' => $sourceId,
                    'last_run_at' => now()
                ]);
            }
        });
    }

    /**
     * بررسی وجود source ID
     */
    public function hasSourceId(int $sourceId): bool
    {
        return BookSource::where('source_name', $this->source_name)
            ->where('source_id', (string)$sourceId)
            ->exists();
    }

    /**
     * یافتن source ID های مفقود
     */
    public function findMissingSourceIds(int $startId, int $endId, int $limit = 100): array
    {
        $existingIds = BookSource::where('source_name', $this->source_name)
            ->whereBetween(\Illuminate\Support\Facades\DB::raw('CAST(source_id AS UNSIGNED)'), [$startId, $endId])
            ->pluck('source_id')
            ->map(fn($id) => (int)$id)
            ->toArray();

        $allIds = range($startId, $endId);
        $missingIds = array_diff($allIds, $existingIds);

        return array_slice(array_values($missingIds), 0, $limit);
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
            'image_url' => 'تصویر کتاب'
        ];
    }

    /**
     * ریست کردن آمار برای شروع مجدد
     */
    public function resetForRestart(): void
    {
        $this->update([
            'current_page' => $this->getSmartStartPage(),
            'is_running' => false
        ]);

        Log::info("🔄 کانفیگ برای شروع مجدد ریست شد", [
            'config_id' => $this->id,
            'new_start_page' => $this->current_page
        ]);
    }

    /**
     * آمار نمایشی
     */
    public function getDisplayStats(): array
    {
        $sourceCount = BookSource::where('source_name', $this->source_name)->count();

        return [
            'total_executions' => $this->executionLogs()->count(),
            'successful_executions' => $this->executionLogs()->where('status', 'completed')->count(),
            'total_processed' => $this->total_processed,
            'total_success' => $this->total_success,
            'total_failed' => $this->total_failed,
            'success_rate' => $this->total_processed > 0
                ? round(($this->total_success / $this->total_processed) * 100, 2)
                : 0,
            'last_source_id' => $this->last_source_id,
            'next_source_id' => $this->getSmartStartPage(),
            'source_books_count' => $sourceCount,
        ];
    }
}
