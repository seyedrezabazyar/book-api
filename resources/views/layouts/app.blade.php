<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', config('app.name', 'Laravel'))</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <!-- سبک‌های سفارشی -->
    <style>
        body {
            font-family: 'figtree', sans-serif;
            direction: rtl;
            text-align: right;
        }
        .ltr {
            direction: ltr;
            text-align: left;
        }
    </style>
</head>
<body class="font-sans antialiased bg-gray-100">
<div class="min-h-screen">
    @include('layouts.navigation')

    <!-- Page Heading -->
    @hasSection('header')
        <header class="bg-white shadow">
            <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                @yield('header')
            </div>
        </header>
    @endif

    <!-- Page Content -->
    <main class="py-4">
        @yield('content')
    </main>
</div>

<!-- Scripts اضافی -->
@stack('scripts')
</body>
</html>
