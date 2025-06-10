<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('config_id')->constrained()->onDelete('cascade');
            $table->string('execution_id', 50)->unique();
            $table->enum('status', ['running', 'completed', 'failed', 'stopped'])->default('running')->index();
            $table->unsignedInteger('total_processed')->default(0);
            $table->unsignedInteger('total_success')->default(0);
            $table->unsignedInteger('total_failed')->default(0);
            $table->unsignedInteger('total_duplicate')->default(0);
            $table->unsignedInteger('total_enhanced')->default(0);
            $table->decimal('execution_time', 10, 2)->nullable();
            $table->decimal('success_rate', 5, 2)->nullable();
            $table->json('log_details')->nullable();
            $table->text('error_message')->nullable();
            $table->text('stop_reason')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index(['config_id', 'status', 'started_at'], 'idx_config_status_start');
            $table->index(['status', 'started_at'], 'idx_status_start');
            $table->index(['config_id', 'total_enhanced'], 'idx_config_enhanced');
            $table->index('last_activity_at', 'idx_last_activity');
        });

        // Cleanup old running logs and calculate execution time
        if (Schema::hasTable('execution_logs')) {
            DB::table('execution_logs')
                ->where('status', 'running')
                ->where('created_at', '<', now()->subDays(30))
                ->update([
                    'status' => 'stopped',
                    'finished_at' => DB::raw('updated_at'),
                    'stop_reason' => 'اصلاح خودکار - اجرای قدیمی',
                    'error_message' => 'اجرای قدیمی که به صورت خودکار متوقف شد'
                ]);

            DB::table('execution_logs')
                ->whereNull('execution_time')
                ->whereNotNull('started_at')
                ->whereNotNull('finished_at')
                ->update([
                    'execution_time' => DB::raw('TIMESTAMPDIFF(SECOND, started_at, finished_at)')
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('execution_logs')) {
            Schema::table('execution_logs', function (Blueprint $table) {
                $table->dropIndex('idx_config_status_start');
                $table->dropIndex('idx_status_start');
                $table->dropIndex('idx_config_enhanced');
                $table->dropIndex('idx_last_activity');
                $table->dropColumn('total_enhanced');
            });
        }
        Schema::dropIfExists('execution_logs');
    }
};
