{{--
    Balance cards, one per accounting context, plus the display-only consolidated
    position. Each balance is its own account in the ledger and stays that way —
    nothing on this page nets, settles or writes anything.
--}}
<div class="space-y-4">
    @if (empty($balances))
        <x-states.state variant="empty"
            title="هنوز گردش مالی ندارد"
            message="برای این طرف حساب هیچ سند مالی ثبت نشده است." />
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($balances as $key => $balance)
                <x-kpi.compact
                    :label="$balance['label']"
                    :value="$balance['amount']"
                    type="toman" />
            @endforeach
        </div>

        <x-financial.summary
            title="وضعیت خالص نمایشی"
            desc="این مبلغ فقط یک نمای کلی است و به معنی تهاتر یا تسویه خودکار حساب‌ها نیست."
            :rows="collect($balances)->map(fn ($b) => [
                'label' => $b['label'],
                'value' => $b['direction'] === 'due_to_us' ? $b['amount'] : -$b['amount'],
                'type' => 'toman',
                'signed' => true,
            ])->values()->all()"
            :total="[
                'label' => 'خالص (نمایشی)',
                'value' => $consolidated,
                'type' => 'toman',
                'signed' => true,
            ]" />

        <p class="text-theme-sm text-gray-500 dark:text-gray-400">
            مثبت یعنی در مجموع به ما بدهکار است و منفی یعنی در مجموع به او بدهکاریم. هر مانده در حساب خودش باقی می‌ماند؛
            تهاتر فقط با یک عملیات مالی جداگانه و ثبت سند انجام می‌شود.
        </p>
    @endif
</div>
