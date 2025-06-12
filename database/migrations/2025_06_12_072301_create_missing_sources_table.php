<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('missing_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('config_id')->constrained()->onDelete('cascade');
            $table->string('source_name');
            $table->string('source_id');
            $table->string('reason')->default('not_found'); // not_found, api_error, invalid_data
            $table->text('error_details')->nullable();
            $table->integer('http_status')->nullable();
            $table->timestamp('first_checked_at');
            $table->timestamp('last_checked_at');
            $table->integer('check_count')->default(1);
            $table->boolean('is_permanently_missing')->default(false);
            $table->timestamps();

            $table->unique(['config_id', 'source_name', 'source_id']);
            $table->index(['source_name', 'source_id']);
            $table->index(['config_id', 'is_permanently_missing']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('missing_sources');
    }
};
