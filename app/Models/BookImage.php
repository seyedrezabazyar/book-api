<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookImage extends Model
{
    protected $table = 'book_images';

    protected $fillable = [
        'book_id',
        'image_url'
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
