<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'API Scraper')</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Vazirmatn', sans-serif;
        }

        .notification {
            animation: slideIn 0.3s ease-out;
            transition: opacity 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>

<body class="bg-gray-50">
    <!-- Header -->
    <header class="bg-white shadow-sm border-b">
        <div class="max-w-7xl mx-auto px-4 py-4">
            <div class="flex justify-between items-center">
                <a href="{{ route('configs.index') }}" class="flex items-center gap-3">
                    <span class="text-2xl">🤖</span>
                    <div>
                        <span class="text-xl font-semibold">API Scraper</span>
                        <div class="text-xs text-gray-500">مدیریت هوشمند دریافت داده</div>
                    </div>
                </a>

                <!-- Navigation Menu -->
                <nav class="hidden md:flex items-center gap-6">
                    <a href="{{ route('configs.index') }}"
                        class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:text-blue-600 transition-colors {{ request()->routeIs('configs.*') ? 'text-blue-600 border-b-2 border-blue-600' : '' }}">
                        <span>⚙️</span>
                        <span>کانفیگ‌ها</span>
                    </a>
                </nav>

                @auth
                    <div class="flex items-center gap-4">
                        <!-- User Menu Dropdown -->
                        <div class="relative" x-data="{ open: false }">
                            <button @click="open = !open"
                                class="flex items-center gap-2 text-sm text-gray-700 hover:text-gray-900 focus:outline-none">
                                <div class="w-8 h-8 bg-gray-300 rounded-full flex items-center justify-center">
                                    <span class="text-sm font-medium text-gray-700">
                                        {{ substr(auth()->user()->name, 0, 1) }}
                                    </span>
                                </div>
                                <span class="hidden md:block">{{ auth()->user()->name }}</span>
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </button>

                            <div x-show="open" @click.away="open = false"
                                x-transition:enter="transition ease-out duration-100"
                                x-transition:enter-start="transform opacity-0 scale-95"
                                x-transition:enter-end="transform opacity-100 scale-100"
                                x-transition:leave="transition ease-in duration-75"
                                x-transition:leave-start="transform opacity-100 scale-100"
                                x-transition:leave-end="transform opacity-0 scale-95"
                                class="absolute left-0 mt-2 w-48 bg-white rounded-md shadow-lg z-50 border">
                                <div class="py-1">
                                    <a href="{{ route('profile.edit') }}"
                                        class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        👤 پروفایل
                                    </a>

                                    <div class="border-t border-gray-100"></div>

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
                    </div>
                @else
                    <div class="flex items-center gap-4">
                        <a href="{{ route('login') }}" class="text-sm text-gray-700 hover:text-gray-900">ورود</a>
                        <a href="{{ route('register') }}"
                            class="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700">ثبت‌نام</a>
                    </div>
                @endauth
            </div>
        </div>
    </header>

    <!-- Messages -->
    @if (session('success'))
        <div class="max-w-7xl mx-auto px-4 py-2">
            <div class="bg-green-100 text-green-800 p-3 rounded notification">
                <div class="flex items-center justify-between">
                    <span>✅ {{ session('success') }}</span>
                    <button onclick="this.parentElement.parentElement.style.display='none'"
                        class="text-green-600">✕</button>
                </div>
            </div>
        </div>
    @endif

    @if (session('error'))
        <div class="max-w-7xl mx-auto px-4 py-2">
            <div class="bg-red-100 text-red-800 p-3 rounded notification">
                <div class="flex items-center justify-between">
                    <span>❌ {{ session('error') }}</span>
                    <button onclick="this.parentElement.parentElement.style.display='none'"
                        class="text-red-600">✕</button>
                </div>
            </div>
        </div>
    @endif

    @if (session('warning'))
        <div class="max-w-7xl mx-auto px-4 py-2">
            <div class="bg-yellow-100 text-yellow-800 p-3 rounded notification">
                <div class="flex items-center justify-between">
                    <span>⚠️ {{ session('warning') }}</span>
                    <button onclick="this.parentElement.parentElement.style.display='none'"
                        class="text-yellow-600">✕</button>
                </div>
            </div>
        </div>
    @endif

    <!-- Notifications Container -->
    <div id="notifications" class="max-w-7xl mx-auto px-4"></div>

    <!-- Content -->
    <main class="max-w-7xl mx-auto px-4 py-6">
        @yield('content')
    </main>

    <!-- Alpine.js for dropdown functionality -->
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
</body>

</html>
