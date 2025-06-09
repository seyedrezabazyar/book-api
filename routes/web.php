<?php

use App\Http\Controllers\ConfigController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

// Redirect اصلی به configs
Route::get('/', function () {
    return redirect()->route('configs.index');
});

Route::middleware(['auth'])->group(function () {
    // Dashboard همان configs index
    Route::get('/dashboard', function () {
        return redirect()->route('configs.index');
    })->name('dashboard');

    // Routes کانفیگ‌های اصلی
    Route::resource('configs', ConfigController::class);
    Route::get('configs/{config}/logs', [ConfigController::class, 'logs'])->name('configs.logs');
    Route::get('configs/{config}/logs/{log}', [ConfigController::class, 'logDetails'])->name('configs.log-details');

    // Routes اجرای هوشمند
    Route::post('configs/{config}/start', [ConfigController::class, 'executeBackground'])->name('configs.start');
    Route::post('configs/{config}/execute-background', [ConfigController::class, 'executeBackground'])->name('configs.execute-background');
    Route::post('configs/{config}/stop', [ConfigController::class, 'stopExecution'])->name('configs.stop');

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

    // Profile (اختیاری)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
