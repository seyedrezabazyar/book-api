<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('email', 100)->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->enum('role', ['user', 'admin'])->default('user');
            $table->rememberToken();
            $table->timestamps();

            $table->index(['role', 'created_at'], 'idx_role_created');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index(['created_at'], 'idx_token_created');
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->ipAddress();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity');
            $table->timestamps();

            $table->index(['user_id', 'last_activity'], 'idx_user_activity');
        });

        // Update existing records
        if (Schema::hasTable('password_reset_tokens')) {
            DB::table('password_reset_tokens')
                ->whereNull('updated_at')
                ->update(['updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_role_created');
        });

        Schema::table('password_reset_tokens', function (Blueprint $table) {
            $table->dropIndex('idx_token_created');
            $table->dropColumn('updated_at');
        });

        Schema::table('sessions', function (Blueprint $table) {
            $table->dropTimestamps();
            $table->dropIndex('idx_user_activity');
        });

        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
