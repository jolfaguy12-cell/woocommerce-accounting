@extends('layouts.app')

@php
    use App\Domain\Accounting\Support\PartyRoleType;
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="بررسی موارد تکراری" parentLabel="طرف حساب‌ها" :parentUrl="route('parties.index')" />

<div class="space-y-4">
    @if (session('success'))
        <x-ui.alert variant="success" :message="session('success')" />
    @endif
    @foreach ($errors->all() as $error)
        <x-ui.alert variant="error" :message="$error" />
    @endforeach

    <x-common.component-card title="طرف حساب‌های احتمالاً تکراری"
        desc="گروه‌هایی که یک شناسه مشترک دارند. هیچ ادغام خودکاری انجام نمی‌شود — تصمیم با شماست.">

        @if ($groups->isEmpty())
            <x-states.state variant="empty"
                title="موردی یافت نشد"
                message="هیچ گروهی با شناسه مشترک پیدا نشد." />
        @else
            <div class="space-y-4">
                @foreach ($groups as $group)
                    @php
                        // The oldest id is offered as the survivor by default: it is the one
                        // with the longest history, and the one every existing link points at.
                        $default = $group['parties']->sortBy('id')->first();
                    @endphp

                    <div class="rounded-card border border-gray-200 p-4 dark:border-gray-800"
                         x-data="{ open: false, survivor: '{{ $default->id }}' }">
                        <div class="mb-3 flex flex-wrap items-center gap-2">
                            <x-ui.status :status="$group['strength'] === 'strong' ? 'needs_review' : 'pending'"
                                :label="$group['strength'] === 'strong' ? 'نشانه قوی' : 'نشانه ضعیف'" />
                            <span class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $group['reason'] }}</span>
                            <x-tables.ltr :value="$group['value']" :cell="false" tone="muted" class="text-theme-sm" />
                            <span class="text-theme-sm text-gray-500 dark:text-gray-400">({{ $group['parties']->count() }} پرونده)</span>

                            @if ($canMerge && $group['parties']->count() > 1)
                                <button type="button" x-on:click="open = ! open"
                                    class="ms-auto inline-flex h-8 items-center rounded-md bg-brand-500 px-3 text-theme-sm font-medium text-white hover:bg-brand-600">
                                    ادغام طرف حساب‌ها
                                </button>
                            @endif
                        </div>

                        <div class="flex flex-wrap gap-2">
                            @foreach ($group['parties'] as $party)
                                <a href="{{ route('parties.show', $party) }}"
                                    class="inline-flex items-center gap-2 rounded-md border border-gray-300 px-3 py-1.5 text-theme-sm hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-white/5">
                                    <span class="font-medium text-brand-500">{{ $party->name }}</span>
                                    <span class="text-gray-400">#{{ $party->id }}</span>
                                    @foreach ($party->roles->where('is_active', true) as $role)
                                        <x-ui.badge color="light" size="sm">{{ PartyRoleType::coerce($role->role)->label() }}</x-ui.badge>
                                    @endforeach
                                </a>
                            @endforeach
                        </div>

                        @if ($canMerge && $group['parties']->count() > 1)
                            {{--
                                «ادغام طرف حساب‌ها». One party survives; the others become
                                aliases of it. Nothing in the ledger is rewritten — the merged
                                parties keep their ids and every journal line ever posted
                                against them, and the survivor's profile simply reads both.
                                That is why the merge is safe, and why it is still a decision
                                a person has to make and give a reason for.
                            --}}
                            <div x-show="open" x-cloak x-transition
                                 class="mt-4 space-y-3 rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]">
                                <p class="text-theme-sm text-gray-700 dark:text-gray-300">
                                    پرونده اصلی را انتخاب کنید. بقیه پرونده‌ها به آن متصل می‌شوند و سوابقشان در همان پرونده تجمیع می‌شود.
                                    <span class="font-medium">هیچ سند حسابداری تغییر نمی‌کند یا حذف نمی‌شود.</span>
                                </p>

                                <div class="flex flex-wrap gap-4">
                                    @foreach ($group['parties'] as $party)
                                        <label class="flex items-center gap-2 text-theme-sm text-gray-700 dark:text-gray-300">
                                            <input type="radio" x-model="survivor" value="{{ $party->id }}"
                                                class="h-4 w-4 border-gray-300 text-brand-500 focus:ring-brand-500/20 dark:border-gray-700">
                                            {{ $party->name }} <span class="text-gray-400">#{{ $party->id }}</span>
                                        </label>
                                    @endforeach
                                </div>

                                @foreach ($group['parties'] as $party)
                                    <form method="POST" x-show="survivor !== '{{ $party->id }}'"
                                          :action="`{{ url('parties') }}/${survivor}/merge`"
                                          class="flex flex-wrap items-end gap-2 border-t border-gray-200 pt-3 dark:border-gray-800"
                                          x-on:submit="if (! confirm('«{{ $party->name }}» (#{{ $party->id }}) در پرونده انتخاب‌شده ادغام شود؟ این کار سوابق را حذف نمی‌کند.')) $event.preventDefault()">
                                        @csrf
                                        <input type="hidden" name="merged_party_id" value="{{ $party->id }}">

                                        <div class="flex-1">
                                            <label class="mb-1 block text-theme-xs text-gray-500 dark:text-gray-400">
                                                دلیل ادغام «{{ $party->name }}» (#{{ $party->id }})
                                            </label>
                                            <input type="text" name="reason" required maxlength="255"
                                                placeholder="مثلاً: همان شخص، دو بار ثبت شده"
                                                class="h-9 w-full rounded-md border border-gray-300 bg-white px-3 text-theme-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                                        </div>

                                        <button type="submit"
                                            class="h-9 shrink-0 rounded-md bg-brand-500 px-4 text-theme-sm font-medium text-white hover:bg-brand-600">
                                            ادغام در پرونده اصلی
                                        </button>
                                    </form>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </x-common.component-card>
</div>
@endsection
