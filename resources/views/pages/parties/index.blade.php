@extends('layouts.app')

@section('content')
<x-common.page-breadcrumb pageTitle="طرف حساب‌ها" />

<div class="space-y-4">
    @if (session('success'))
        <x-ui.alert variant="success" :message="session('success')" />
    @endif

    <x-common.component-card title="پرونده طرف حساب‌ها"
        desc="هر شخص یا شرکت یک پرونده دارد و می‌تواند هم‌زمان چند نقش داشته باشد. فهرست‌های مشتریان، تأمین‌کننده‌ها و مشتریان عمده نماهای فیلترشده‌ای از همین پرونده‌ها هستند.">

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <a href="{{ route('parties.index') }}"
                @class([
                    'inline-flex h-8 items-center rounded-md px-3 text-theme-sm font-medium',
                    'bg-brand-500 text-white' => blank($filters['role'] ?? null),
                    'border border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5' => filled($filters['role'] ?? null),
                ])>همه</a>

            @foreach ($roles as $role)
                <a href="{{ route('parties.index', ['role' => $role->value]) }}"
                    @class([
                        'inline-flex h-8 items-center rounded-md px-3 text-theme-sm font-medium',
                        'bg-brand-500 text-white' => ($filters['role'] ?? null) === $role->value,
                        'border border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5' => ($filters['role'] ?? null) !== $role->value,
                    ])>{{ $role->label() }}</a>
            @endforeach

            <a href="{{ route('parties.duplicates') }}"
                class="ms-auto inline-flex h-8 items-center rounded-md border border-gray-300 px-3 text-theme-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                بررسی موارد تکراری
            </a>
        </div>

        <x-tables.pro-table
            :columns="[
                ['key' => 'id', 'label' => 'شناسه', 'sort' => 'id', 'align' => 'right'],
                ['key' => 'name', 'label' => 'نام', 'sort' => 'name'],
                ['key' => 'kind', 'label' => 'نوع'],
                ['key' => 'roles', 'label' => 'نقش‌ها'],
                ['key' => 'phone', 'label' => 'شماره تماس'],
                ['key' => 'email', 'label' => 'ایمیل'],
            ]"
            :paginator="$parties"
            :query="$query"
            :searchValue="$filters['search'] ?? null"
            :filterLabels="['role' => 'نقش', 'kind' => 'نوع']"
            searchPlaceholder="جستجوی نام، تلفن، ایمیل یا کد ملی"
            emptyMessage="هنوز طرف حسابی ثبت نشده است"
            storageKey="parties.index.visibleColumns"
        >
            @foreach ($parties as $party)
                <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                    <x-tables.num x-show="visible.id" :value="$party->id" type="int" tone="muted" />
                    <td x-show="visible.name" class="px-5 py-3 sm:px-6">
                        <a href="{{ route('parties.show', $party) }}" class="font-medium text-brand-500 hover:underline">{{ $party->name }}</a>
                    </td>
                    <td x-show="visible.kind" class="px-5 py-3 text-theme-sm text-gray-600 sm:px-6 dark:text-gray-300">
                        {{ $party->party_kind === 'company' ? 'شخص حقوقی' : 'شخص حقیقی' }}
                    </td>
                    <td x-show="visible.roles" class="px-5 py-3 sm:px-6">
                        <div class="flex flex-wrap gap-1">
                            @forelse ($party->roles->where('is_active', true) as $role)
                                <x-ui.badge color="light" size="sm">{{ \App\Domain\Accounting\Support\PartyRoleType::coerce($role->role)->label() }}</x-ui.badge>
                            @empty
                                <span class="text-theme-sm text-gray-400">—</span>
                            @endforelse
                        </div>
                    </td>
                    <x-tables.ltr x-show="visible.phone" :value="$party->phone" />
                    <x-tables.ltr x-show="visible.email" :value="$party->email" />
                </tr>
            @endforeach
        </x-tables.pro-table>
    </x-common.component-card>
</div>
@endsection
