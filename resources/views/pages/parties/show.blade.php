@extends('layouts.app')

@php
    use App\Domain\Accounting\Support\PartyRoleType;

    $tabs = [
        'overview' => 'نمای کلی',
        'statement' => 'گردش کامل حساب',
        'roles' => 'مدیریت نقش‌ها',
        'bank-accounts' => 'حساب‌های بانکی طرف حساب',
        'duplicates' => 'بررسی موارد تکراری',
    ];
@endphp

@section('content')
<x-common.page-breadcrumb :pageTitle="$party->name" parentLabel="طرف حساب‌ها" :parentUrl="route('parties.index')" />

<div class="space-y-4">
    @if (session('success'))
        <x-ui.alert variant="success" :message="session('success')" />
    @endif
    @foreach ($errors->all() as $error)
        <x-ui.alert variant="error" :message="$error" />
    @endforeach

    {{-- Shared identity header: one person, whatever they are to us. --}}
    <x-common.component-card :title="$party->name">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="grid flex-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">نوع</p>
                    <p class="mt-1 text-sm font-medium text-gray-800 dark:text-white/90">
                        {{ $party->party_kind === 'company' ? 'شخص حقوقی' : 'شخص حقیقی' }}
                    </p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">شماره تماس</p>
                    <x-tables.ltr :value="$party->phone" :cell="false" class="mt-1 block text-sm font-medium" />
                </div>
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">ایمیل</p>
                    <x-tables.ltr :value="$party->email" :cell="false" class="mt-1 block text-sm font-medium" />
                </div>
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">شناسه تلگرام</p>
                    <x-tables.ltr :value="$party->telegram_id" :cell="false" class="mt-1 block text-sm font-medium" />
                </div>
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $party->party_kind === 'company' ? 'شناسه ملی' : 'کد ملی' }}</p>
                    <x-tables.ltr :value="$party->party_kind === 'company' ? $party->company_national_id : $party->national_id" :cell="false" class="mt-1 block text-sm font-medium" />
                </div>
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">شناسه مالیاتی</p>
                    <x-tables.ltr :value="$party->tax_id" :cell="false" class="mt-1 block text-sm font-medium" />
                </div>
                <div class="sm:col-span-2">
                    <p class="text-xs text-gray-500 dark:text-gray-400">آدرس</p>
                    <p class="mt-1 text-sm font-medium text-gray-800 dark:text-white/90">{{ $party->address ?? '—' }}</p>
                </div>
            </div>

            <div class="shrink-0 space-y-2">
                <p class="text-xs text-gray-500 dark:text-gray-400">نقش‌های فعال</p>
                <div class="flex flex-wrap gap-1.5">
                    @forelse ($activeRoles as $role)
                        <x-ui.badge color="light">{{ PartyRoleType::coerce($role->role)->label() }}</x-ui.badge>
                    @empty
                        <span class="text-theme-sm text-gray-400">—</span>
                    @endforelse
                </div>

                <div class="flex flex-wrap gap-2 pt-1">
                    @if ($party->hasRole(PartyRoleType::Customer))
                        <a href="{{ route('customers.show', $party) }}"
                            class="inline-flex h-8 items-center rounded-md border border-gray-300 px-3 text-theme-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                            پرونده مشتری
                        </a>
                    @endif
                    @if ($party->hasRole(PartyRoleType::Supplier))
                        <a href="{{ route('suppliers.show', $party) }}"
                            class="inline-flex h-8 items-center rounded-md border border-gray-300 px-3 text-theme-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                            پرونده تأمین‌کننده
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </x-common.component-card>

    <x-nav.tabs :tabs="$tabs" param="tab" :active="$tab" />

    @if ($tab === 'overview')
        @include('pages.parties.partials.overview')
    @elseif ($tab === 'statement')
        @include('pages.parties.partials.statement')
    @elseif ($tab === 'roles')
        @include('pages.parties.partials.roles')
    @elseif ($tab === 'bank-accounts')
        @include('pages.parties.partials.bank-accounts')
    @elseif ($tab === 'duplicates')
        @include('pages.parties.partials.duplicates')
    @endif
</div>
@endsection
