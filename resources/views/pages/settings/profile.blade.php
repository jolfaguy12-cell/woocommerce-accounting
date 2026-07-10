@extends('layouts.app')

@section('content')
<x-common.page-breadcrumb pageTitle="پروفایل" />

<div class="max-w-xl space-y-4">
    @if (session('success'))
        <x-ui.alert variant="success" :message="session('success')" />
    @endif

    <x-common.component-card title="اطلاعات پروفایل">
        <form method="POST" action="{{ route('profile.update') }}">
            @csrf
            @method('patch')

            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">نام</label>
            <input type="text" name="name" required value="{{ old('name', $errors->any() ? old('name') : auth()->user()->name) }}"
                class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            @error('name')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror

            <label class="mb-1.5 mt-4 block text-sm font-medium text-gray-700 dark:text-gray-400">ایمیل</label>
            <input type="email" name="email" required dir="ltr" value="{{ old('email', $errors->any() ? old('email') : auth()->user()->email) }}"
                class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            @error('email')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror

            @if ($mustVerifyEmail && ! auth()->user()->hasVerifiedEmail())
                <p class="mt-1 text-xs text-warning-600">ایمیل شما تأیید نشده است.</p>
            @endif

            <label class="mb-1.5 mt-4 block text-sm font-medium text-gray-700 dark:text-gray-400">آیدی تلگرام</label>
            <input type="text" name="telegram_id" dir="ltr" value="{{ old('telegram_id', $errors->any() ? old('telegram_id') : auth()->user()->telegram_id) }}"
                placeholder="مثلاً 123456789@"
                class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            <p class="mt-1.5 text-xs text-gray-400">برای دریافت هشدارهای سیستم در تلگرام. آیدی عددی خود را می‌توانید از ربات @userinfobot در تلگرام دریافت کنید.</p>
            @error('telegram_id')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror

            <div class="mt-5">
                <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">ذخیره</button>
            </div>
        </form>
    </x-common.component-card>
</div>
@endsection
