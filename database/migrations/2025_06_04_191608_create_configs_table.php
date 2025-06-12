<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configs', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique()->index();
            $table->string('base_url', 500);
            $table->enum('source_type', ['api', 'crawler'])->default('api')->index(); // تغییر: crawler اضافه شد
            $table->string('source_name', 100)->index();
            $table->unsignedSmallInteger('timeout')->default(30);
            $table->unsignedSmallInteger('delay_seconds')->default(5);
            $table->unsignedTinyInteger('records_per_run')->default(10);
            $table->unsignedTinyInteger('page_delay')->default(5);
            $table->unsignedInteger('start_page')->nullable();
            $table->unsignedInteger('max_pages')->default(1000);
            $table->integer('current_page')->default(0);
            $table->unsignedInteger('last_source_id')->default(0)->index();
            $table->boolean('auto_resume')->default(true);
            $table->boolean('fill_missing_fields')->default(true);
            $table->boolean('update_descriptions')->default(true);

            // جدید: فیلدهای crawler
            $table->string('page_pattern', 500)->nullable()->comment('Pattern برای ساخت URL صفحات مثل: /book/{id}');
            $table->string('user_agent', 500)->nullable()->comment('User Agent برای crawler');
            $table->boolean('follow_redirects')->default(true);
            $table->boolean('verify_ssl')->default(true);
            $table->text('headers')->nullable()->comment('JSON headers اضافی برای crawler');

            $table->json('config_data');
            $table->unsignedInteger('total_processed')->default(0);
            $table->unsignedInteger('total_success')->default(0);
            $table->unsignedInteger('total_failed')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->boolean('is_running')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->index(['source_type', 'source_name'], 'idx_source_info');
            $table->index(['is_running', 'created_at'], 'idx_running_status');
            $table->index(['is_active', 'created_at'], 'idx_active_created');
        });

        // Update existing records
        if (Schema::hasTable('configs')) {
            DB::table('configs')
                ->whereNull('is_active')
                ->update(['is_active' => true]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('configs')) {
            Schema::table('configs', function (Blueprint $table) {
                $table->dropIndex('idx_source_info');
                $table->dropIndex('idx_running_status');
                $table->dropIndex('idx_active_created');
                $table->dropColumn([
                    'page_pattern', 'user_agent', 'headers', 'follow_redirects', 'verify_ssl'
                ]);
            });
        }
        Schema::dropIfExists('configs');
    }
};
