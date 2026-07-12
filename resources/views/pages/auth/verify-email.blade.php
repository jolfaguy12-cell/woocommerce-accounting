@extends('layouts.auth')

@section('auth-title', 'تأیید ایمیل')
@section('auth-description', 'لطفاً با کلیک روی پیوندی که برایتان ایمیل شد، آدرس ایمیل خود را تأیید کنید.')

@section('auth-form')
    <div class="space-y-5">
        @if (session('status') === 'verification-link-sent')
            <div class="rounded-lg border border-success-500/30 bg-success-50 px-4 py-2.5 text-sm text-success-600 dark:bg-success-500/10 dark:text-success-500">
                پیوند تأیید تازه‌ای به ایمیل شما ارسال شد.
            </div>
        @endif

        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit"
                class="flex w-full items-center justify-center rounded-lg bg-brand-500 px-4 py-3 text-sm font-medium text-white shadow-theme-xs transition hover:bg-brand-600">
                ارسال مجدد پیوند تأیید
            </button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="w-full text-center text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300">
                خروج از حساب
            </button>
        </form>
    </div>
@endsection
