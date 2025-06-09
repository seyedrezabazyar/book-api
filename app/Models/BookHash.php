<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookHash extends Model
{
    protected $table = 'book_hashes';

    protected $fillable = [
        'book_id',
        'md5',
        'sha1',
        'sha256',
        'crc32',
        'ed2k_hash',
        'btih',
        'magnet_link'
    ];

    /**
     * رابطه با کتاب
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * دریافت تمام هش‌های موجود
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
}
