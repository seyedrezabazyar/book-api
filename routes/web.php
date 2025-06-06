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

Route::middleware('auth')->group(function () {
    // مدیریت پروفایل
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // مدیریت کانفیگ‌ها
    Route::resource('configs', ConfigController::class);

    // اجرای فوری
    Route::post('configs/{config}/run-sync', [ConfigController::class, 'runSync'])
        ->name('configs.run-sync');

    // مشاهده لاگ‌ها
    Route::get('configs/{config}/logs', [ConfigController::class, 'logs'])
        ->name('configs.logs');

    Route::get('configs/{config}/logs/{log}', [ConfigController::class, 'logDetails'])
        ->name('configs.log-details');
});

require __DIR__.'/auth.php';
