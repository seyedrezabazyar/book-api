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

    // اجرای بک‌گراند
    Route::post('configs/{config}/execute-background', [ConfigController::class, 'executeBackground'])->name('configs.execute-background');

    // اجرای فوری
    Route::post('configs/{config}/run-sync', [ConfigController::class, 'runSync'])->name('configs.run-sync');

    // توقف اجرا - اینجا مشکل بود! باید stopExecution باشه نه stop
    Route::post('configs/{config}/stop-execution', [ConfigController::class, 'stopExecution'])->name('configs.stop-execution');

    // مدیریت Worker
    Route::get('configs/worker/status', [ConfigController::class, 'workerStatus'])->name('configs.worker.status');
    Route::post('configs/{config}/worker/manage', [ConfigController::class, 'manageWorker'])->name('configs.worker.manage');

    // Profile routes
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
