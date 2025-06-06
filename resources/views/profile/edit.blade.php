@extends('layouts.app')

@section('title', 'ویرایش پروفایل')

@section('content')
    <div class="container mx-auto px-4 py-6">
        <div class="max-w-2xl mx-auto">
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-gray-800">ویرایش پروفایل</h1>
                <p class="text-gray-600">اطلاعات حساب کاربری خود را مدیریت کنید</p>
            </div>

            @if(session('status') === 'profile-updated')
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                    پروفایل با موفقیت به‌روزرسانی شد!
                </div>
            @endif

            @if(session('status') === 'password-updated')
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                    رمز عبور با موفقیت تغییر یافت!
                </div>
            @endif

            <!-- اطلاعات پروفایل -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">اطلاعات پروفایل</h2>

                    <form method="POST" action="{{ route('profile.update') }}">
                        @csrf
                        @method('PATCH')

                        <div class="space-y-4">
                            <div>
                                <label for="name" class="block text-sm font-medium text-gray-700 mb-2">نام</label>
                                <input
                                    type="text"
                                    id="name"
                                    name="name"
                                    value="{{ old('name', $user->name) }}"
                                    required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 @error('name') border-red-500 @enderror"
                                >
                                @error('name')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700 mb-2">ایمیل</label>
                                <input
                                    type="email"
                                    id="email"
                                    name="email"
                                    value="{{ old('email', $user->email) }}"
                                    required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 @error('email') border-red-500 @enderror"
                                >
                                @error('email')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror

                                @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                                    <div class="mt-2">
                                        <p class="text-sm text-gray-800">
                                            آدرس ایمیل شما تأیید نشده است.
                                            <button form="send-verification" class="underline text-sm text-gray-600 hover:text-gray-900">
                                                برای ارسال مجدد ایمیل تأیید کلیک کنید.
                                            </button>
                                        </p>

                                        @if (session('status') === 'verification-link-sent')
                                            <p class="mt-2 font-medium text-sm text-green-600">
                                                لینک تأیید جدید به آدرس ایمیل شما ارسال شد.
                                            </p>
                                        @endif
                                    </div>
                                @endif
                            </div>

                            <div class="flex items-center gap-4 pt-4">
                                <button
                                    type="submit"
                                    class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                >
                                    ذخیره تغییرات
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- تغییر رمز عبور -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">تغییر رمز عبور</h2>

                    <form method="POST" action="{{ route('password.update') }}">
                        @csrf
                        @method('PUT')

                        <div class="space-y-4">
                            <div>
                                <label for="current_password" class="block text-sm font-medium text-gray-700 mb-2">رمز عبور فعلی</label>
                                <input
                                    type="password"
                                    id="current_password"
                                    name="current_password"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 @error('current_password', 'updatePassword') border-red-500 @enderror"
                                >
                                @error('current_password', 'updatePassword')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="password" class="block text-sm font-medium text-gray-700 mb-2">رمز عبور جدید</label>
                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 @error('password', 'updatePassword') border-red-500 @enderror"
                                >
                                @error('password', 'updatePassword')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-2">تکرار رمز عبور جدید</label>
                                <input
                                    type="password"
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 @error('password_confirmation', 'updatePassword') border-red-500 @enderror"
                                >
                                @error('password_confirmation', 'updatePassword')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="flex items-center gap-4 pt-4">
                                <button
                                    type="submit"
                                    class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500"
                                >
                                    تغییر رمز عبور
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- حذف حساب -->
            <div class="bg-white rounded-lg shadow border border-red-200">
                <div class="p-6">
                    <h2 class="text-lg font-medium text-red-900 mb-4">حذف حساب کاربری</h2>
                    <p class="text-sm text-red-700 mb-4">
                        هشدار: حذف حساب کاربری غیرقابل بازگشت است و تمام اطلاعات شما پاک خواهد شد.
                    </p>

                    <button
                        type="button"
                        onclick="confirmDelete()"
                        class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500"
                    >
                        حذف حساب کاربری
                    </button>

                    <!-- فرم حذف مخفی -->
                    <form id="delete-form" method="POST" action="{{ route('profile.destroy') }}" class="hidden">
                        @csrf
                        @method('DELETE')

                        <div class="mt-4">
                            <label for="delete_password" class="block text-sm font-medium text-gray-700 mb-2">رمز عبور برای تأیید</label>
                            <input
                                type="password"
                                id="delete_password"
                                name="password"
                                required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500"
                            >
                            @error('password', 'userDeletion')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="mt-4 flex gap-3">
                            <button
                                type="submit"
                                class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700"
                            >
                                تأیید حذف
                            </button>
                            <button
                                type="button"
                                onclick="cancelDelete()"
                                class="px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700"
                            >
                                انصراف
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
        <form id="send-verification" method="POST" action="{{ route('verification.send') }}">
            @csrf
        </form>
    @endif

    <script>
        function confirmDelete() {
            if (confirm('آیا مطمئن هستید که می‌خواهید حساب کاربری خود را حذف کنید؟')) {
                document.getElementById('delete-form').classList.remove('hidden');
            }
        }

        function cancelDelete() {
            document.getElementById('delete-form').classList.add('hidden');
            document.getElementById('delete_password').value = '';
        }
    </script>
@endsection
