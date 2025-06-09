<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Log;

class Book extends Model
{
    use HasFactory;

    protected $table = 'books';

    protected $fillable = [
        'title',
        'description',
        'excerpt',
        'slug',
        'isbn',
        'publication_year',
        'pages_count',
        'language',
        'format',
        'file_size',
        'category_id',
        'publisher_id',
        'downloads_count',
        'status'
    ];

    protected $casts = [
        'publication_year' => 'integer',
        'pages_count' => 'integer',
        'file_size' => 'integer',
        'downloads_count' => 'integer'
    ];

    /**
     * روابط
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }

    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(Author::class, 'book_author');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(BookSource::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(BookImage::class);
    }

    public function hashes(): HasOne
    {
        return $this->hasOne(BookHash::class);
    }

    /**
     * اسکوپ‌ها
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeSearch($query, $search)
    {
        if (!empty($search)) {
            return $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }
        return $query;
    }

    /**
     * متدهای کمکی
     */
    public function getMainHash(): ?string
    {
        return $this->hashes?->md5;
    }

    public function getAllHashes(): array
    {
        return $this->hashes ? $this->hashes->getAllHashes() : [];
    }

    /**
     * بررسی تکراری بودن بر اساس MD5
     */
    public static function findByMd5(string $md5): ?self
    {
        $bookHash = BookHash::where('md5', $md5)->first();
        return $bookHash ? $bookHash->book : null;
    }

