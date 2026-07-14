@extends('layouts.app')

@php
    $roleLabels = [
        'admin' => 'مدیر',
        'accountant' => 'حسابدار',
        'warehouse' => 'انباردار',
        'partner_viewer' => 'شریک (فقط گزارش)',
    ];
    $inputClass = 'h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90';
    $labelClass = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400';
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="مدیریت کاربران" />

<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            ساخت و مدیریت حساب‌ها فقط توسط مدیر انجام می‌شود؛ ثبت‌نام عمومی غیرفعال است.
        </p>
        <button type="button" x-data @click="$dispatch('open-create-user')"
            class="inline-flex h-10 items-center gap-2 rounded-lg bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600">
            + کاربر جدید
        </button>
    </div>

    @if (session('success'))
        <x-ui.alert variant="success" title="انجام شد" :message="session('success')" />
    @endif

    {{-- Guard-rail errors from the controller (last-admin protection, self-delete) --}}
    @error('user')<x-ui.alert variant="error" title="خطا" :message="$message" />@enderror
    @error('role')<x-ui.alert variant="error" title="خطا" :message="$message" />@enderror

    <x-tables.data-table :headers="['نام', 'ایمیل', 'سطح دسترسی سیستم', 'طرف حساب', 'نقش‌های تجاری', 'عملیات']">
        @foreach ($users as $user)
            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                <td class="px-5 py-3 sm:px-6">
                    <span class="flex items-center gap-2 font-medium text-gray-800 dark:text-white/90">
                        {{ $user['name'] }}
                        @if ($user['id'] === auth()->id())
                            <x-ui.badge color="light" size="sm">شما</x-ui.badge>
                        @endif
                    </span>
                </td>
                <x-tables.ltr class="px-5 py-3" :value="$user['email']" tone="muted" />
                <td class="px-5 py-3">
                    @foreach ($user['roles'] as $role)
                        <x-ui.badge :color="$role === 'admin' ? 'primary' : 'light'" size="sm">
                            {{ $roleLabels[$role] ?? $role }}
                        </x-ui.badge>
                    @endforeach
                </td>
                {{-- The business identity behind the login, if there is one. --}}
                <td class="px-5 py-3">
                    @if ($user['party_url'])
                        <a href="{{ $user['party_url'] }}" class="text-theme-sm font-medium text-brand-500 hover:underline">{{ $user['party_name'] }}</a>
                    @else
                        <span class="text-theme-sm text-gray-400">—</span>
                    @endif
                </td>
                <td class="px-5 py-3">
                    @forelse ($user['business_roles'] as $businessRole)
                        <x-ui.badge color="light" size="sm">{{ $businessRole }}</x-ui.badge>
                    @empty
                        <span class="text-theme-sm text-gray-400">—</span>
                    @endforelse
                </td>
                <td class="px-5 py-3">
                    <div class="flex items-center gap-1">
                        <button type="button" x-data @click="$dispatch('open-edit-user', { id: {{ $user['id'] }} })"
                            class="rounded-lg px-3 py-1.5 text-sm text-brand-500 hover:bg-gray-100 dark:hover:bg-white/[0.03]"
                            aria-label="ویرایش {{ $user['name'] }}">
                            ویرایش
                        </button>

                        @if ($user['id'] !== auth()->id())
                            <form method="POST" action="{{ route('users.destroy', $user['id']) }}"
                                onsubmit="return confirm('حساب «{{ $user['name'] }}» حذف شود؟ این عملیات قابل بازگشت نیست.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                    class="rounded-lg px-3 py-1.5 text-sm text-error-500 hover:bg-gray-100 dark:hover:bg-white/[0.03]"
                                    aria-label="حذف {{ $user['name'] }}">
                                    حذف
                                </button>
                            </form>
                        @endif
                    </div>
                </td>
            </tr>
        @endforeach
    </x-tables.data-table>
</div>

