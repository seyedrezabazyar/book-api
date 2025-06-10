<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') - سیستم کرال هوشمند</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'vazir': ['Vazir', 'Arial', 'sans-serif']
                    }
                }
            }
        }
    </script>

    <!-- Vazir Font -->
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazir-font@v30.1.0/dist/font-face.css" rel="stylesheet" type="text/css" />

    <style>
        body { font-family: 'Vazir', Arial, sans-serif; }
        .rtl { direction: rtl; }
    </style>
</head>
<body class="bg-gray-100 rtl">
<!-- Header -->
<nav class="bg-white shadow-sm border-b">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex items-center">
                <a href="{{ route('configs.index') }}" class="flex items-center">
                    <div class="text-xl font-bold text-gray-900">🧠 سیستم کرال هوشمند</div>
                </a>
            </div>

            <div class="flex items-center space-x-4 space-x-reverse">
                <a href="{{ route('configs.index') }}"
                   class="text-gray-700 hover:text-gray-900 px-3 py-2 rounded text-sm font-medium
                              {{ request()->routeIs('configs.*') ? 'bg-gray-100' : '' }}">
                    📊 کانفیگ‌ها
                </a>

                <!-- User Menu -->
                @auth
                    <div class="flex items-center space-x-2 space-x-reverse">
                        <span class="text-sm text-gray-700">{{ Auth::user()->name }}</span>
                        <form method="POST" action="{{ route('logout') }}" class="inline">
                            @csrf
                            <button type="submit" class="text-gray-500 hover:text-gray-700 text-sm">
                                خروج
                            </button>
                        </form>
                    </div>
                @endauth
            </div>
        </div>
    </div>
</nav>

<!-- Main Content -->
<main class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
    <!-- Flash Messages -->
    @if (session('success'))
        <div class="mb-6 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded" role="alert">
            <span class="block sm:inline">{{ session('success') }}</span>
        </div>
    @endif

    @if (session('error'))
        <div class="mb-6 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded" role="alert">
            <span class="block sm:inline">{{ session('error') }}</span>
        </div>
    @endif

    @if (session('warning'))
        <div class="mb-6 bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded" role="alert">
            <span class="block sm:inline">{{ session('warning') }}</span>
        </div>
    @endif

    @yield('content')
</main>

<!-- Footer -->
<footer class="bg-white border-t mt-12">
    <div class="max-w-7xl mx-auto py-4 px-4 sm:px-6 lg:px-8">
        <div class="text-center text-sm text-gray-500">
            سیستم کرال هوشمند © {{ date('Y') }} - با قابلیت تشخیص خودکار و مدیریت تکراری‌ها
        </div>
    </div>
</footer>

<!-- Scripts -->
<script>
    // حذف خودکار پیام‌های flash بعد از 5 ثانیه
    setTimeout(function() {
        const alerts = document.querySelectorAll('[role="alert"]');
        alerts.forEach(function(alert) {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(function() {
                alert.remove();
            }, 500);
        });
    }, 5000);

    // تابع کمکی برای نمایش پیام‌ها
    window.showMessage = function(message, type = 'info') {
        const colorClass = {
            'success': 'bg-green-100 border-green-400 text-green-700',
            'error': 'bg-red-100 border-red-400 text-red-700',
            'warning': 'bg-yellow-100 border-yellow-400 text-yellow-700',
            'info': 'bg-blue-100 border-blue-400 text-blue-700'
        };

        const alertDiv = document.createElement('div');
        alertDiv.className = `mb-6 ${colorClass[type]} px-4 py-3 rounded`;
        alertDiv.setAttribute('role', 'alert');
        alertDiv.innerHTML = `<span class="block sm:inline">${message}</span>`;

        const main = document.querySelector('main');
        const firstChild = main.firstElementChild;
        main.insertBefore(alertDiv, firstChild);

        setTimeout(() => {
            alertDiv.style.transition = 'opacity 0.5s';
            alertDiv.style.opacity = '0';
            setTimeout(() => alertDiv.remove(), 500);
        }, 5000);
    };
</script>

@stack('scripts')
</body>
</html>
