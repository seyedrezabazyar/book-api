<?php

use App\Http\Controllers\ConfigController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

// صفحه خانه
Route::get('/', function () {
    return redirect()->route('configs.index');
});

// داشبورد
Route::get('/dashboard', function () {
    return redirect()->route('configs.index');
})->middleware(['auth', 'verified'])->name('dashboard');

// گروه روت‌های احراز هویت شده
Route::middleware('auth')->group(function () {

    // مدیریت پروفایل - روت‌های مفقود شده
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // مدیریت کانفیگ‌ها - Resource Routes
    Route::resource('configs', ConfigController::class);

    // روت‌های کنترل اسکرپر
    Route::post('configs/{config}/start', [ConfigController::class, 'start'])
        ->name('configs.start');

    Route::post('configs/{config}/stop', [ConfigController::class, 'stop'])
        ->name('configs.stop');

    Route::post('configs/{config}/reset', [ConfigController::class, 'reset'])
        ->name('configs.reset');

    // کنترل همه کانفیگ‌ها
    Route::post('configs/start-all', [ConfigController::class, 'startAll'])
        ->name('configs.start-all');

    Route::post('configs/stop-all', [ConfigController::class, 'stopAll'])
        ->name('configs.stop-all');

    // مدیریت شکست‌ها
    Route::get('configs/{config}/failures', [ConfigController::class, 'failures'])
        ->name('configs.failures');

    Route::post('configs/{config}/failures/{failure}/resolve', [ConfigController::class, 'resolveFailure'])
        ->name('configs.resolve-failure');

    Route::post('configs/{config}/failures/resolve-all', [ConfigController::class, 'resolveAllFailures'])
        ->name('configs.resolve-all-failures');

    // روت‌های آمار و تست
    Route::get('configs/{config}/stats', [ConfigController::class, 'stats'])
        ->name('configs.stats');

    Route::get('configs/{config}/debug', [ConfigController::class, 'debug'])
        ->name('configs.debug');

    Route::get('configs/{config}/debug-api', [ConfigController::class, 'debugApi'])
        ->name('configs.debug-api');

    Route::get('configs/test', [ConfigController::class, 'testPage'])
        ->name('configs.test');

    Route::post('configs/test-url', [ConfigController::class, 'testUrl'])
        ->name('configs.test-url');

    // روت‌های اجرا
    Route::post('configs/{config}/run', [ConfigController::class, 'run'])
        ->name('configs.run');

    Route::post('configs/{config}/run-sync', [ConfigController::class, 'runSync'])
        ->name('configs.run-sync');

    Route::delete('configs/{config}/clear-stats', [ConfigController::class, 'clearStats'])
        ->name('configs.clear-stats');
});

require __DIR__.'/auth.php';