{{-- ---------- Create user modal ---------- --}}
{{-- Re-opens itself on validation failure (old('_form') marks which form failed). --}}
<x-ui.modal x-on:open-create-user.window="open = true" :isOpen="old('_form') === 'create'" class="max-w-lg p-6">
    <h4 class="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">ساخت کاربر جدید</h4>
    <p class="mb-5 text-sm text-gray-500 dark:text-gray-400">حساب جدید فقط از همین‌جا ساخته می‌شود.</p>

    <form method="POST" action="{{ route('users.store') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="_form" value="create">
        @php $formErrors = old('_form') === 'create'; @endphp

        <div>
            <label class="{{ $labelClass }}">نام و نام خانوادگی</label>
            <input type="text" name="name" required value="{{ old('_form') === 'create' ? old('name') : '' }}" class="{{ $inputClass }}">
            @if ($formErrors && $errors->has('name'))<p class="mt-1 text-xs text-error-500">{{ $errors->first('name') }}</p>@endif
        </div>

        <div>
            <label class="{{ $labelClass }}">ایمیل</label>
            <input type="email" name="email" required dir="ltr" value="{{ old('_form') === 'create' ? old('email') : '' }}" class="{{ $inputClass }} text-left">
            @if ($formErrors && $errors->has('email'))<p class="mt-1 text-xs text-error-500">{{ $errors->first('email') }}</p>@endif
        </div>

        <div>
            <label class="{{ $labelClass }}">سطح دسترسی سیستم</label>
            <select name="role" required class="{{ $inputClass }}">
                <option value="">انتخاب نقش</option>
                @foreach ($roles as $role)
                    <option value="{{ $role }}" @selected(old('_form') === 'create' && old('role') === $role)>{{ $roleLabels[$role] ?? $role }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-gray-400">این فقط تعیین می‌کند کاربر در نرم‌افزار چه کاری می‌تواند انجام دهد.</p>
        </div>

        <div>
            <label class="{{ $labelClass }}">رمز عبور</label>
            <input type="password" name="password" required dir="ltr" autocomplete="new-password" class="{{ $inputClass }} text-left">
            @if ($formErrors && $errors->has('password'))<p class="mt-1 text-xs text-error-500">{{ $errors->first('password') }}</p>@endif
        </div>

        <div>
            <label class="{{ $labelClass }}">تکرار رمز عبور</label>
            <input type="password" name="password_confirmation" required dir="ltr" autocomplete="new-password" class="{{ $inputClass }} text-left">
        </div>

        @include('pages.users.partials.party-link', ['formKey' => 'create', 'user' => null])

        <div class="flex justify-end gap-2 pt-2">
            <button type="button" @click="open = false"
                class="h-10 rounded-lg border border-gray-300 px-4 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300">انصراف</button>
            <button type="submit" class="h-10 rounded-lg bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600">ساخت کاربر</button>
        </div>
    </form>
</x-ui.modal>

{{-- ---------- Edit user modals (one per row) ---------- --}}
@foreach ($users as $user)
    @php $formKey = 'edit-'.$user['id']; @endphp
    <x-ui.modal x-on:open-edit-user.window="if ($event.detail.id === {{ $user['id'] }}) open = true" :isOpen="old('_form') === $formKey" class="max-w-lg p-6">
        <h4 class="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">ویرایش کاربر</h4>
        <p class="mb-5 text-sm text-gray-500 dark:text-gray-400" dir="ltr">{{ $user['email'] }}</p>

        <form method="POST" action="{{ route('users.update', $user['id']) }}" class="space-y-4">
            @csrf
            @method('PUT')
            <input type="hidden" name="_form" value="{{ $formKey }}">
            @php $formErrors = old('_form') === $formKey; @endphp

            <div>
                <label class="{{ $labelClass }}">نام و نام خانوادگی</label>
                <input type="text" name="name" required value="{{ old('_form') === $formKey ? old('name') : $user['name'] }}" class="{{ $inputClass }}">
                @if ($formErrors && $errors->has('name'))<p class="mt-1 text-xs text-error-500">{{ $errors->first('name') }}</p>@endif
            </div>

            <div>
                <label class="{{ $labelClass }}">ایمیل</label>
                <input type="email" name="email" required dir="ltr" value="{{ old('_form') === $formKey ? old('email') : $user['email'] }}" class="{{ $inputClass }} text-left">
                @if ($formErrors && $errors->has('email'))<p class="mt-1 text-xs text-error-500">{{ $errors->first('email') }}</p>@endif
            </div>

            <div>
                <label class="{{ $labelClass }}">سطح دسترسی سیستم</label>
                @php $currentRole = old('_form') === $formKey ? old('role') : ($user['roles'][0] ?? ''); @endphp
                <select name="role" required class="{{ $inputClass }}">
                    @foreach ($roles as $role)
                        <option value="{{ $role }}" @selected($currentRole === $role)>{{ $roleLabels[$role] ?? $role }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="{{ $labelClass }}">شناسه چت تلگرام (اختیاری)</label>
                <input type="text" name="telegram_id" dir="ltr" placeholder="مثلاً 123456789"
                    value="{{ old('_form') === $formKey ? old('telegram_id') : $user['telegram_id'] }}" class="{{ $inputClass }} text-left">
                <p class="mt-1 text-xs text-gray-400">برای دریافت هشدارها در تلگرام؛ کاربر این عدد را از رباتی مثل @userinfobot می‌گیرد.</p>
                @if ($formErrors && $errors->has('telegram_id'))<p class="mt-1 text-xs text-error-500">{{ $errors->first('telegram_id') }}</p>@endif
            </div>

            <div>
                <label class="{{ $labelClass }}">رمز عبور جدید (اختیاری)</label>
                <input type="password" name="password" dir="ltr" autocomplete="new-password" class="{{ $inputClass }} text-left">
                @if ($formErrors && $errors->has('password'))<p class="mt-1 text-xs text-error-500">{{ $errors->first('password') }}</p>@endif
            </div>

            <div>
                <label class="{{ $labelClass }}">تکرار رمز عبور</label>
                <input type="password" name="password_confirmation" dir="ltr" autocomplete="new-password" class="{{ $inputClass }} text-left">
            </div>

            @include('pages.users.partials.party-link', ['formKey' => $formKey, 'user' => $user])

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" @click="open = false"
                    class="h-10 rounded-lg border border-gray-300 px-4 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300">انصراف</button>
                <button type="submit" class="h-10 rounded-lg bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600">ذخیره تغییرات</button>
            </div>
        </form>
    </x-ui.modal>
@endforeach
@endsection
