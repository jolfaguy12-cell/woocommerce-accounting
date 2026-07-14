@extends('layouts.app')

{{--
    «حساب کارمند» — the primary employee page.

    Every figure is a read of journal_lines through EmployeeAccountService. There is
    no employee ledger, no stored balance and no second posting path: the salary is
    account 2300, the advance is 1400, the money the employee laid out for the
    company is 2350, what they bought from us is 1200, and their loans are 1600/2200.

    The contexts stay SEPARATE. Only the last row of the summary nets them, and it
    nets them for the eye alone — «تهاتر» is a deliberate, posted operation, and it
    lives on its own screen.
--}}
@php
    $labelClass = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400';
    $inputClass = 'h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90';
    $selectClass = 'h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
@endphp

@section('content')
<x-common.page-breadcrumb :pageTitle="$party->name" parentLabel="کارکنان" :parentUrl="route('employees.index')" />

<div class="space-y-4">
    @if (session('success'))
        <x-ui.alert variant="success" :message="session('success')" />
    @endif
    @foreach ($errors->all() as $error)
        <x-ui.alert variant="error" :message="$error" />
    @endforeach

    {{-- The four salary figures, in the order the question is actually asked:
         what was earned, what was handed over, what is left. --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-kpi.compact label="حقوق" :value="$summary['salary']" type="toman" />
        <x-kpi.compact label="حقوق تحقق‌یافته" :value="$summary['accrued_salary']" type="toman" />
        <x-kpi.compact label="حقوق پرداخت‌شده" :value="$summary['paid_salary']" type="toman" />
        <x-kpi.compact label="مانده حقوق" :value="$summary['salary_balance']" type="toman" />
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-financial.summary
            title="حساب کارمند"
            desc="هر مانده در حساب خودش می‌ماند. این جدول فقط نمایش است و هیچ تهاتری انجام نمی‌دهد."
            :rows="collect($contexts)->map(fn ($c) => [
                'label' => $c['label'],
                'value' => $c['direction'] === 'due_to_us' ? $c['amount'] : ($c['direction'] === 'neutral' ? $c['amount'] : -$c['amount']),
                'type' => 'toman',
                'signed' => $c['direction'] !== 'neutral',
                'muted' => $c['direction'] === 'neutral',
            ])->values()->all()"
            :total="[
                'label' => 'خالص (نمایشی)',
                'value' => $summary['consolidated'],
                'type' => 'toman',
                'signed' => true,
            ]" />

        <div class="space-y-4">
            {{-- «پرداخت حقوق» — capped at «مانده حقوق». Paying more than was earned
                 does not clear the debt, it inverts it. --}}
            <x-common.component-card title="پرداخت حقوق"
                :desc="'حداکثر قابل پرداخت: '.number_format(max(0, $summary['salary_balance'])).' تومان'">
                @if ($summary['salary_balance'] <= 0)
                    <x-states.state variant="empty"
                        title="مانده حقوق صفر است"
                        message="ابتدا از صفحهٔ «ثبت حقوق دوره»، حقوق این دوره را ثبت کنید." />
                @else
                    <form method="POST" action="{{ route('employees.salary-payment', $party) }}" class="grid gap-4 sm:grid-cols-2">
                        @csrf
                        <x-form.money-input name="amount" label="مبلغ" required />
                        <div>
                            <label class="{{ $labelClass }}">از حساب</label>
                            <select name="bank_account_id" required class="{{ $selectClass }}">
                                @foreach ($banks as $bank)
                                    <option value="{{ $bank->id }}">{{ $bank->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <x-form.jalali-date name="accounting_date" label="تاریخ سند" :value="$today" required />
                        <div>
                            <label class="{{ $labelClass }}">مرجع / شماره پیگیری</label>
                            <input type="text" name="reference" class="{{ $inputClass }}">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="{{ $labelClass }}">یادداشت</label>
                            <input type="text" name="note" class="{{ $inputClass }}">
                        </div>
                        <div class="sm:col-span-2">
                            <button type="submit"
                                class="inline-flex h-10 items-center rounded-lg bg-brand-500 px-4 text-theme-sm font-medium text-white hover:bg-brand-600">
                                پرداخت حقوق
                            </button>
                        </div>
                    </form>
                @endif
            </x-common.component-card>

            {{-- «مساعده» — an ASSET (1400), not a reduction of the salary debt. The
                 employee owes it back, and the next payroll run recovers it. --}}
            <x-common.component-card title="مساعده"
                desc="مساعده حقوقِ پرداخت‌شده پیش از تحقق است: در حساب «مساعده» می‌نشیند و در لیست حقوق بعدی کسر می‌شود — «مانده حقوق» را کم نمی‌کند.">
                <form method="POST" action="{{ route('employees.advance', $party) }}" class="grid gap-4 sm:grid-cols-2">
                    @csrf
                    <x-form.money-input name="amount" label="مبلغ مساعده" required />
                    <div>
                        <label class="{{ $labelClass }}">از حساب</label>
                        <select name="bank_account_id" required class="{{ $selectClass }}">
                            @foreach ($banks as $bank)
                                <option value="{{ $bank->id }}">{{ $bank->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <x-form.jalali-date name="accounting_date" label="تاریخ سند" :value="$today" required />
                    <div>
                        <label class="{{ $labelClass }}">مرجع</label>
                        <input type="text" name="reference" class="{{ $inputClass }}">
                    </div>
                    <div class="sm:col-span-2">
                        <button type="submit"
                            class="inline-flex h-10 items-center rounded-lg border border-gray-300 px-4 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                            پرداخت مساعده
                        </button>
                    </div>
                </form>
            </x-common.component-card>
        </div>
    </div>

    {{-- «بازپرداخت هزینه کارمند» — paying back what they spent on the company. It
         debits 2350, the very account the expense credited; it is not salary, and it
         never touches 2300. --}}
    <x-common.component-card title="بازپرداخت هزینه کارمند"
        :desc="'هزینه‌هایی که این کارمند از جیب خود پرداخت کرده و هنوز به او برنگشته است: '.number_format(max(0, $summary['employee_paid_expenses'])).' تومان'">
        @if ($summary['employee_paid_expenses'] <= 0)
            <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                هزینه‌ای که این کارمند پرداخت کرده باشد و بازپرداخت‌نشده مانده باشد، وجود ندارد.
            </p>
        @else
            <a href="{{ route('expenses.reimbursements.create', ['type' => 'employee', 'party' => $party->id]) }}"
               class="inline-flex h-10 items-center rounded-lg bg-brand-500 px-4 text-theme-sm font-medium text-white hover:bg-brand-600">
                بازپرداخت هزینه کارمند
            </a>
        @endif
    </x-common.component-card>

    {{-- Loans are their own contract with their own schedule; this page only shows
         that they exist and what is left of them. --}}
    @if ($loans->isNotEmpty())
        <x-common.component-card title="وام و اقساط">
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr class="text-right text-theme-xs text-gray-500 dark:text-gray-400">
                            <th class="px-4 py-3 font-medium">نوع</th>
                            <th class="px-4 py-3 font-medium">اصل وام</th>
                            <th class="px-4 py-3 font-medium">مانده اصل</th>
                            <th class="px-4 py-3 font-medium">قسط بعدی</th>
                            <th class="px-4 py-3 font-medium">وضعیت</th>
                            <th class="px-4 py-3 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($loans as $row)
                            <tr>
                                <td class="px-4 py-3 text-theme-sm text-gray-700 dark:text-gray-300">{{ $row['direction'] }}</td>
                                <x-tables.num :value="$row['principal']" type="toman" tone="muted" />
                                <x-tables.num :value="$row['remaining_principal']" type="toman" />
                                <x-tables.ltr :value="$row['next_due_fa']" />
                                <td class="px-4 py-3"><x-ui.status :status="$row['status']" :label="$row['status_label']" /></td>
                                <td class="px-4 py-3">
                                    <a href="{{ route('loans.show', $row['loan']) }}" class="text-theme-sm text-brand-500 hover:underline">مشاهده</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-common.component-card>
    @endif

    {{-- «گردش کامل حساب» — every journal line on this identity, across every one of
         the accounts above, searchable. This is the employee's complete transaction
         history; there is no separate employee history to build, and there never was. --}}
    <x-common.component-card title="گردش کامل حساب"
        desc="همه اسناد این شخص در همه حساب‌ها — حقوق، مساعده، هزینه‌های پرداخت‌شده توسط او، خرید از شرکت و وام‌ها.">
        <form method="GET" class="mb-4">
            <input type="search" name="search" value="{{ $statementQuery->search() }}"
                placeholder="جستجو در شرح سند یا نام حساب…"
                class="h-10 w-full max-w-sm rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
        </form>

        @if ($statement->isEmpty())
            <x-states.state variant="no-results"
                title="سندی یافت نشد"
                message="هنوز سندی به نام این کارمند ثبت نشده است، یا جستجو نتیجه‌ای نداشت." />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr class="text-right text-theme-xs text-gray-500 dark:text-gray-400">
                            <th class="px-4 py-3 font-medium">تاریخ</th>
                            <th class="px-4 py-3 font-medium">شرح</th>
                            <th class="px-4 py-3 font-medium">حساب</th>
                            <th class="px-4 py-3 font-medium">بدهکار</th>
                            <th class="px-4 py-3 font-medium">بستانکار</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($statement as $line)
                            <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <x-tables.ltr :value="$line->jalali_date" />
                                <td class="px-4 py-3 text-theme-sm text-gray-700 dark:text-gray-300">
                                    {{ $line->entry->description }}
                                </td>
                                <td class="px-4 py-3 text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $line->account->code }} — {{ $line->account->name }}
                                </td>
                                <x-tables.num :value="(int) $line->debit" type="toman" zero="—" tone="muted" />
                                <x-tables.num :value="(int) $line->credit" type="toman" zero="—" tone="muted" />
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $statement->links() }}</div>
        @endif
    </x-common.component-card>

    {{-- The contract, not the balance. Changing base_salary posts nothing: it is what
         the payroll form PROPOSES, and what the employee is actually owed is «مانده
         حقوق», which lives in the ledger. --}}
    <x-common.component-card title="اطلاعات کارمند"
        desc="این اطلاعات قراردادی است و هیچ سندی در دفتر ثبت نمی‌کند. «حقوق پایه» فقط پیشنهاد اولیهٔ فرم «ثبت حقوق دوره» است.">
        <form method="POST" action="{{ route('employees.update', $party) }}" class="grid gap-4 sm:grid-cols-2">
            @csrf
            @method('PUT')
            <x-form.money-input name="base_salary" label="حقوق پایه (ماهانه)" :value="$employee->base_salary" required />
            <div>
                <label class="{{ $labelClass }}">سمت</label>
                <input type="text" name="job_title" value="{{ old('job_title', $employee->job_title) }}" class="{{ $inputClass }}">
            </div>
            <x-form.jalali-date name="hired_at" label="تاریخ شروع همکاری" :value="$hiredAt" />
            <div class="flex items-end">
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-400">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked($employee->is_active)
                        class="size-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500">
                    کارمند فعال است
                </label>
            </div>
            <div class="sm:col-span-2">
                <label class="{{ $labelClass }}">یادداشت</label>
                <textarea name="notes" rows="2" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">{{ old('notes', $employee->notes) }}</textarea>
            </div>
            <div class="sm:col-span-2">
                <button type="submit"
                    class="inline-flex h-10 items-center rounded-lg border border-gray-300 px-4 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                    ذخیره اطلاعات
                </button>
            </div>
        </form>
    </x-common.component-card>

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
@endsection
