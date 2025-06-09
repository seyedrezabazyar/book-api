<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class BookHash extends Model
{
    protected $table = 'book_hashes';

    protected $fillable = [
        'book_id',
        'book_hash',
        'md5',
        'sha1',
        'sha256',
        'crc32',
        'ed2k_hash',
        'btih',
        'magnet_link'
    ];

    protected $casts = [
        'book_id' => 'integer',
    ];

    /**
     * رابطه با کتاب
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * ثبت یا بروزرسانی هش‌های کتاب
     */
    public static function createOrUpdateForBook(Book $book, array $hashData = []): self
    {
        $data = [
            'book_id' => $book->id,
            'book_hash' => $book->content_hash,
            'md5' => $book->content_hash, // همان content_hash
        ];

        // اضافه کردن سایر هش‌ها اگر موجود باشند
        $hashFields = ['sha1', 'sha256', 'crc32', 'ed2k_hash', 'btih', 'magnet_link'];

        foreach ($hashFields as $field) {
            if (!empty($hashData[$field])) {
                $data[$field] = $hashData[$field];
            }
        }

        $bookHash = self::updateOrCreate(
            ['book_id' => $book->id],
            $data
        );

        Log::info("🔐 هش‌های کتاب ثبت/بروزرسانی شد", [
            'book_id' => $book->id,
            'book_title' => $book->title,
            'hash_fields' => array_keys($data),
            'was_existing' => !$bookHash->wasRecentlyCreated
        ]);

        return $bookHash;
    }

    /**
     * بروزرسانی هش‌های جدید بدون تغییر موجودی‌ها
     */
    public function updateMissingHashes(array $newHashData): bool
    {
        $updated = false;
        $updatedFields = [];

        $hashFields = ['sha1', 'sha256', 'crc32', 'ed2k_hash', 'btih', 'magnet_link'];

        foreach ($hashFields as $field) {
            // فقط در صورتی که فیلد خالی باشد و داده جدید موجود باشد
            if (empty($this->$field) && !empty($newHashData[$field])) {
                $this->$field = $newHashData[$field];
                $updatedFields[] = $field;
                $updated = true;
            }
        }

        if ($updated) {
            $this->save();

            Log::info("🔄 هش‌های جدید به کتاب اضافه شد", [
                'book_id' => $this->book_id,
                'updated_fields' => $updatedFields
            ]);
        }

        return $updated;
    }

    /**
     * دریافت تمام هش‌های موجود برای کتاب
     */
    public function getAllHashes(): array
    {
        $hashes = [];

        if ($this->md5) $hashes['md5'] = $this->md5;
        if ($this->sha1) $hashes['sha1'] = $this->sha1;
        if ($this->sha256) $hashes['sha256'] = $this->sha256;
        if ($this->crc32) $hashes['crc32'] = $this->crc32;
        if ($this->ed2k_hash) $hashes['ed2k'] = $this->ed2k_hash;
        if ($this->btih) $hashes['btih'] = $this->btih;
        if ($this->magnet_link) $hashes['magnet'] = $this->magnet_link;

        return $hashes;
    }

    /**
     * بررسی اینکه آیا هش خاصی موجود است
     */
    public function hasHash(string $hashType): bool
    {
        $field = match($hashType) {
            'md5' => 'md5',
            'sha1' => 'sha1',
            'sha256' => 'sha256',
            'crc32' => 'crc32',
            'ed2k' => 'ed2k_hash',
            'btih' => 'btih',
            'magnet' => 'magnet_link',
            default => null
        };

        return $field && !empty($this->$field);
    }

    /**
     * دریافت هش خاص
     */
    public function getHash(string $hashType): ?string
    {
        $field = match($hashType) {
            'md5' => 'md5',
            'sha1' => 'sha1',
            'sha256' => 'sha256',
            'crc32' => 'crc32',
            'ed2k' => 'ed2k_hash',
            'btih' => 'btih',
            'magnet' => 'magnet_link',
            default => null
        };

        return $field ? $this->$field : null;
    }

    /**
     * جستجوی کتاب بر اساس هش
     */
    public static function findBookByHash(string $hash, string $hashType = 'md5'): ?Book
    {
        $field = match($hashType) {
            'md5' => 'md5',
            'sha1' => 'sha1',
            'sha256' => 'sha256',
            'crc32' => 'crc32',
            'ed2k' => 'ed2k_hash',
            'btih' => 'btih',
            default => 'md5'
        };

        $bookHash = self::where($field, $hash)->first();
        return $bookHash?->book;
    }

    /**
     * آمار هش‌های موجود در سیستم
     */
    public static function getHashStats(): array
    {
        return [
            'total_books_with_hashes' => self::count(),
            'md5_hashes' => self::whereNotNull('md5')->count(),
            'sha1_hashes' => self::whereNotNull('sha1')->count(),
            'sha256_hashes' => self::whereNotNull('sha256')->count(),
            'crc32_hashes' => self::whereNotNull('crc32')->count(),
            'ed2k_hashes' => self::whereNotNull('ed2k_hash')->count(),
            'btih_hashes' => self::whereNotNull('btih')->count(),
            'magnet_links' => self::whereNotNull('magnet_link')->count(),
        ];
    }

    /**
     * تولید لینک مگنت اگر btih موجود باشد
     */
    public function generateMagnetLink(): ?string
    {
        if (empty($this->btih)) {
            return null;
        }

        $book = $this->book;
        if (!$book) {
            return null;
        }

        // ساخت لینک مگنت ساده
        $magnetLink = "magnet:?xt=urn:btih:{$this->btih}";

        if ($book->title) {
            $magnetLink .= "&dn=" . urlencode($book->title);
        }

        // اگر لینک مگنت وجود ندارد، آن را ذخیره کن
        if (empty($this->magnet_link)) {
            $this->update(['magnet_link' => $magnetLink]);
        }

        return $magnetLink;
    }

    /**
     * اعتبارسنجی هش‌ها
     */
    public function validateHashes(): array
    {
        $validation = [
            'valid' => true,
            'errors' => [],
            'warnings' => []
        ];

        // بررسی MD5
        if ($this->md5 && !preg_match('/^[a-f0-9]{32}$/i', $this->md5)) {
            $validation['errors'][] = 'MD5 hash format is invalid';
            $validation['valid'] = false;
        }

        // بررسی SHA1
        if ($this->sha1 && !preg_match('/^[a-f0-9]{40}$/i', $this->sha1)) {
            $validation['errors'][] = 'SHA1 hash format is invalid';
            $validation['valid'] = false;
        }

        // بررسی SHA256
        if ($this->sha256 && !preg_match('/^[a-f0-9]{64}$/i', $this->sha256)) {
            $validation['errors'][] = 'SHA256 hash format is invalid';
            $validation['valid'] = false;
        }

        // بررسی CRC32
        if ($this->crc32 && !preg_match('/^[a-f0-9]{8}$/i', $this->crc32)) {
            $validation['errors'][] = 'CRC32 hash format is invalid';
            $validation['valid'] = false;
        }

        // بررسی BTIH
        if ($this->btih && !preg_match('/^[a-f0-9]{40}$/i', $this->btih)) {
            $validation['errors'][] = 'BTIH hash format is invalid';
            $validation['valid'] = false;
        }

        // بررسی Magnet Link
        if ($this->magnet_link && !str_starts_with($this->magnet_link, 'magnet:')) {
            $validation['warnings'][] = 'Magnet link does not start with magnet:';
        }

        return $validation;
    }
}
