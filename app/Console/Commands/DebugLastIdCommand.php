<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Config;
use App\Models\BookSource;

class DebugLastIdCommand extends Command
{
    protected $signature = 'debug:last-id {config_id}';
    protected $description = 'Debug آخرین ID در book_sources';

    public function handle(): int
    {
        $configId = $this->argument('config_id');
        $config = Config::find($configId);

        if (!$config) {
            $this->error("❌ کانفیگ با ID {$configId} یافت نشد!");
            return Command::FAILURE;
        }

        $this->info("🔍 Debug آخرین ID برای کانفیگ: {$config->name}");
        $this->info("📊 منبع: {$config->source_name}");
        $this->newLine();

        // نمایش همه source_id ها برای این منبع
        $allSourceIds = BookSource::where('source_name', $config->source_name)
            ->whereRaw('source_id REGEXP "^[0-9]+$"')
            ->orderByRaw('CAST(source_id AS UNSIGNED) DESC')
            ->limit(10)
            ->pluck('source_id')
            ->toArray();

        $this->info("🔢 آخرین 10 source_id (مرتب‌سازی صحیح):");
        foreach ($allSourceIds as $sourceId) {
            $this->line("   • {$sourceId}");
        }
        $this->newLine();

        // مقایسه روش‌های مختلف
        $method1 = $config->getLastSourceIdFromBookSources();
        $this->info("🎯 متد کانفیگ: {$method1}");

        $method2 = BookSource::where('source_name', $config->source_name)
            ->whereRaw('source_id REGEXP "^[0-9]+$"')
            ->orderByRaw('CAST(source_id AS UNSIGNED) DESC')
            ->value('source_id');
        $this->info("🔍 Query مستقیم: " . ($method2 ?: 'null'));

        // استفاده صحیح از DB::raw()
        $method3 = BookSource::where('source_name', $config->source_name)
            ->whereRaw('source_id REGEXP "^[0-9]+$"')
            ->max(DB::raw('CAST(source_id AS UNSIGNED)'));
        $this->info("📈 Max query: " . ($method3 ?: 'null'));

        // نمایش getSmartStartPage
        $smartStart = $config->getSmartStartPage();
        $this->info("🧠 Smart start page: {$smartStart}");

        $this->newLine();
        $this->info("📋 خلاصه:");
        $this->line("   • کل رکوردهای این منبع: " . BookSource::where('source_name', $config->source_name)->count());
        $this->line("   • آخرین ID: " . ($method1 ?: 'یافت نشد'));
        $this->line("   • ID بعدی پیشنهادی: " . ($method1 + 1));

        return Command::SUCCESS;
    }
}
