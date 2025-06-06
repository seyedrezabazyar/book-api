<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class SuperSimpleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 30;
    public $tries = 1;

    public function handle(): void
    {
        // لاگ در فایل
        Log::info("🚀 SuperSimpleJob شروع شد در: " . now());

        // ایجاد رکورد تست در دیتابیس
        DB::table('users')->insert([
            'name' => 'Test Job User',
            'email' => 'test-job-' . time() . '@example.com',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now()
        ]);

        Log::info("✅ SuperSimpleJob تمام شد! کاربر تست ایجاد شد.");
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("❌ SuperSimpleJob شکست خورد: " . $exception->getMessage());
    }
}
