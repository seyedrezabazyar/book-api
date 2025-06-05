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

        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        ::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        /* Smooth transitions */
        .transition-all {
            transition: all 0.3s ease;
        }

        /* Loading spinner */
        .spinner {
            border: 2px solid #f3f3f3;
            border-top: 2px solid #3498db;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Toast notifications */
        .toast {
            animation: slideInRight 0.3s ease-out;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        /* Custom badge */
        .badge {
            position: relative;
        }

        .badge::after {
            content: attr(data-count);
            position: absolute;
            top: -8px;
            right: -8px;
            background: #ef4444;
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 10px;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 18px;
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
<!-- نوار ناوبری -->
<nav class="bg-white shadow-sm border-b border-gray-200 sticky top-0 z-50">
    <div class="container mx-auto px-4">
        <div class="flex justify-between items-center h-16">
            <!-- لوگو و برند -->
            <div class="flex items-center">
                <a href="{{ route('configs.index') }}" class="flex items-center text-xl font-bold text-gray-800 hover:text-blue-600 transition-colors">
                    <span class="text-2xl ml-2">🤖</span>
                    <span>اسکرپر مدیریت</span>
                </a>
            </div>

            <!-- منوی اصلی -->
            <div class="hidden md:flex items-center space-x-6 space-x-reverse">
                <a href="{{ route('configs.index') }}"
                   class="flex items-center text-gray-700 hover:text-blue-600 px-3 py-2 rounded-md text-sm font-medium transition-colors {{ request()->routeIs('configs.index') ? 'bg-blue-100 text-blue-700' : '' }}">
                    <span class="ml-2">📋</span>
                    کانفیگ‌ها
                </a>

                @if(request()->routeIs('configs.*'))
                    <a href="{{ route('configs.create') }}"
                       class="flex items-center text-gray-700 hover:text-green-600 px-3 py-2 rounded-md text-sm font-medium transition-colors {{ request()->routeIs('configs.create') ? 'bg-green-100 text-green-700' : '' }}">
                        <span class="ml-2">➕</span>
                        کانفیگ جدید
                    </a>

                    <!-- نمایش آمار سریع -->
                    @php
                        $runningConfigs = \App\Models\Config::where('is_running', true)->count();
                        $activeConfigs = \App\Models\Config::where('status', 'active')->count();
                    @endphp

                    @if($runningConfigs > 0)
                        <div class="flex items-center text-yellow-600 px-3 py-2 text-sm">
                            <div class="spinner ml-2"></div>
                            {{ $runningConfigs }} در حال اجرا
                        </div>
                    @endif
                @endif
            </div>

            <!-- منوی کاربر -->
            <div class="flex items-center space-x-4 space-x-reverse">
                @auth
                    <!-- اعلان‌ها -->
                    @php
                        $totalFailures = \App\Models\ScrapingFailure::where('is_resolved', false)->count();
                    @endphp

                    @if($totalFailures > 0)
                        <a href="{{ route('configs.index') }}?filter=failures"
                           class="relative text-orange-600 hover:text-orange-800 transition-colors"
                           title="خطاهای حل نشده">
                            <span class="text-lg">🚨</span>
                            <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full h-4 w-4 flex items-center justify-center text-xs font-bold">
                                    {{ $totalFailures > 99 ? '99+' : $totalFailures }}
                                </span>
                        </a>
                    @endif

                    <!-- منوی کاربر -->
                    <div class="relative group">
                        <button class="flex items-center text-gray-700 hover:text-blue-600 px-3 py-2 rounded-md text-sm font-medium transition-colors">
                            <span class="ml-2">👤</span>
                            {{ Auth::user()->name }}
                            <svg class="w-4 h-4 mr-1 transition-transform group-hover:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>

                        <!-- منوی کشویی -->
                        <div class="absolute left-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200">
                            <div class="px-4 py-2 text-sm text-gray-700 border-b border-gray-100">
                                <div class="font-medium">{{ Auth::user()->name }}</div>
                                <div class="text-xs text-gray-500">{{ Auth::user()->email }}</div>
                            </div>

                            <a href="{{ route('profile.edit') }}"
                               class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors">
                                <span class="ml-2">⚙️</span>
                                تنظیمات پروفایل
                            </a>

                            <div class="border-t border-gray-100"></div>

                            <form method="POST" action="{{ route('logout') }}" class="block">
                                @csrf
                                <button type="submit" class="flex items-center w-full text-right px-4 py-2 text-sm text-red-600 hover:bg-red-50 transition-colors">
                                    <span class="ml-2">🚪</span>
                                    خروج از سیستم
                                </button>
                            </form>
                        </div>
                    </div>
                @else
                    <a href="{{ route('login') }}"
                       class="flex items-center text-gray-700 hover:text-blue-600 px-3 py-2 rounded-md text-sm font-medium transition-colors">
                        <span class="ml-2">🔑</span>
                        ورود
                    </a>

                    @if(Route::has('register'))
                        <a href="{{ route('register') }}"
                           class="flex items-center bg-blue-600 text-white hover:bg-blue-700 px-4 py-2 rounded-md text-sm font-medium transition-colors">
                            <span class="ml-2">✨</span>
                            ثبت نام
                        </a>
                    @endif
                @endauth

                <!-- منوی موبایل -->
                <div class="md:hidden">
                    <button id="mobile-menu-btn" class="text-gray-700 hover:text-blue-600 p-2">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- منوی موبایل -->
        <div id="mobile-menu" class="md:hidden hidden border-t border-gray-200 py-2">
            <div class="space-y-1">
                <a href="{{ route('configs.index') }}"
                   class="flex items-center text-gray-700 hover:text-blue-600 hover:bg-gray-50 px-3 py-2 rounded-md text-sm font-medium {{ request()->routeIs('configs.index') ? 'bg-blue-100 text-blue-700' : '' }}">
                    <span class="ml-2">📋</span>
                    کانفیگ‌ها
                </a>

                <a href="{{ route('configs.create') }}"
                   class="flex items-center text-gray-700 hover:text-green-600 hover:bg-gray-50 px-3 py-2 rounded-md text-sm font-medium {{ request()->routeIs('configs.create') ? 'bg-green-100 text-green-700' : '' }}">
                    <span class="ml-2">➕</span>
                    کانفیگ جدید
                </a>
            </div>
        </div>
    </div>
</nav>

<!-- محتوای اصلی -->
<main class="min-h-screen">
    @yield('content')
</main>

<!-- فوتر -->
<footer class="bg-white border-t border-gray-200 mt-12">
    <div class="container mx-auto px-4 py-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- اطلاعات سیستم -->
            <div>
                <h3 class="text-sm font-medium text-gray-900 mb-3">سیستم مدیریت اسکرپر</h3>
                <div class="text-xs text-gray-600 space-y-1">
                    <div>نسخه: 1.0.0</div>
                    <div>Laravel: {{ app()->version() }}</div>
                    <div>PHP: {{ PHP_VERSION }}</div>
                </div>
            </div>

            <!-- آمار سریع -->
            @auth
                @php
                    $totalConfigs = \App\Models\Config::count();
                    $activeConfigs = \App\Models\Config::where('status', 'active')->count();
                    $runningConfigs = \App\Models\Config::where('is_running', true)->count();
                @endphp
                <div>
                    <h3 class="text-sm font-medium text-gray-900 mb-3">آمار سیستم</h3>
                    <div class="text-xs text-gray-600 space-y-1">
                        <div>کل کانفیگ‌ها: {{ number_format($totalConfigs) }}</div>
                        <div>فعال: {{ number_format($activeConfigs) }}</div>
                        <div>در حال اجرا: {{ number_format($runningConfigs) }}</div>
                    </div>
                </div>
            @endauth

            <!-- کپی‌رایت -->
            <div class="text-center md:text-left">
                <div class="text-sm text-gray-600">
                    © {{ date('Y') }} سیستم مدیریت اسکرپر
                </div>
                <div class="text-xs text-gray-500 mt-1">
                    ساخته شده با ❤️ توسط تیم توسعه
                </div>
            </div>
        </div>
    </div>
</footer>

<!-- Toast Container -->
<div id="toast-container" class="fixed top-4 left-4 z-50 space-y-2"></div>

<!-- Scripts -->
<script>
    // Mobile menu toggle
    document.getElementById('mobile-menu-btn').addEventListener('click', function() {
        const menu = document.getElementById('mobile-menu');
        menu.classList.toggle('hidden');
    });

    // Auto-hide alerts after 5 seconds
    document.addEventListener('DOMContentLoaded', function() {
        const alerts = document.querySelectorAll('.bg-green-100, .bg-red-100, .bg-yellow-100, .bg-blue-100');
        alerts.forEach(alert => {
            if (!alert.querySelector('button')) { // Only auto-hide if no close button
                setTimeout(() => {
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-10px)';
                    setTimeout(() => alert.remove(), 300);
                }, 5000);
            }
        });
    });

    // Toast notification function
    function showToast(message, type = 'info') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');

        const colors = {
            success: 'bg-green-500',
            error: 'bg-red-500',
            warning: 'bg-yellow-500',
            info: 'bg-blue-500'
        };

        toast.className = `toast ${colors[type]} text-white px-4 py-3 rounded-lg shadow-lg max-w-sm`;
        toast.innerHTML = `
                <div class="flex items-center">
                    <span class="flex-1">${message}</span>
                    <button onclick="this.parentElement.parentElement.remove()" class="mr-2 text-white hover:text-gray-200">
                        ✕
                    </button>
                </div>
            `;

        container.appendChild(toast);

        // Auto remove after 5 seconds
        setTimeout(() => {
            if (toast.parentElement) {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(100%)';
                setTimeout(() => toast.remove(), 300);
            }
        }, 5000);
    }

    // Global error handler for AJAX requests
    window.addEventListener('unhandledrejection', function(event) {
        console.error('Unhandled promise rejection:', event.reason);
        showToast('خطایی در سیستم رخ داده است', 'error');
    });

    // Add loading state to forms
    document.addEventListener('DOMContentLoaded', function() {
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', function() {
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn && !submitBtn.disabled) {
                    const originalText = submitBtn.innerHTML;
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<div class="spinner inline-block ml-2"></div> در حال پردازش...';

                    // Re-enable after 10 seconds as fallback
                    setTimeout(() => {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalText;
                    }, 10000);
                }
            });
        });
    });

    // Real-time updates for running configs
    @if(auth()->check())
    // Check for updates every 30 seconds
    setInterval(function() {
        if (window.location.pathname.includes('/configs')) {
            fetch('/configs/status-check', {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                }
            })
                .then(response => response.json())
                .then(data => {
                    if (data.should_refresh) {
                        // Only refresh if there are significant changes
                        if (data.running_configs !== {{ $runningConfigs ?? 0 }}) {
                            location.reload();
                        }
                    }
                })
                .catch(error => console.log('Status check failed:', error));
        }
    }, 30000);
    @endif

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl+N or Cmd+N for new config
        if ((e.ctrlKey || e.metaKey) && e.key === 'n' && e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA') {
            e.preventDefault();
            window.location.href = '{{ route("configs.create") }}';
        }

        // Escape to close modals/menus
        if (e.key === 'Escape') {
            document.getElementById('mobile-menu').classList.add('hidden');
        }
    });

    // Smooth scroll for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
</script>

@stack('scripts')
</body>
</html>
