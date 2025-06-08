<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;

class BookApiController extends Controller
{
    public function index()
    {
        return response()->json(Book::all());
    }
}
