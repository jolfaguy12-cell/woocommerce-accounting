@extends('layouts.app')

@php
    $inputClass = 'h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-left text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90';
    $labelClass = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400';
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="تغییر رمز عبور" parentLabel="پروفایل" :parentUrl="route('profile.edit')" />

<div class="max-w-xl space-y-4">
    @if (session('status') === 'password-updated')
        <x-ui.alert variant="success" title="انجام شد" message="رمز عبور شما به‌روزرسانی شد." />
    @endif

    <x-common.component-card title="تغییر رمز عبور" desc="برای امنیت بیشتر، رمز عبوری طولانی و یکتا انتخاب کنید.">
        <form method="POST" action="{{ route('password.update') }}">
            @csrf
            @method('PUT')

            <div>
                <label class="{{ $labelClass }}">رمز عبور فعلی</label>
                <input type="password" name="current_password" required dir="ltr" autocomplete="current-password" class="{{ $inputClass }}">
                @error('current_password')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
            </div>

            <div class="mt-4">
                <label class="{{ $labelClass }}">رمز عبور جدید</label>
                <input type="password" name="password" required dir="ltr" autocomplete="new-password" class="{{ $inputClass }}">
                @error('password')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
            </div>

            <div class="mt-4">
                <label class="{{ $labelClass }}">تکرار رمز عبور جدید</label>
                <input type="password" name="password_confirmation" required dir="ltr" autocomplete="new-password" class="{{ $inputClass }}">
                @error('password_confirmation')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
            </div>

            <div class="mt-5">
                <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">
                    ذخیره رمز جدید
                </button>
            </div>
        </form>
    </x-common.component-card>
</div>
@endsection
