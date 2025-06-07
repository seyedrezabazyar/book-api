<?php

use App\Http\Controllers\ConfigController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('configs.index');
});

Route::get('/dashboard', function () {
    return redirect()->route('configs.index');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth'])->group(function () {

    // Routes اصلی کانفیگ‌ها
    Route::resource('configs', ConfigController::class);

    // Routes اضافی مورد نیاز
    Route::get('configs/{config}/logs', [ConfigController::class, 'logs'])->name('configs.logs');
    Route::get('configs/{config}/logs/{log}', [ConfigController::class, 'logDetails'])->name('configs.log-details');

    // Routes اجرا و مدیریت
    Route::post('configs/{config}/start', [ConfigController::class, 'executeBackground'])->name('configs.start');
    Route::post('configs/{config}/execute-background', [ConfigController::class, 'executeBackground'])->name('configs.execute-background');
    Route::post('configs/{config}/stop', [ConfigController::class, 'stopExecution'])->name('configs.stop');

    // مدیریت Worker
    Route::post('admin/worker/start', function() {
        $result = \App\Services\QueueManagerService::startWorker();
        return response()->json([
            'success' => $result,
            'message' => $result ? '✅ Worker شروع شد' : '❌ خطا در شروع Worker',
            'worker_status' => \App\Services\QueueManagerService::getWorkerStatus()
        ]);
    })->name('admin.worker.start');

    Route::post('admin/worker/stop', function() {
        $result = \App\Services\QueueManagerService::stopWorker();
        return response()->json([
            'success' => $result,
            'message' => $result ? '✅ Worker متوقف شد' : '❌ خطا در توقف Worker',
            'worker_status' => \App\Services\QueueManagerService::getWorkerStatus()
        ]);
    })->name('admin.worker.stop');

    Route::post('admin/worker/restart', function() {
        $result = \App\Services\QueueManagerService::restartWorker();
        return response()->json([
            'success' => $result,
            'message' => $result ? '✅ Worker راه‌اندازی مجدد شد' : '❌ خطا در راه‌اندازی مجدد Worker',
            'worker_status' => \App\Services\QueueManagerService::getWorkerStatus()
        ]);
    })->name('admin.worker.restart');

    Route::get('admin/worker/status', [ConfigController::class, 'workerStatus'])->name('admin.worker.status');

    // Profile routes
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Routes اصلاح لاگ
    Route::post('admin/logs/{log}/fix-status', [ConfigController::class, 'fixLogStatus'])->name('admin.logs.fix-status');
    Route::post('admin/logs/{log}/sync-stats', [ConfigController::class, 'syncLogStats'])->name('admin.logs.sync-stats');
});

require __DIR__.'/auth.php';
