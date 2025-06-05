<?php

use App\Http\Controllers\ConfigController;
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
});

require __DIR__.'/auth.php';
