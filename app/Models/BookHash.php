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
}
