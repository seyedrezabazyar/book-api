<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // کاربر ادمین
        User::create([
            'name' => 'مدیر سیستم',
            'email' => 'admin@bookstore.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        // کاربر عادی
        User::create([
            'name' => 'کاربر تست',
            'email' => 'user@bookstore.com',
            'password' => Hash::make('password'),
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        // کاربران تصادفی
        User::factory(10)->create();
    }
}
