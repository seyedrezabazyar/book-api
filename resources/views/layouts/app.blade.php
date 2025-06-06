<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'API Data Scraper')</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Font -->
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Vazirmatn', sans-serif;
        }

        /* بهبود نمایش متن فارسی */
        .rtl-text {
            direction: rtl;
            text-align: right;
        }

        /* انیمیشن‌های سفارشی */
        .fade-in {
            animation: fadeIn 0.3s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* بهبود نمایش جداول */
        .table-responsive {
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
        }

        /* حالت تاریک برای کدها */
        pre {
            background-color: #1f2937;
            color: #f9fafb;
            padding: 1rem;
            border-radius: 0.5rem;
            overflow-x: auto;
        }

        /* استایل برای آیکون‌های emoji */
        .emoji-icon {
            font-style: normal;
            font-size: 1.2em;
        }
    </style>

    @stack('styles')
</head>
<body class="bg-gray-100 min-h-screen">
<!-- هدر -->
<header class="bg-white shadow-sm border-b border-gray-200">
    <div class="container mx-auto px-4">
        <div class="flex items-center justify-between h-16">
            <!-- لوگو و عنوان -->
            <div class="flex items-center">
                <a href="{{ route('configs.index') }}" class="flex items-center space-x-3 space-x-reverse">
                    <div class="text-2xl emoji-icon">🤖</div>
                    <div>
                        <h1 class="text-xl font-bold text-gray-800">API Data Scraper</h1>
                        <p class="text-xs text-gray-500">مدیریت هوشمند دریافت داده</p>
                    </div>
                </a>
            </div>

            <!-- منوی اصلی -->
            <nav class="hidden md:flex items-center space-x-6 space-x-reverse">
                <a href="{{ route('configs.index') }}"
                   class="flex items-center space-x-2 space-x-reverse px-3 py-2 text-sm font-medium text-gray-700 hover:text-blue-600 transition-colors {{ request()->routeIs('configs.*') ? 'text-blue-600 border-b-2 border-blue-600' : '' }}">
                    <span class="emoji-icon">⚙️</span>
                    <span>کانفیگ‌ها</span>
                </a>
            </nav>

            <!-- منوی کاربر -->
            <div class="flex items-center space-x-4 space-x-reverse">
                @auth
                    <!-- اعلان‌ها -->
                    @php
                        // بررسی امن وجود جدول
                        $totalFailures = 0;
                        try {
                            if (Schema::hasTable('scraping_failures')) {
                                $totalFailures = \App\Models\ScrapingFailure::where('is_resolved', false)->count();
                            }
                        } catch (\Exception $e) {
                            // در صورت خطا، تعداد خطاها صفر در نظر گرفته می‌شود
                            $totalFailures = 0;
                        }
                    @endphp

                    @if($totalFailures > 0)
                        <a href="{{ route('configs.index') }}?filter=failures"
                           class="relative text-orange-600 hover:text-orange-800 transition-colors"
                           title="خطاهای حل نشده">
                            <span class="text-lg emoji-icon">🚨</span>
                            <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full h-4 w-4 flex items-center justify-center font-bold">
                                    {{ $totalFailures > 99 ? '99+' : $totalFailures }}
                                </span>
                        </a>
                    @endif

                    <!-- منوی کاربر -->
                    <div class="relative" x-data="{ open: false }">
                        <button @click="open = !open"
                                class="flex items-center space-x-2 space-x-reverse text-sm text-gray-700 hover:text-gray-900 focus:outline-none">
                            <div class="w-8 h-8 bg-gray-300 rounded-full flex items-center justify-center">
                                    <span class="text-sm font-medium text-gray-700">
                                        {{ substr(auth()->user()->name, 0, 1) }}
                                    </span>
                            </div>
                            <span class="hidden md:block">{{ auth()->user()->name }}</span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>

                        <div x-show="open"
                             @click.away="open = false"
                             x-transition:enter="transition ease-out duration-100"
                             x-transition:enter-start="transform opacity-0 scale-95"
                             x-transition:enter-end="transform opacity-100 scale-100"
                             x-transition:leave="transition ease-in duration-75"
                             x-transition:leave-start="transform opacity-100 scale-100"
                             x-transition:leave-end="transform opacity-0 scale-95"
                             class="absolute left-0 mt-2 w-48 bg-white rounded-md shadow-lg z-50">
                            <div class="py-1">
                                <a href="{{ route('dashboard') }}"
                                   class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    👤 پروفایل
                                </a>

                                <form method="POST" action="{{ route('logout') }}" class="block">
                                    @csrf
                                    <button type="submit"
                                            class="w-full text-right px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        🚪 خروج
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                @else
                    <a href="{{ route('login') }}"
                       class="text-sm text-gray-700 hover:text-gray-900">ورود</a>
                    <a href="{{ route('register') }}"
                       class="bg-blue-600 text-white px-4 py-2 rounded-md text-sm hover:bg-blue-700">ثبت‌نام</a>
                @endauth
            </div>
        </div>
    </div>
</header>

<!-- محتوای اصلی -->
<main class="container mx-auto px-4 py-6">
    <!-- نمایش پیام‌های سیستم -->
    @if(session('success'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6 fade-in"
             x-data="{ show: true }"
             x-show="show"
             x-init="setTimeout(() => show = false, 5000)">
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <span class="emoji-icon mr-2">✅</span>
                    {{ session('success') }}
                </div>
                <button @click="show = false" class="text-green-700 hover:text-green-900">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>
    @endif

    @if(session('error'))
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6 fade-in"
             x-data="{ show: true }"
             x-show="show">
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <span class="emoji-icon mr-2">❌</span>
                    {{ session('error') }}
                </div>
                <button @click="show = false" class="text-red-700 hover:text-red-900">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>
    @endif

    @if(session('warning'))
        <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded mb-6 fade-in"
             x-data="{ show: true }"
             x-show="show">
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <span class="emoji-icon mr-2">⚠️</span>
                    {{ session('warning') }}
                </div>
                <button @click="show = false" class="text-yellow-700 hover:text-yellow-900">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>
    @endif

    @yield('content')
</main>

<!-- فوتر -->
<footer class="bg-white border-t border-gray-200 mt-12">
    <div class="container mx-auto px-4 py-6">
        <div class="flex flex-col md:flex-row justify-between items-center">
            <div class="text-sm text-gray-600 mb-4 md:mb-0">
                © {{ date('Y') }} API Data Scraper. تمامی حقوق محفوظ است.
            </div>

            <div class="flex items-center space-x-4 space-x-reverse text-sm text-gray-600">
                <span>نسخه 1.0.0</span>
                <span>•</span>
                <span>Laravel {{ app()->version() }}</span>
                <span>•</span>
                <span>PHP {{ PHP_VERSION }}</span>
            </div>
        </div>
    </div>
</footer>

<!-- Alpine.js -->
<script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>

@stack('scripts')
</body>
</html>
