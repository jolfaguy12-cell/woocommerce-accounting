@extends('layouts.auth')

@section('auth-title', 'فراموشی رمز عبور')
@section('auth-description', 'ایمیل خود را وارد کنید تا پیوند بازنشانی رمز برایتان ارسال شود')

@section('auth-form')
    <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
        @csrf

        <div>
            <label for="email" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                ایمیل<span class="text-error-500">*</span>
            </label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="off"
                dir="ltr" placeholder="email@example.com"
                class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-left text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30">
            @error('email')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
        </div>

        <button type="submit"
            class="flex w-full items-center justify-center rounded-lg bg-brand-500 px-4 py-3 text-sm font-medium text-white shadow-theme-xs transition hover:bg-brand-600">
            ارسال پیوند بازنشانی رمز
        </button>

        <p class="text-center text-sm text-gray-500 dark:text-gray-400">
            بازگشت به
            <a href="{{ route('login') }}" class="text-brand-500 hover:text-brand-600 dark:text-brand-400">صفحه ورود</a>
        </p>
    </form>
@endsection
