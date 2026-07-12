@extends('layouts.auth')

@section('auth-title', 'بازنشانی رمز عبور')
@section('auth-description', 'رمز عبور جدید خود را وارد کنید')

@php
    $inputClass = 'h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-left text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30';
@endphp

@section('auth-form')
    <form method="POST" action="{{ route('password.store') }}" class="space-y-5">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div>
            <label for="email" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">ایمیل</label>
            <input type="email" id="email" name="email" value="{{ old('email', $email) }}" readonly autocomplete="email"
                dir="ltr" class="{{ $inputClass }}">
            @error('email')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="password" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                رمز عبور جدید<span class="text-error-500">*</span>
            </label>
            <input type="password" id="password" name="password" required autofocus autocomplete="new-password"
                dir="ltr" placeholder="********" class="{{ $inputClass }}">
            @error('password')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="password_confirmation" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                تکرار رمز عبور<span class="text-error-500">*</span>
            </label>
            <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password"
                dir="ltr" placeholder="********" class="{{ $inputClass }}">
            @error('password_confirmation')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
        </div>

        <button type="submit"
            class="flex w-full items-center justify-center rounded-lg bg-brand-500 px-4 py-3 text-sm font-medium text-white shadow-theme-xs transition hover:bg-brand-600">
            بازنشانی رمز عبور
        </button>
    </form>
@endsection
