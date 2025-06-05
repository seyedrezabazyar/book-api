<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'مدیریت اسکرپر')</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        body { font-family: 'Vazir', 'Tahoma', sans-serif; }
    </style>
</head>
<body class="bg-gray-50">
<!-- نوار ناوبری -->
<nav class="bg-white shadow border-b border-gray-200">
    <div class="container mx-auto px-4">
        <div class="flex justify-between items-center h-16">
            <!-- لوگو -->
            <div class="flex items-center">
                <a href="{{ route('configs.index') }}" class="text-xl font-bold text-gray-800">
                    🤖 اسکرپر مدیریت
                </a>
            </div>

            <!-- منوی اصلی -->
            <div class="flex items-center space-x-4 space-x-reverse">
                <a href="{{ route('configs.index') }}"
                   class="text-gray-700 hover:text-blue-600 px-3 py-2 rounded-md text-sm font-medium {{ request()->routeIs('configs.*') ? 'bg-blue-100 text-blue-700' : '' }}">
                    📋 کانفیگ‌ها
                </a>

                @auth
                    <div class="relative group">
                        <button class="flex items-center text-gray-700 hover:text-blue-600 px-3 py-2 rounded-md text-sm font-medium">
                            👤 {{ Auth::user()->name }}
                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>

                        <!-- منوی کشویی -->
                        <div class="absolute left-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50 hidden group-hover:block">
                            <a href="{{ route('profile.edit') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                ⚙️ تنظیمات
                            </a>
                            <form method="POST" action="{{ route('logout') }}" class="block">
                                @csrf
                                <button type="submit" class="w-full text-right px-4 py-2 text-sm text-red-600 hover:bg-gray-100">
                                    🚪 خروج
                                </button>
                            </form>
                        </div>
                    </div>
                @else
                    <a href="{{ route('login') }}" class="text-gray-700 hover:text-blue-600 px-3 py-2 rounded-md text-sm font-medium">
                        🔑 ورود
                    </a>
                @endauth
            </div>
        </div>
    </div>
</nav>

<!-- محتوای اصلی -->
<main>
    @yield('content')
</main>

<!-- فوتر -->
<footer class="bg-white border-t border-gray-200 mt-12">
    <div class="container mx-auto px-4 py-6">
        <div class="text-center text-gray-600 text-sm">
            © {{ date('Y') }} سیستم مدیریت اسکرپر - ساخته شده با ❤️
        </div>
    </div>
</footer>

@stack('scripts')
</body>
</html>
