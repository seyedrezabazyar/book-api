<?php

namespace App\Helpers;

class UserAgentHelper
{
    public static function getRandomUserAgent()
    {
        $path = storage_path('app/user_agents.txt');
        if (!file_exists($path)) {
            return null; // یا یک User Agent پیش‌فرض
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return null;
        }
        return $lines[array_rand($lines)];
    }
}
