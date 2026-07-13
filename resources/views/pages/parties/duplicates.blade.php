@extends('layouts.app')

@section('content')
<x-common.page-breadcrumb pageTitle="بررسی موارد تکراری" parentLabel="طرف حساب‌ها" :parentUrl="route('parties.index')" />

<div class="space-y-4">
    <x-common.component-card title="طرف حساب‌های احتمالاً تکراری"
        desc="گروه‌هایی که یک شناسه مشترک دارند. هیچ ادغام خودکاری انجام نمی‌شود — تصمیم با شماست.">

        @if ($groups->isEmpty())
            <x-states.state variant="empty"
                title="موردی یافت نشد"
                message="هیچ گروهی با شناسه مشترک پیدا نشد." />
        @else
            <div class="space-y-4">
                @foreach ($groups as $group)
                    <div class="rounded-card border border-gray-200 p-4 dark:border-gray-800">
                        <div class="mb-3 flex flex-wrap items-center gap-2">
                            <x-ui.status :status="$group['strength'] === 'strong' ? 'needs_review' : 'pending'"
                                :label="$group['strength'] === 'strong' ? 'نشانه قوی' : 'نشانه ضعیف'" />
                            <span class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $group['reason'] }}</span>
                            <x-tables.ltr :value="$group['value']" :cell="false" tone="muted" class="text-theme-sm" />
                            <span class="text-theme-sm text-gray-500 dark:text-gray-400">({{ $group['parties']->count() }} پرونده)</span>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            @foreach ($group['parties'] as $party)
                                <a href="{{ route('parties.show', $party) }}"
                                    class="inline-flex items-center gap-2 rounded-md border border-gray-300 px-3 py-1.5 text-theme-sm hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-white/5">
                                    <span class="font-medium text-brand-500">{{ $party->name }}</span>
                                    <span class="text-gray-400">#{{ $party->id }}</span>
                                    @foreach ($party->roles->where('is_active', true) as $role)
                                        <x-ui.badge color="light" size="sm">{{ \App\Domain\Accounting\Support\PartyRoleType::coerce($role->role)->label() }}</x-ui.badge>
                                    @endforeach
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-common.component-card>
</div>
@endsection
