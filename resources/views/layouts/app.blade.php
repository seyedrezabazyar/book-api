<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'مدیریت کانفیگ') - سیستم مدیریت</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- کانفیگ Tailwind برای RTL -->
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'vazir': ['Vazir', 'sans-serif'],
                    }
                }
            }
        }
    </script>

    <!-- فونت وزیر -->
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazir-font@v30.1.0/dist/font-face.css" rel="stylesheet" type="text/css" />

    <!-- استایل‌های اضافی -->
    <style>
        body {
            font-family: 'Vazir', sans-serif;
        }

        /* انیمیشن برای loading */
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* استایل برای پیج‌نیشن */
        .pagination svg {
            width: 1rem;
            height: 1rem;
        }

        /* حالت hover برای ردیف‌های جدول */
        .table-row:hover {
            background-color: #f9fafb;
            transition: background-color 0.2s ease;
        }

        /* استایل‌های سفارشی برای فرم‌ها */
        .form-input:focus {
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        /* اسکرول بار سفارشی */
        .custom-scrollbar::-webkit-scrollbar {
            width: 8px;
        }

        .custom-scrollbar::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 4px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
    </style>

    @stack('styles')
</head>
<body class="bg-gray-50 min-h-screen">

<!-- Header/Navigation -->
<header class="bg-white shadow-sm border-b border-gray-200">
    <div class="container mx-auto px-4">
        <div class="flex items-center justify-between h-16">
            <!-- لوگو و عنوان -->
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V7a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                </div>
                <div class="mr-4">
                    <h1 class="text-xl font-semibold text-gray-900">
                        <a href="{{ route('simple-configs.index') }}" class="hover:text-blue-600 transition-colors">
                            سیستم مدیریت کانفیگ
                        </a>
                    </h1>
                </div>
            </div>

            <!-- ناوبری -->
            <nav class="hidden md:flex items-center space-x-4 space-x-reverse">
                <a
                    href="{{ route('simple-configs.index') }}"
                    class="text-gray-700 hover:text-blue-600 px-3 py-2 rounded-md text-sm font-medium transition-colors
                               {{ request()->routeIs('simple-configs.index') ? 'text-blue-600 bg-blue-50' : '' }}"
                >
                    لیست کانفیگ‌ها
                </a>
                <a
                    href="{{ route('simple-configs.create') }}"
                    class="text-gray-700 hover:text-blue-600 px-3 py-2 rounded-md text-sm font-medium transition-colors
                               {{ request()->routeIs('simple-configs.create') ? 'text-blue-600 bg-blue-50' : '' }}"
                >
                    کانفیگ جدید
                </a>
            </nav>

            <!-- منوی موبایل -->
            <div class="md:hidden">
                <button
                    type="button"
                    class="text-gray-500 hover:text-gray-600 focus:outline-none focus:text-gray-600"
                    id="mobile-menu-button"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
                </button>
            </div>
        </div>

        <!-- منوی موبایل (مخفی) -->
        <div class="md:hidden hidden" id="mobile-menu">
            <div class="px-2 pt-2 pb-3 space-y-1 border-t border-gray-200">
                <a
                    href="{{ route('simple-configs.index') }}"
                    class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-blue-600 hover:bg-gray-50
                               {{ request()->routeIs('simple-configs.index') ? 'text-blue-600 bg-blue-50' : '' }}"
                >
                    لیست کانفیگ‌ها
                </a>
                <a
                    href="{{ route('simple-configs.create') }}"
                    class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-blue-600 hover:bg-gray-50
                               {{ request()->routeIs('simple-configs.create') ? 'text-blue-600 bg-blue-50' : '' }}"
                >
                    کانفیگ جدید
                </a>
            </div>
        </div>
    </div>
</header>

<!-- محتوای اصلی -->
<main class="fade-in">
    @yield('content')
</main>

<!-- Footer -->
<footer class="bg-white border-t border-gray-200 mt-12">
    <div class="container mx-auto px-4 py-6">
        <div class="text-center text-sm text-gray-500">
            <p>&copy; {{ date('Y') }} سیستم مدیریت کانفیگ. تمامی حقوق محفوظ است.</p>
            <div class="mt-2 flex justify-center items-center space-x-4 space-x-reverse text-xs">
                <span>نسخه 1.0.0</span>
                <span>•</span>
                <span>ساخته شده با ❤️ برای مدیریت بهتر</span>
            </div>
        </div>
    </div>
</footer>

<!-- اسکریپت‌های عمومی -->
<script>
    // تنظیم CSRF Token برای AJAX requests
    document.addEventListener('DOMContentLoaded', function() {
        const token = document.querySelector('meta[name="csrf-token"]');
        if (token) {
            window.Laravel = {
                csrfToken: token.getAttribute('content')
            };
        }
    });

    // منوی موبایل
    document.getElementById('mobile-menu-button').addEventListener('click', function() {
        const mobileMenu = document.getElementById('mobile-menu');
        mobileMenu.classList.toggle('hidden');
    });

    // بستن منوی موبایل وقتی روی لینک کلیک می‌شود
    document.querySelectorAll('#mobile-menu a').forEach(function(link) {
        link.addEventListener('click', function() {
            document.getElementById('mobile-menu').classList.add('hidden');
        });
    });

    // بستن منوی موبایل با کلیک در خارج از آن
    document.addEventListener('click', function(event) {
        const mobileMenu = document.getElementById('mobile-menu');
        const mobileMenuButton = document.getElementById('mobile-menu-button');

        if (!mobileMenu.contains(event.target) && !mobileMenuButton.contains(event.target)) {
            mobileMenu.classList.add('hidden');
        }
    });

    // تایید حذف
    window.confirmDelete = function(message = 'آیا از حذف این مورد اطمینان دارید؟') {
        return confirm(message);
    };

    // نمایش پیام‌های موقت
    window.showMessage = function(message, type = 'success', duration = 5000) {
        const messageDiv = document.createElement('div');
        messageDiv.className = `fixed top-4 left-1/2 transform -translate-x-1/2 z-50 px-6 py-3 rounded-md shadow-lg transition-all duration-300 ${
            type === 'success' ? 'bg-green-500 text-white' :
                type === 'error' ? 'bg-red-500 text-white' :
                    type === 'warning' ? 'bg-yellow-500 text-white' :
                        'bg-blue-500 text-white'
        }`;
        messageDiv.textContent = message;

        document.body.appendChild(messageDiv);

        // انیمیشن ورود
        setTimeout(() => {
            messageDiv.style.opacity = '1';
            messageDiv.style.transform = 'translate(-50%, 0)';
        }, 100);

        // حذف خودکار پس از مدت زمان مشخص
        setTimeout(() => {
            messageDiv.style.opacity = '0';
            messageDiv.style.transform = 'translate(-50%, -20px)';
            setTimeout(() => {
                if (messageDiv.parentNode) {
                    messageDiv.parentNode.removeChild(messageDiv);
                }
            }, 300);
        }, duration);
    };

    // بررسی اتصال به اینترنت
    window.addEventListener('online', function() {
        showMessage('اتصال به اینترنت برقرار شد', 'success', 3000);
    });

    window.addEventListener('offline', function() {
        showMessage('اتصال به اینترنت قطع شد', 'error', 5000);
    });

    // کپی کردن متن به کلیپ‌بورد
    window.copyToClipboard = function(text) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function() {
                showMessage('متن کپی شد', 'success', 2000);
            }).catch(function() {
                showMessage('خطا در کپی کردن', 'error', 2000);
            });
        } else {
            // روش قدیمی برای مرورگرهای قدیمی
            const textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.position = 'fixed';
            textArea.style.left = '-999999px';
            textArea.style.top = '-999999px';
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();

            try {
                document.execCommand('copy');
                showMessage('متن کپی شد', 'success', 2000);
            } catch (err) {
                showMessage('خطا در کپی کردن', 'error', 2000);
            }

            document.body.removeChild(textArea);
        }
    };

    // تبدیل تاریخ میلادی به شمسی (ساده)
    window.formatPersianDate = function(date) {
        const options = {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            calendar: 'persian',
            numberingSystem: 'latn'
        };
        return new Intl.DateTimeFormat('fa-IR', options).format(new Date(date));
    };

    // اعتبارسنجی URL
    window.isValidUrl = function(string) {
        try {
            new URL(string);
            return true;
        } catch (_) {
            return false;
        }
    };

    // فرمت کردن اعداد فارسی
    window.formatPersianNumber = function(number) {
        return new Intl.NumberFormat('fa-IR').format(number);
    };

    // تنظیم theme mode (در صورت نیاز در آینده)
    window.setTheme = function(theme) {
        localStorage.setItem('theme', theme);
        if (theme === 'dark') {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    };

    // بارگذاری theme ذخیره شده
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme) {
        setTheme(savedTheme);
    }
</script>

@stack('scripts')
</body>
</html>
