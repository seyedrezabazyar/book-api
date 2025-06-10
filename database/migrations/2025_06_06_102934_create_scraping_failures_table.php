<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scraping_failures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('config_id')->constrained()->onDelete('cascade');
            $table->string('url', 1000);
            $table->text('error_message');
            $table->json('error_details')->nullable();
            $table->text('response_content')->nullable();
            $table->integer('http_status')->nullable();
            $table->integer('retry_count')->default(0);
            $table->boolean('is_resolved')->default(false)->index();
            $table->timestamp('last_attempt_at');
            $table->timestamps();

            $table->index(['config_id', 'is_resolved'], 'idx_config_resolved');
            $table->index(['is_resolved', 'last_attempt_at'], 'idx_resolved_attempt');
            $table->index(['config_id', 'created_at'], 'idx_config_created');
        });

        // Cleanup old unresolved failures (older than 30 days)
        if (Schema::hasTable('scraping_failures')) {
            DB::table('scraping_failures')
                ->where('is_resolved', false)
                ->where('created_at', '<', now()->subDays(30))
                ->update(['is_resolved' => true]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('scraping_failures')) {
            Schema::table('scraping_failures', function (Blueprint $table) {
                $table->dropIndex('idx_config_resolved');
                $table->dropIndex('idx_resolved_attempt');
                $table->dropIndex('idx_config_created');
            });
        }
        Schema::dropIfExists('scraping_failures');
    }
};
