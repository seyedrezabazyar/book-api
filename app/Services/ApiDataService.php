<?php

namespace App\Services;

use App\Models\Config;
use App\Models\Book;
use App\Models\Author;
use App\Models\Category;
use App\Models\Publisher;
use App\Models\BookImage;
use App\Models\BookSource;
use App\Models\BookHash;
use App\Models\ExecutionLog;
use App\Models\ScrapingFailure;
use App\Jobs\ProcessSinglePageJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class ApiDataService
{
    private Config $config;
    private ?ExecutionLog $executionLog = null;
    private array $stats = ['total' => 0, 'success' => 0, 'failed' => 0, 'duplicate' => 0, 'updated' => 0];

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * پردازش یک source ID منفرد
     */
    public function processSourceId(int $sourceId, ExecutionLog $executionLog): array
    {
        if ($sourceId === -1) {
            // این Job برای پایان دادن به اجرا است
            $this->completeExecution($executionLog);
            return ['action' => 'completed'];
        }

        $startTime = microtime(true);
        $apiSettings = $this->config->getApiSettings();
        $generalSettings = $this->config->getGeneralSettings();

        // ساخت URL برای source ID خاص
        $url = $this->buildApiUrlForSourceId($sourceId);

        $executionLog->addLogEntry("🔍 پردازش source ID {$sourceId}", [
            'source_id' => $sourceId,
            'url' => $url,
            'started_at' => now()->toISOString()
        ]);

        Log::info("🔍 شروع پردازش source ID {$sourceId}", [
            'config_id' => $this->config->id,
            'execution_id' => $executionLog->execution_id,
            'url' => $url
        ]);

        try {
            // بررسی اینکه آیا این ID قبلاً از این منبع پردازش شده
            if ($this->isSourceIdProcessed($sourceId)) {
                $executionLog->addLogEntry("⏭️ Source ID {$sourceId} قبلاً از این منبع پردازش شده", [
                    'source_id' => $sourceId,
                    'action' => 'skipped'
                ]);

                // بروزرسانی last_source_id
                $this->config->updateLastSourceId($sourceId);

                return [
                    'source_id' => $sourceId,
                    'action' => 'skipped',
                    'reason' => 'already_processed_from_this_source',
                    'stats' => ['total' => 0, 'success' => 0, 'failed' => 0, 'duplicate' => 1]
                ];
            }

            $response = $this->makeHttpRequest($url, $apiSettings, $generalSettings);

            if (!$response->successful()) {
                $error = "خطای HTTP {$response->status()}: {$response->reason()}";

                // ثبت شکست
                $this->config->logSourceIdFailure($sourceId, "HTTP {$response->status()}");

                $executionLog->addLogEntry("❌ خطا در source ID {$sourceId}: {$error}", [
                    'source_id' => $sourceId,
                    'http_status' => $response->status(),
                    'error' => $error,
                    'url' => $url
                ]);

                throw new \Exception($error);
            }

            $data = $response->json();
            $bookData = $this->extractBookFromApiData($data, $sourceId);

            if (empty($bookData)) {
                // این ID کتابی ندارد - ثبت شکست
                $this->config->logSourceIdFailure($sourceId, 'No book data found');

                $executionLog->addLogEntry("📭 Source ID {$sourceId}: کتابی یافت نشد", [
                    'source_id' => $sourceId,
                    'response_size' => strlen(json_encode($data)),
                    'action' => 'no_book_found'
                ]);

                return [
                    'source_id' => $sourceId,
                    'action' => 'no_book_found',
                    'stats' => ['total' => 1, 'success' => 0, 'failed' => 1, 'duplicate' => 0]
                ];
            }

            // پردازش کتاب
            $bookResult = $this->processBookWithSource($bookData, $sourceId, $executionLog, $apiSettings);
            $processTime = round(microtime(true) - $startTime, 2);

            // بروزرسانی آمار کانفیگ
            $this->config->updateProgress($sourceId, $bookResult['stats']);

            // بروزرسانی ExecutionLog
            $executionLog->updateProgress($bookResult['stats']);

            Log::info("✅ Source ID {$sourceId} کامل شد", [
                'config_id' => $this->config->id,
                'execution_id' => $executionLog->execution_id,
                'result' => $bookResult,
                'process_time' => $processTime
            ]);

            return $bookResult;
        } catch (\Exception $e) {
            Log::error("❌ خطا در پردازش source ID {$sourceId}", [
                'config_id' => $this->config->id,
                'execution_id' => $executionLog->execution_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $executionLog->addLogEntry("❌ خطای کلی در source ID {$sourceId}", [
                'source_id' => $sourceId,
                'error' => $e->getMessage(),
                'failed_at' => now()->toISOString()
            ]);

            throw $e;
        }
    }

    /**
     * بررسی اینکه آیا این source ID قبلاً از این منبع پردازش شده
     */
    private function isSourceIdProcessed(int $sourceId): bool
    {
        return BookSource::sourceExists($this->config->source_name, (string) $sourceId);
    }

    /**
     * پردازش کتاب با منبع - بهبود یافته
     */
    private function processBookWithSource(array $bookData, int $sourceId, ExecutionLog $executionLog, array $apiSettings): array
    {
        $stats = ['total' => 1, 'success' => 0, 'failed' => 0, 'duplicate' => 0, 'updated' => 0];

        try {
            $extractedData = $this->extractFieldsFromData($bookData, $apiSettings['field_mapping'] ?? []);

            if (empty($extractedData['title'])) {
                throw new \Exception('عنوان کتاب یافت نشد');
            }

            // محاسبه MD5 برای کتاب
            $contentHash = $this->calculateBookHash($extractedData);

            // بررسی وجود کتاب با همین MD5
            $existingBook = Book::where('content_hash', $contentHash)->first();

            if ($existingBook) {
                // کتاب موجود است - ثبت منبع و بررسی نیاز به بروزرسانی
                $result = $this->handleExistingBookImproved($existingBook, $extractedData, $sourceId, $executionLog);

                // همیشه منبع را ثبت کن
                BookSource::recordBookSource($existingBook->id, $this->config->source_name, (string) $sourceId);

                $stats[$result['action']]++;

                $executionLog->addLogEntry("🔄 کتاب موجود پردازش شد", [
                    'source_id' => $sourceId,
                    'book_id' => $existingBook->id,
                    'title' => $existingBook->title,
                    'action' => $result['action'],
                    'changes' => $result['changes'] ?? []
                ]);

                return [
                    'source_id' => $sourceId,
                    'action' => $result['action'],
                    'book_id' => $existingBook->id,
                    'title' => $existingBook->title,
                    'stats' => $stats
                ];
            } else {
                // کتاب جدید
                $book = $this->createNewBookWithSourceImproved($extractedData, $sourceId, $contentHash, $executionLog);
                $stats['success']++;

                $executionLog->addLogEntry("✨ کتاب جدید ایجاد شد", [
                    'source_id' => $sourceId,
                    'book_id' => $book->id,
                    'title' => $book->title,
                    'content_hash' => $contentHash
                ]);

                return [
                    'source_id' => $sourceId,
                    'action' => 'created',
                    'book_id' => $book->id,
                    'title' => $book->title,
                    'stats' => $stats
                ];
            }
        } catch (\Exception $e) {
            $stats['failed']++;
            Log::error('❌ خطا در پردازش کتاب', [
                'source_id' => $sourceId,
                'error' => $e->getMessage(),
                'book_data' => $bookData
            ]);

            return [
                'source_id' => $sourceId,
                'action' => 'failed',
                'error' => $e->getMessage(),
                'stats' => $stats
            ];
        }
    }

    /**
     * مدیریت کتاب موجود - بهبود یافته با استفاده از متدهای جدید مدل
     */
    private function handleExistingBookImproved(Book $existingBook, array $newData, int $sourceId, ExecutionLog $executionLog): array
    {
        // استفاده از متد smartUpdate جدید
        $updateOptions = [
            'fill_missing_fields' => $this->config->fill_missing_fields,
            'update_descriptions' => $this->config->update_descriptions,
            'merge_isbns' => true,
            'merge_authors' => true,
        ];

        $updateResult = $existingBook->smartUpdate($newData, $updateOptions);

        // پردازش تصاویر جدید
        if (!empty($newData['image_url'])) {
            $this->processImages($existingBook, $newData['image_url']);
            $updateResult['changes']['updated_images'] = true;
        }

        // ثبت لاگ تفصیلی
        $executionLog->addLogEntry("🔄 پردازش کتاب موجود", [
            'book_id' => $existingBook->id,
            'source_id' => $sourceId,
            'title' => $existingBook->title,
            'update_result' => $updateResult,
            'changes_count' => count($updateResult['changes'])
        ]);

        return [
            'action' => $updateResult['action'],
            'changes' => $updateResult['changes']
        ];
    }

    /**
     * ادغام ISBN های مختلف
     */
    private function mergeIsbns(?string $existingIsbn, string $newIsbn): string
    {
        if (empty($existingIsbn)) {
            return $newIsbn;
        }

        // نرمال‌سازی ISBN ها
        $existing = preg_replace('/[^0-9X-]/', '', strtoupper($existingIsbn));
        $new = preg_replace('/[^0-9X-]/', '', strtoupper($newIsbn));

        if ($existing === $new) {
            return $existingIsbn; // بدون تغییر
        }

        // تبدیل به آرایه و حذف تکراری‌ها
        $existingIsbns = array_filter(explode(',', $existingIsbn));
        $newIsbns = array_filter(explode(',', $newIsbn));

        $allIsbns = array_unique(array_merge($existingIsbns, $newIsbns));

        return implode(', ', $allIsbns);
    }

    /**
     * انتخاب بهترین توضیحات
     */
    private function getBetterDescription(?string $existingDesc, string $newDesc): string
    {
        if (empty($existingDesc)) {
            return $newDesc;
        }

        $existingLength = strlen(trim($existingDesc));
        $newLength = strlen(trim($newDesc));

        // اگر توضیحات جدید 30% بیشتر باشد، از آن استفاده کن
        if ($newLength > $existingLength * 1.3) {
            return $newDesc;
        }

        // اگر توضیحات جدید فقط کمی بیشتر باشد، ادغام کن
        if ($newLength > $existingLength * 1.1 && $newLength <= $existingLength * 1.3) {
            // بررسی اینکه آیا محتوای جدیدی دارد
            similar_text($existingDesc, $newDesc, $percent);
            if ($percent < 80) { // اگر کمتر از 80% شباهت داشت، ادغام کن
                return $existingDesc . "\n\n---\n\n" . $newDesc;
            }
        }

        return $existingDesc; // بدون تغییر
    }

    /**
     * ادغام نویسندگان جدید
     */
    private function mergeAuthors(Book $book, string $newAuthorsString): bool
    {
        $newAuthorNames = array_map('trim', explode(',', $newAuthorsString));
        $existingAuthorNames = $book->authors()->pluck('name')->toArray();

        $hasChanges = false;

        foreach ($newAuthorNames as $authorName) {
            if (empty($authorName) || in_array($authorName, $existingAuthorNames)) {
                continue;
            }

            // پیدا کردن یا ایجاد نویسنده
            $author = Author::firstOrCreate(
                ['name' => $authorName],
                [
                    'slug' => Str::slug($authorName . '_' . time()),
                    'is_active' => true,
                    'books_count' => 0
                ]
            );

            // بررسی اینکه آیا رابطه قبلاً وجود دارد
            $exists = DB::table('book_author')
                ->where('book_id', $book->id)
                ->where('author_id', $author->id)
                ->exists();

            if (!$exists) {
                // اضافه کردن رابطه
                DB::table('book_author')->insert([
                    'book_id' => $book->id,
                    'author_id' => $author->id,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                $hasChanges = true;

                Log::info("✅ نویسنده جدید '{$authorName}' به کتاب '{$book->title}' اضافه شد", [
                    'book_id' => $book->id,
                    'author_id' => $author->id,
                    'author_name' => $authorName
                ]);
            }
        }

        return $hasChanges;
    }

    /**
     * بروزرسانی یا ایجاد هش‌های کتاب
     */
    private function updateOrCreateBookHashes(Book $book, array $extractedData): void
    {
        // دریافت یا ایجاد رکورد هش
        $bookHash = BookHash::firstOrCreate(
            ['book_id' => $book->id],
            [
                'book_hash' => $book->content_hash,
                'md5' => $book->content_hash, // همان content_hash
            ]
        );

        $needsUpdate = false;

        // بروزرسانی هش‌های موجود در API
        $hashFields = [
            'sha1' => 'sha1',
            'sha256' => 'sha256',
            'crc32' => 'crc32',
            'ed2k_hash' => 'ed2k',
            'btih' => 'btih',
            'magnet_link' => 'magnet'
        ];

        foreach ($hashFields as $dbField => $apiField) {
            if (!empty($extractedData[$apiField]) && empty($bookHash->$dbField)) {
                $bookHash->$dbField = $extractedData[$apiField];
                $needsUpdate = true;
            }
        }

        if ($needsUpdate) {
            $bookHash->save();

            Log::info("🔐 هش‌های کتاب بروزرسانی شد", [
                'book_id' => $book->id,
                'updated_hashes' => array_keys($bookHash->getDirty())
            ]);
        }
    }

    /**
     * ایجاد کتاب جدید با منبع - بهبود یافته با استفاده از متدهای جدید
     */
    private function createNewBookWithSourceImproved(array $extractedData, int $sourceId, string $contentHash, ExecutionLog $executionLog): Book
    {
        $options = [
            'source_name' => $this->config->source_name,
            'source_id' => (string) $sourceId
        ];

        $book = Book::createWithRelations($extractedData, $options);

        $executionLog->addLogEntry("✨ کتاب جدید با تمام اجزا ایجاد شد", [
            'book_id' => $book->id,
            'source_id' => $sourceId,
            'title' => $book->title,
            'content_hash' => $contentHash,
            'stats' => $book->getCompleteStats()
        ]);

        return $book;
    }

    /**
     * ایجاد هش‌های کتاب برای کتاب جدید
     */
    private function createBookHashes(Book $book, array $extractedData): void
    {
        $hashData = [
            'book_id' => $book->id,
            'book_hash' => $book->content_hash,
            'md5' => $book->content_hash, // همان content_hash
        ];

        // اضافه کردن سایر هش‌ها اگر در API موجود باشند
        $hashFields = [
            'sha1' => 'sha1',
            'sha256' => 'sha256',
            'crc32' => 'crc32',
            'ed2k_hash' => 'ed2k',
            'btih' => 'btih',
            'magnet_link' => 'magnet'
        ];

        foreach ($hashFields as $dbField => $apiField) {
            if (!empty($extractedData[$apiField])) {
                $hashData[$dbField] = $extractedData[$apiField];
            }
        }

        BookHash::create($hashData);

        Log::info("🔐 هش‌های کتاب ایجاد شد", [
            'book_id' => $book->id,
            'hashes_created' => array_keys($hashData)
        ]);
    }

    // سایر متدهای کمکی (بدون تغییر عمده)
    private function buildApiUrlForSourceId(int $sourceId): string
    {
        return $this->config->buildApiUrl($sourceId);
    }

    private function extractBookFromApiData(array $data, int $sourceId): array
    {
        // بررسی status
        if (isset($data['status']) && $data['status'] === 'success' && isset($data['data']['book'])) {
            return $data['data']['book'];
        }

        // اگر خود data یک کتاب است
        if (isset($data['id']) || isset($data['title'])) {
            return $data;
        }

        // بررسی ساختارهای مختلف
        $possibleKeys = ['data', 'book', 'result', 'item'];
        foreach ($possibleKeys as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return $data[$key];
            }
        }

        Log::warning("📚 ساختار نامعلوم برای source ID {$sourceId}", [
            'data_keys' => array_keys($data),
            'source_id' => $sourceId
        ]);

        return [];
    }

    private function completeExecution(ExecutionLog $executionLog): void
    {
        $config = $this->config->fresh();
        $finalStats = [
            'total' => $config->total_processed,
            'success' => $config->total_success,
            'failed' => $config->total_failed,
            'duplicate' => $executionLog->total_duplicate,
            'execution_time' => $executionLog->started_at ? now()->diffInSeconds($executionLog->started_at) : 0
        ];

        $executionLog->markCompleted($finalStats);
        $this->config->update(['is_running' => false]);

        Log::info("🎉 اجرا کامل شد", [
            'config_id' => $this->config->id,
            'execution_id' => $executionLog->execution_id,
            'final_stats' => $finalStats,
            'last_source_id' => $this->config->last_source_id
        ]);
    }

    private function calculateBookHash(array $data): string
    {
        return Book::calculateContentHash($data);
    }

    private function makeHttpRequest(string $url, array $apiSettings, array $generalSettings)
    {
        return Http::timeout($this->config->timeout)
            ->retry(3, 1000)
            ->when(!empty($generalSettings['user_agent']),
                fn($client) => $client->withUserAgent($generalSettings['user_agent']))
            ->when(!($generalSettings['verify_ssl'] ?? true),
                fn($client) => $client->withoutVerifying())
            ->get($url);
    }

    private function extractFieldsFromData(array $data, array $fieldMapping): array
    {
        if (empty($fieldMapping)) {
            $fieldMapping = [
                'title' => 'title',
                'description' => 'description_en',
                'author' => 'authors',
                'category' => 'category.name',
                'publisher' => 'publisher.name',
                'isbn' => 'isbn',
                'publication_year' => 'publication_year',
                'pages_count' => 'pages_count',
                'language' => 'language',
                'format' => 'format',
                'file_size' => 'file_size',
                'image_url' => 'image_url.0',
                // اضافه کردن فیلدهای هش
                'sha1' => 'sha1',
                'sha256' => 'sha256',
                'crc32' => 'crc32',
                'ed2k' => 'ed2k_hash',
                'btih' => 'btih',
                'magnet' => 'magnet_link'
            ];
        }

        $extracted = [];
        foreach ($fieldMapping as $bookField => $apiField) {
            if (empty($apiField)) continue;

            $value = $this->getNestedValue($data, $apiField);
            if ($value !== null) {
                $extracted[$bookField] = $this->sanitizeValue($value, $bookField);
            }
        }

        return $extracted;
    }

    private function getNestedValue(array $data, string $path)
    {
        $keys = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            if (is_array($value)) {
                $value = is_numeric($key) ? $value[(int)$key] ?? null : $value[$key] ?? null;
            } else {
                return null;
            }
        }

        return $value;
    }

    private function sanitizeValue($value, string $fieldType)
    {
        if ($value === null) return null;

        return match ($fieldType) {
            'title', 'description', 'category' => trim((string) $value),
            'author' => $this->extractAuthors($value),
            'publisher' => is_array($value) && isset($value['name']) ? trim($value['name']) : $this->extractPublisherName($value),
            'publication_year' => is_numeric($value) && $value >= 1000 && $value <= date('Y') + 5 ? (int) $value : null,
            'pages_count', 'file_size' => is_numeric($value) && $value > 0 ? (int) $value : null,
            'isbn' => preg_replace('/[^0-9X-]/', '', (string) $value),
            'language' => $this->normalizeLanguage((string) $value),
            'format' => $this->normalizeFormat((string) $value),
            'image_url' => $this->extractImageUrl($value),
            // فیلدهای هش
            'sha1', 'sha256', 'crc32', 'ed2k', 'btih' => is_string($value) ? trim($value) : null,
            'magnet' => is_string($value) && str_starts_with($value, 'magnet:') ? trim($value) : null,
            default => trim((string) $value)
        };
    }

    private function extractAuthors($authorsData): ?string
    {
        if (is_string($authorsData)) {
            return trim($authorsData);
        }

        if (is_array($authorsData)) {
            $names = [];
            foreach ($authorsData as $author) {
                if (is_array($author) && isset($author['name'])) {
                    $names[] = trim($author['name']);
                } elseif (is_string($author)) {
                    $names[] = trim($author);
                }
            }
            return !empty($names) ? implode(', ', $names) : null;
        }

        return null;
    }

    private function extractImageUrl($imageData): ?string
    {
        if (is_string($imageData)) {
            return filter_var(trim($imageData), FILTER_VALIDATE_URL) ?: null;
        }

        if (is_array($imageData)) {
            foreach ($imageData as $url) {
                if (is_string($url) && filter_var(trim($url), FILTER_VALIDATE_URL)) {
                    return trim($url);
                }
            }
        }

        return null;
    }

    private function normalizeLanguage(string $language): string
    {
        $language = strtolower(trim($language));
        $langMap = ['persian' => 'fa', 'english' => 'en', 'فارسی' => 'fa'];
        return $langMap[$language] ?? substr($language, 0, 2);
    }

    private function normalizeFormat(string $format): string
    {
        $format = strtolower(trim($format));
        $allowedFormats = ['pdf', 'epub', 'mobi', 'djvu'];
        return in_array($format, $allowedFormats) ? $format : 'pdf';
    }

    private function extractPublisherName($publisherData): ?string
    {
        if (is_string($publisherData)) return trim($publisherData);

        if (is_array($publisherData)) {
            if (isset($publisherData['name'])) return trim($publisherData['name']);
            foreach ($publisherData as $value) {
                if (is_string($value) && !empty(trim($value))) return trim($value);
            }
        }

        return null;
    }

    private function findOrCreateCategory(string $categoryName): Category
    {
        return Category::firstOrCreate(
            ['name' => $categoryName],
            ['slug' => Str::slug($categoryName . '_' . time()), 'is_active' => true, 'books_count' => 0]
        );
    }

    private function findOrCreatePublisher(string $publisherName): Publisher
    {
        return Publisher::firstOrCreate(
            ['name' => $publisherName],
            ['slug' => Str::slug($publisherName . '_' . time()), 'is_active' => true, 'books_count' => 0]
        );
    }

    private function processAuthors(Book $book, string $authorString): void
    {
        $authorNames = array_map('trim', explode(',', $authorString));

        foreach ($authorNames as $authorName) {
            if (empty($authorName)) continue;

            // پیدا کردن یا ایجاد نویسنده
            $author = Author::firstOrCreate(
                ['name' => $authorName],
                [
                    'slug' => Str::slug($authorName . '_' . time()),
                    'is_active' => true,
                    'books_count' => 0
                ]
            );

            // بررسی اینکه آیا رابطه قبلاً وجود دارد
            $exists = DB::table('book_author')
                ->where('book_id', $book->id)
                ->where('author_id', $author->id)
                ->exists();

            if (!$exists) {
                // اضافه کردن رابطه با timestamps صحیح
                DB::table('book_author')->insert([
                    'book_id' => $book->id,
                    'author_id' => $author->id,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                Log::info("✅ نویسنده '{$authorName}' به کتاب '{$book->title}' اضافه شد", [
                    'book_id' => $book->id,
                    'author_id' => $author->id,
                    'author_name' => $authorName
                ]);
            }
        }
    }

    private function processImages(Book $book, string $imageUrl): void
    {
        if (filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            BookImage::updateOrCreate(
                ['book_id' => $book->id],
                ['image_url' => $imageUrl]
            );
        }
    }
}
