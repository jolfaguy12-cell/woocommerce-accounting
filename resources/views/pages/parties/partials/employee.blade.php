{{--
    «حساب کارمند».

    Every figure here is a read of journal_lines through EmployeeAccountService —
    there is no employee ledger, no stored balance and no second posting path.
    The contexts stay SEPARATE by design: salary owed, an advance held, money the
    employee spent for the company, and goods they bought from it are four
    different debts in four different accounts, and only the last card nets them,
    for the eye alone.
--}}
@php
    $ea = $employeeAccount;
@endphp

<div class="space-y-4">
    @if (! $ea)
        <x-states.state variant="permission"
            title="این طرف حساب نقش «کارمند» ندارد"
            message="برای دیدن «حساب کارمند»، ابتدا نقش کارمند را از تب «مدیریت نقش‌ها» فعال کنید." />
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-kpi.compact label="حقوق" :value="$ea['salary']" type="toman" />
            <x-kpi.compact label="حقوق تحقق‌یافته" :value="$ea['accrued_salary']" type="toman" />
            <x-kpi.compact label="حقوق پرداخت‌شده" :value="$ea['paid_salary']" type="toman" />
            <x-kpi.compact label="مانده حقوق" :value="$ea['salary_balance']" type="toman" />
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <x-financial.summary
                title="حساب کارمند"
                desc="هر مانده در حساب خودش باقی می‌ماند. این جدول فقط نمایش است و هیچ تهاتری انجام نمی‌دهد."
                :rows="collect($employeeContexts)->map(fn ($c) => [
                    'label' => $c['label'],
                    'value' => $c['direction'] === 'due_to_us' ? $c['amount'] : ($c['direction'] === 'neutral' ? $c['amount'] : -$c['amount']),
                    'type' => 'toman',
                    'signed' => $c['direction'] !== 'neutral',
                    'muted' => $c['direction'] === 'neutral',
                ])->values()->all()"
                :total="[
                    'label' => 'خالص (نمایشی)',
                    'value' => $ea['consolidated'],
                    'type' => 'toman',
                    'signed' => true,
                ]" />

            <x-common.component-card title="این اعداد از کجا می‌آیند؟"
                desc="هیچ مانده‌ای ذخیره نمی‌شود؛ همه از دفتر روزنامه خوانده می‌شوند.">
                <ul class="space-y-2 text-theme-sm text-gray-600 dark:text-gray-400">
                    <li><span class="font-medium text-gray-800 dark:text-white/90">حقوق تحقق‌یافته</span> — بستانکار حساب «حقوق پرداختنی» (۲۳۰۰) در لیست‌های حقوق ثبت‌شده.</li>
                    <li><span class="font-medium text-gray-800 dark:text-white/90">حقوق پرداخت‌شده</span> — بدهکار همان حساب، هنگام پرداخت.</li>
                    <li><span class="font-medium text-gray-800 dark:text-white/90">مانده حقوق</span> — تفاضل این دو.</li>
                    <li><span class="font-medium text-gray-800 dark:text-white/90">مساعده</span> — حساب «مساعده کارمند» (۱۴۰۰).</li>
                    <li><span class="font-medium text-gray-800 dark:text-white/90">هزینه پرداخت‌شده توسط کارمند</span> — حساب «حساب جاری کارمند» (۲۳۵۰). موجودی بانک شرکت با این هزینه‌ها کم نمی‌شود.</li>
                    <li><span class="font-medium text-gray-800 dark:text-white/90">خرید از شرکت</span> — حساب «حساب‌های دریافتنی» (۱۲۰۰)، یعنی همین شخص در نقش مشتری.</li>
                    <li><span class="font-medium text-gray-800 dark:text-white/90">وام</span> — حساب‌های ۱۶۰۰ و ۲۲۰۰.</li>
                </ul>

                <p class="mt-4 rounded-lg bg-warning-50 px-3 py-2 text-theme-xs text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
                    «خالص (نمایشی)» فقط یک نمای کلی است. تهاتر حقوق با مساعده یا با خرید کارمند، فقط با یک عملیات مالی جداگانه و ثبت سند انجام می‌شود.
                </p>
            </x-common.component-card>
        </div>

        <x-common.component-card title="گردش کامل حساب کارمند"
            desc="همه اسناد این شخص در همه حساب‌ها — در تب «گردش کامل حساب» قابل مشاهده است.">
            <a href="{{ route('parties.show', ['party' => $party, 'tab' => 'statement']) }}"
               class="inline-flex h-9 items-center rounded-lg border border-gray-300 px-4 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                مشاهده گردش کامل حساب
            </a>
        </x-common.component-card>
    @endif
</div>
