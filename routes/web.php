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

    // مدیریت کانفیگ‌ها - Resource Routes
    Route::resource('configs', ConfigController::class);

    // روت‌های اضافی برای کانفیگ‌ها
    Route::patch('configs/{config}/toggle-status', [ConfigController::class, 'toggleStatus'])
        ->name('configs.toggle-status');

});

// فایل‌های روت احراز هویت
require __DIR__.'/auth.php';
