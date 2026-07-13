@php
    $loanHeaders = [
        ['label' => 'نوع'],
        ['label' => 'اصل وام', 'align' => 'end'],
        ['label' => 'مانده اصل وام', 'align' => 'end'],
        ['label' => 'مبلغ پرداخت‌شده', 'align' => 'end'],
        ['label' => 'سررسید بعدی'],
        ['label' => 'وضعیت'],
    ];
@endphp

<div class="mb-3 flex justify-end">
    <a href="{{ route('loans.create') }}"
       class="inline-flex h-9 items-center rounded-lg bg-brand-500 px-4 text-sm font-medium text-white transition hover:bg-brand-600">
        ثبت وام جدید
    </a>
</div>

<x-common.component-card title="وام و اقساط">
    @if ($loans->isEmpty())
        <x-states.state variant="empty"
            title="این طرف حساب وامی ندارد"
            message="«وام دریافتی» یعنی پولی که از این شخص گرفته‌ایم و «وام پرداختی» یعنی پولی که به او داده‌ایم." />
    @else
        <x-tables.data-table :headers="$loanHeaders">
            @foreach ($loans as $row)
                <tr class="border-b border-gray-100 last:border-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/5">
                    <td class="px-5 py-3 sm:px-6">
                        <a href="{{ route('loans.show', $row['loan']) }}" class="font-medium text-brand-500 hover:underline">
                            {{ $row['direction'] }}
                        </a>
                    </td>
                    <x-tables.num class="px-5 py-3 sm:px-6" :value="$row['principal']" type="toman" />
                    <x-tables.num class="px-5 py-3 sm:px-6" :value="$row['remaining_principal']" type="toman" zero
                        :tone="$row['remaining_principal'] > 0 ? 'default' : 'positive'" />
                    <x-tables.num class="px-5 py-3 sm:px-6" :value="$row['paid_total']" type="toman" zero tone="muted" />
                    <td class="whitespace-nowrap px-5 py-3 text-sm text-gray-600 sm:px-6 dark:text-gray-400">
                        {{ $row['next_due_fa'] ?? '—' }}
                    </td>
                    <td class="px-5 py-3 sm:px-6">
                        <x-ui.status :status="$row['status']" :label="$row['status_label']" />
                    </td>
                </tr>
            @endforeach
        </x-tables.data-table>
    @endif
</x-common.component-card>
