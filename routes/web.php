<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ConfigController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| در اینجا می‌توانید روت‌های وب اپلیکیشن خود را ثبت کنید. این روت‌ها
| توسط RouteServiceProvider بارگذاری می‌شوند و همگی در گروه middleware "web" قرار می‌گیرند.
|
*/

// صفحه خانه
Route::get('/', function () {
    return view('welcome');
});

// داشبورد (نیاز به احراز هویت)
Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

// گروه روت‌های احراز هویت شده
Route::middleware('auth')->group(function () {

    // مدیریت پروفایل کاربر
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // روت‌های تست کانفیگ‌ها (باید قبل از resource باشد)
    Route::get('configs/test-page', [ConfigController::class, 'testPage'])->name('configs.test-page');
    Route::post('configs/test-url', [ConfigController::class, 'testUrl'])->name('configs.test-url');

    // مدیریت کانفیگ‌ها - Resource Routes
    Route::resource('configs', ConfigController::class);

    // روت‌های اضافی برای کانفیگ‌ها
    Route::patch('configs/{config}/toggle-status', [ConfigController::class, 'toggleStatus'])
        ->name('configs.toggle-status');

    // روت‌های اجرای کانفیگ‌ها
    Route::post('configs/{config}/run', [ConfigController::class, 'run'])
        ->name('configs.run');

    Route::post('configs/{config}/run-sync', [ConfigController::class, 'runSync'])
        ->name('configs.run-sync');

    Route::post('configs/{config}/stop', [ConfigController::class, 'stop'])
        ->name('configs.stop');

    Route::post('configs/run-all', [ConfigController::class, 'runAll'])
        ->name('configs.run-all');

    // روت‌های آمار و گزارش
    Route::get('configs/{config}/stats', [ConfigController::class, 'stats'])
        ->name('configs.stats');

    Route::delete('configs/{config}/clear-stats', [ConfigController::class, 'clearStats'])
        ->name('configs.clear-stats');

    // روت تست کانفیگ
    Route::post('configs/{config}/test', [ConfigController::class, 'testConfig'])
        ->name('configs.test');

});

// فایل‌های روت احراز هویت
require __DIR__.'/auth.php';