    /**
     * ایجاد کتاب جدید با تمام روابط
     */
    public static function createWithDetails(array $bookData, array $hashData = [], string $sourceName = null, string $sourceId = null): self
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($bookData, $hashData, $sourceName, $sourceId) {
            // ایجاد category و publisher
            $category = Category::firstOrCreate(
                ['name' => $bookData['category'] ?? 'عمومی'],
                [
                    'slug' => \Illuminate\Support\Str::slug(($bookData['category'] ?? 'عمومی') . '_' . time()),
                    'is_active' => true,
                    'books_count' => 0
                ]
            );

            $publisher = null;
            if (!empty($bookData['publisher'])) {
                $publisher = Publisher::firstOrCreate(
                    ['name' => $bookData['publisher']],
                    [
                        'slug' => \Illuminate\Support\Str::slug($bookData['publisher'] . '_' . time()),
                        'is_active' => true,
                        'books_count' => 0
                    ]
                );
            }

            // ایجاد کتاب
            $book = self::create([
                'title' => $bookData['title'],
                'description' => $bookData['description'] ?? null,
                'excerpt' => \Illuminate\Support\Str::limit($bookData['description'] ?? $bookData['title'], 200),
                'slug' => \Illuminate\Support\Str::slug($bookData['title'] . '_' . time()),
                'isbn' => $bookData['isbn'] ?? null,
                'publication_year' => $bookData['publication_year'] ?? null,
                'pages_count' => $bookData['pages_count'] ?? null,
                'language' => $bookData['language'] ?? 'fa',
                'format' => $bookData['format'] ?? 'pdf',
                'file_size' => $bookData['file_size'] ?? null,
                'category_id' => $category->id,
                'publisher_id' => $publisher?->id,
                'downloads_count' => 0,
                'status' => 'active'
            ]);

            // ایجاد هش‌ها - اطمینان از وجود MD5
            if (!empty($hashData['md5'])) {
                BookHash::create([
                    'book_id' => $book->id,
                    'md5' => $hashData['md5'],
                    'sha1' => $hashData['sha1'] ?? null,
                    'sha256' => $hashData['sha256'] ?? null,
                    'crc32' => $hashData['crc32'] ?? null,
                    'ed2k_hash' => $hashData['ed2k'] ?? null,
                    'btih' => $hashData['btih'] ?? null,
                    'magnet_link' => $hashData['magnet'] ?? null,
                ]);
            }

            // اضافه کردن نویسندگان
            if (!empty($bookData['author'])) {
                $book->addAuthors($bookData['author']);
            }

            // اضافه کردن تصاویر
            if (!empty($bookData['image_url'])) {
                BookImage::create([
                    'book_id' => $book->id,
                    'image_url' => $bookData['image_url']
                ]);
            }

            // اضافه کردن منبع
            if ($sourceName && $sourceId) {
                BookSource::recordBookSource($book->id, $sourceName, $sourceId);
            }

            Log::info("✨ کتاب جدید ایجاد شد", [
                'book_id' => $book->id,
                'title' => $book->title,
                'md5' => $hashData['md5'] ?? null,
                'source' => $sourceName,
            ]);

            return $book;
        });
    }

    /**
     * بروزرسانی هوشمند کتاب
     */
    public function smartUpdate(array $newData, array $options = []): array
    {
        $changes = [];
        $needsUpdate = false;

        // تکمیل فیلدهای خالی
        if ($options['fill_missing_fields'] ?? true) {
            $fillableFields = ['publication_year', 'pages_count', 'file_size', 'language', 'format'];
            foreach ($fillableFields as $field) {
                if (empty($this->$field) && !empty($newData[$field])) {
                    $this->$field = $newData[$field];
                    $changes['filled_fields'][] = $field;
                    $needsUpdate = true;
                }
            }
        }

        // بهبود توضیحات
        if (($options['update_descriptions'] ?? true) && !empty($newData['description'])) {
            $existingLength = strlen(trim($this->description ?? ''));
            $newLength = strlen(trim($newData['description']));

            if ($existingLength == 0 || $newLength > $existingLength * 1.3) {
                $this->description = $newData['description'];
                $changes['updated_description'] = true;
                $needsUpdate = true;
            }
        }

        // ادغام ISBN
        if (!empty($newData['isbn'])) {
            $merged = $this->mergeIsbn($newData['isbn']);
            if ($merged) {
                $changes['merged_isbn'] = true;
                $needsUpdate = true;
            }
        }

        // ذخیره تغییرات
        if ($needsUpdate) {
            $this->save();
        }

        // ادغام نویسندگان
        if (!empty($newData['author'])) {
            $addedAuthors = $this->addAuthors($newData['author']);
            if (!empty($addedAuthors)) {
                $changes['added_authors'] = $addedAuthors;
            }
        }

        // بروزرسانی هش‌ها
        $this->updateHashes($newData);

        return [
            'updated' => $needsUpdate,
            'changes' => $changes,
            'action' => $needsUpdate || !empty($changes) ? 'updated' : 'no_changes'
        ];
    }

    private function mergeIsbn(string $newIsbn): bool
    {
        if (empty($this->isbn)) {
            $this->isbn = $newIsbn;
            return true;
        }

        $existing = array_filter(explode(',', $this->isbn));
        $new = array_filter(explode(',', $newIsbn));
        $merged = array_unique(array_merge($existing, $new));

        $mergedIsbn = implode(', ', $merged);
        if ($mergedIsbn !== $this->isbn) {
            $this->isbn = $mergedIsbn;
            return true;
        }

        return false;
    }

    private function addAuthors(string $authorsString): array
    {
        $authorNames = array_map('trim', explode(',', $authorsString));
        $existingNames = $this->authors()->pluck('name')->toArray();
        $addedAuthors = [];

        foreach ($authorNames as $name) {
            if (empty($name) || in_array($name, $existingNames)) continue;

            $author = Author::firstOrCreate(
                ['name' => $name],
                [
                    'slug' => \Illuminate\Support\Str::slug($name . '_' . time()),
                    'is_active' => true
                ]
            );

            if (!$this->authors()->where('author_id', $author->id)->exists()) {
                $this->authors()->attach($author->id);
                $addedAuthors[] = $name;
            }
        }

        return $addedAuthors;
    }

    private function updateHashes(array $data): void
    {
        if (empty($this->hashes)) return;

        $hashFields = ['sha1', 'sha256', 'crc32', 'ed2k', 'btih', 'magnet'];
        $updates = [];

        foreach ($hashFields as $field) {
            $dbField = $field === 'ed2k' ? 'ed2k_hash' : ($field === 'magnet' ? 'magnet_link' : $field);

            if (!empty($data[$field]) && empty($this->hashes->$dbField)) {
                $updates[$dbField] = $data[$field];
            }
        }

        if (!empty($updates)) {
            $this->hashes->update($updates);
        }
    }

    /**
     * محاسبه MD5 بر اساس اطلاعات کتاب
     */
    public static function calculateContentMd5(array $data): string
    {
        $content = implode('|', [
            strtolower(trim($data['title'] ?? '')),
            strtolower(trim($data['author'] ?? '')),
            trim($data['isbn'] ?? ''),
            $data['publication_year'] ?? '',
            $data['pages_count'] ?? ''
        ]);

        return md5($content);
    }

    /**
     * پیدا کردن کتاب بر اساس محتوا
     */
    public static function findByContent(array $data): ?self
    {
        $md5 = self::calculateContentMd5($data);
        return self::findByMd5($md5);
    }
}
