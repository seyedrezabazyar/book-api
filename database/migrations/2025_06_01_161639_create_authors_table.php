<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authors', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 200);
            $table->string('slug', 200)->unique()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedBigInteger('books_count')->default(0)->index();
            $table->timestamps();

            $table->fullText('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authors');
    }
};
