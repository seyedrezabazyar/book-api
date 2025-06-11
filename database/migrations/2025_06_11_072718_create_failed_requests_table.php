<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failed_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('config_id')->constrained()->onDelete('cascade');
            $table->string('source_name', 100)->index();
            $table->string('source_id', 100)->index();
            $table->string('url', 1000);
            $table->text('error_message');
            $table->json('error_details')->nullable();
            $table->integer('http_status')->nullable();
            $table->integer('retry_count')->default(0);
            $table->boolean('is_resolved')->default(false)->index();
            $table->timestamp('last_attempt_at');
            $table->timestamp('first_failed_at');
            $table->timestamps();

            $table->unique(['config_id', 'source_name', 'source_id'], 'failed_requests_unique');
            $table->index(['source_name', 'source_id'], 'idx_source_lookup');
            $table->index(['is_resolved', 'retry_count'], 'idx_resolved_retry');
            $table->index(['config_id', 'is_resolved'], 'idx_config_resolved');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_requests');
    }
};
