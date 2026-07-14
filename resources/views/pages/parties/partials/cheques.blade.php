@php
    use App\Domain\Accounting\Support\JalaliPeriod;

    $chequeHeaders = [
        ['label' => 'شماره چک'],
        ['label' => 'نوع'],
        ['label' => 'مبلغ', 'align' => 'end'],
        ['label' => 'تاریخ سررسید'],
        ['label' => 'بانک'],
        ['label' => 'وضعیت'],
    ];
@endphp

<div class="mb-3 flex justify-end">
    <a href="{{ route('cheques.create') }}"
       class="inline-flex h-9 items-center rounded-lg bg-brand-500 px-4 text-sm font-medium text-white transition hover:bg-brand-600">
        ثبت چک جدید
    </a>
</div>

<x-common.component-card title="چک‌ها">
    @if ($cheques->isEmpty())
        <x-states.state variant="empty"
            title="این طرف حساب چکی ندارد"
            message="چک ثبت‌شده تا زمان وصول، در «اسناد دریافتنی/پرداختنی» می‌ماند و به حساب بانکی نمی‌نشیند." />
    @else
        <x-tables.data-table :headers="$chequeHeaders">
            @foreach ($cheques as $cheque)
                <tr class="border-b border-gray-100 last:border-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/5">
                    <td class="px-5 py-3 sm:px-6">
                        <a href="{{ route('cheques.show', $cheque) }}" class="font-medium text-brand-500 hover:underline">
                            <x-tables.ltr :value="$cheque->serial ?? '—'" :cell="false" tone="brand" />
                        </a>
                    </td>
                    <td class="whitespace-nowrap px-5 py-3 text-gray-700 sm:px-6 dark:text-gray-300">{{ $cheque->directionLabel() }}</td>
                    <x-tables.num class="px-5 py-3 sm:px-6" :value="$cheque->amount" type="toman" />
                    <td class="whitespace-nowrap px-5 py-3 text-sm sm:px-6">
                        <span class="{{ $cheque->isLate() ? 'font-medium text-error-600 dark:text-error-400' : 'text-gray-600 dark:text-gray-400' }}">
                            {{ JalaliPeriod::fmtDate($cheque->due_date) }}
                        </span>
                    </td>
                    <td class="px-5 py-3 text-gray-600 sm:px-6 dark:text-gray-400">
                        {{ $cheque->bankAccount?->name ?? $cheque->bank_name ?? '—' }}
                    </td>
                    <td class="px-5 py-3 sm:px-6">
                        <x-ui.status :status="$cheque->badgeStatus()" :label="$cheque->statusLabel()" />
                    </td>
                </tr>
            @endforeach
        </x-tables.data-table>
    @endif
</x-common.component-card>
