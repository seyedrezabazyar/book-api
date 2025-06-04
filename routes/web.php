<?php

// routes/web.php

use App\Http\Controllers\ConfigController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Config Routes
|--------------------------------------------------------------------------
|
| روت‌های مربوط به مدیریت کانفیگ‌ها
|
*/

// گروه روت‌های کانفیگ با middleware های مورد نیاز
Route::middleware(['web'])->group(function () {

    // روت‌های CRUD کامل برای کانفیگ‌ها
    Route::resource('configs', ConfigController::class)->names([
        'index' => 'configs.index',
        'create' => 'configs.create',
        'store' => 'configs.store',
        'show' => 'configs.show',
        'edit' => 'configs.edit',
        'update' => 'configs.update',
        'destroy' => 'configs.destroy'
    ]);

    // روت اضافی برای تغییر وضعیت کانفیگ
    Route::patch('configs/{config}/toggle-status', [ConfigController::class, 'toggleStatus'])
        ->name('configs.toggle-status');

    // روت اصلی برای ریدایرکت به کانفیگ‌ها
    Route::get('/', function () {
        return redirect()->route('configs.index');
    });
});

// در صورت استفاده از احراز هویت، می‌توانید middleware auth اضافه کنید:
/*
Route::middleware(['web', 'auth'])->group(function () {
    Route::resource('configs', ConfigController::class);
    Route::patch('configs/{config}/toggle-status', [ConfigController::class, 'toggleStatus'])
         ->name('configs.toggle-status');
});
*/
