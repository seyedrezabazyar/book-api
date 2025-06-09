<?php

use App\Http\Controllers\ConfigController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Models\Book;

// Redirect اصلی به configs
Route::get('/', function () {
    return redirect()->route('configs.index');
});

Route::middleware(['auth'])->group(function () {
    // Dashboard همان configs index
    Route::get('/dashboard', function () {
        return redirect()->route('configs.index');
    })->name('dashboard');

    // Routes کانفیگ‌های هوشمند
    Route::resource('configs', ConfigController::class);
    Route::get('configs/{config}/logs', [ConfigController::class, 'logs'])->name('configs.logs');
    Route::get('configs/{config}/logs/{log}', [ConfigController::class, 'logDetails'])->name('configs.log-details');

    // Routes اجرای هوشمند
    Route::post('configs/{config}/start', [ConfigController::class, 'executeBackground'])->name('configs.start');
    Route::post('configs/{config}/execute-background', [ConfigController::class, 'executeBackground'])->name('configs.execute-background');
    Route::post('configs/{config}/stop', [ConfigController::class, 'stopExecution'])->name('configs.stop');

    // Routes قدیمی (اختیاری - برای سازگاری)
    Route::post('configs/{config}/run-sync', [ConfigController::class, 'runAsync'])->name('configs.run-sync');

    // Worker management
    Route::prefix('admin/worker')->name('admin.worker.')->group(function () {
        Route::post('start', function() {
            $result = \App\Services\QueueManagerService::startWorker();
            return response()->json([
                'success' => $result,
                'message' => $result ? '✅ Worker شروع شد' : '❌ خطا در شروع Worker',
                'worker_status' => \App\Services\QueueManagerService::getWorkerStatus()
            ]);
        })->name('start');

        Route::post('stop', function() {
            $result = \App\Services\QueueManagerService::stopWorker();
            return response()->json([
                'success' => $result,
                'message' => $result ? '✅ Worker متوقف شد' : '❌ خطا در توقف Worker'
            ]);
        })->name('stop');

        Route::post('restart', function() {
            $result = \App\Services\QueueManagerService::restartWorker();
            return response()->json([
                'success' => $result,
                'message' => $result ? '✅ Worker راه‌اندازی مجدد شد' : '❌ خطا در راه‌اندازی مجدد Worker'
            ]);
        })->name('restart');

        Route::get('status', [ConfigController::class, 'workerStatus'])->name('status');
    });

    // Log management
    Route::prefix('admin/logs')->name('admin.logs.')->group(function () {
        Route::post('{log}/fix-status', [ConfigController::class, 'fixLogStatus'])->name('fix-status');
        Route::post('{log}/sync-stats', [ConfigController::class, 'syncLogStats'])->name('sync-stats');
    });

    // Source ID Management - کامند‌های جدید
    Route::prefix('admin/source-management')->name('admin.source.')->group(function () {
        // مدیریت source ID ها
        Route::get('analyze/{config}', function($configId) {
            \Artisan::call('crawl:manage-sources', [
                'action' => 'analyze',
                '--config' => $configId
            ]);

            return response()->json([
                'success' => true,
                'message' => '📊 تحلیل source ID ها انجام شد',
                'output' => \Artisan::output()
            ]);
        })->name('analyze');

        Route::get('missing/{config}', function($configId) {
            \Artisan::call('crawl:manage-sources', [
                'action' => 'missing',
                '--config' => $configId,
                '--limit' => 100
            ]);

            return response()->json([
                'success' => true,
                'message' => '🔍 جستجوی ID های مفقود انجام شد',
                'output' => \Artisan::output()
            ]);
        })->name('missing');

        Route::post('process-missing/{config}', function($configId) {
            \Artisan::call('crawl:missing-ids', [
                'config' => $configId,
                '--limit' => 50,
                '--background' => true
            ]);

            return response()->json([
                'success' => true,
                'message' => '🚀 پردازش ID های مفقود در پس‌زمینه شروع شد',
                'output' => \Artisan::output()
            ]);
        })->name('process-missing');

        Route::post('cleanup/{config}', function($configId) {
            \Artisan::call('crawl:manage-sources', [
                'action' => 'cleanup',
                '--config' => $configId,
                '--fix' => true
            ]);

            return response()->json([
                'success' => true,
                'message' => '🧹 پاکسازی انجام شد',
                'output' => \Artisan::output()
            ]);
        })->name('cleanup');

        Route::get('report/{config}', function($configId) {
            \Artisan::call('crawl:manage-sources', [
                'action' => 'report',
                '--config' => $configId
            ]);

            return response()->json([
                'success' => true,
                'message' => '📋 گزارش تولید شد',
                'output' => \Artisan::output()
            ]);
        })->name('report');
    });

    // Profile (اگر نیاز داری)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// فقط یکی از این دو را نگه دارید:
Route::get('/api/books', function () {
    return response()->json(\App\Models\Book::limit(100)->get());
});

require __DIR__.'/auth.php';
