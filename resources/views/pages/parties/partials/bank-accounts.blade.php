{{--
    The counterparty's OWN bank accounts — where their money is. These are never
    internal ledger accounts and can never be posted to; the company's own
    cash/bank accounts live under «امور حساب ها».
--}}
<div class="grid gap-4 lg:grid-cols-3">
    <x-common.component-card title="حساب‌های بانکی طرف حساب" class="lg:col-span-2">
        @if ($party->bankAccounts->where('is_active', true)->isEmpty())
            <x-states.state variant="empty"
                title="حساب بانکی ثبت نشده"
                message="برای این طرف حساب هنوز حساب بانکی ثبت نشده است." />
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-max">
                    <thead class="border-b border-gray-200 dark:border-gray-800">
                        <tr>
                            <th class="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 sm:px-6 dark:text-gray-400">بانک</th>
                            <th class="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 sm:px-6 dark:text-gray-400">صاحب حساب</th>
                            <th class="px-5 py-3 text-end text-theme-xs font-medium text-gray-500 sm:px-6 dark:text-gray-400">شماره حساب</th>
                            <th class="px-5 py-3 text-end text-theme-xs font-medium text-gray-500 sm:px-6 dark:text-gray-400">شماره کارت</th>
                            <th class="px-5 py-3 text-end text-theme-xs font-medium text-gray-500 sm:px-6 dark:text-gray-400">شبا</th>
                            <th class="px-5 py-3 text-end text-theme-xs font-medium text-gray-500 sm:px-6 dark:text-gray-400">عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($party->bankAccounts->where('is_active', true) as $account)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                                <td class="px-5 py-3 text-theme-sm text-gray-800 sm:px-6 dark:text-white/90">
                                    {{ $account->bank_name ?? '—' }}
                                    @if ($account->is_default)
                                        <x-ui.badge color="success" size="sm" class="ms-1">پیش‌فرض</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-theme-sm text-gray-600 sm:px-6 dark:text-gray-300">{{ $account->account_holder ?? '—' }}</td>
                                <x-tables.ltr :value="$account->account_number" mono />
                                <x-tables.ltr :value="$account->card_number" mono />
                                <x-tables.ltr :value="$account->iban" mono />
                                <td class="px-5 py-3 text-end sm:px-6">
                                    <form method="POST" action="{{ route('parties.bank-accounts.destroy', [$party, $account]) }}" class="inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-theme-sm text-error-500 hover:underline">غیرفعال کردن</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-common.component-card>

    <x-common.component-card title="افزودن حساب بانکی">
        <form method="POST" action="{{ route('parties.bank-accounts.store', $party) }}" class="space-y-3">
            @csrf
            <div>
                <label class="mb-1 block text-theme-sm text-gray-600 dark:text-gray-300">نام بانک</label>
                <input name="bank_name" value="{{ old('bank_name') }}" class="h-10 w-full rounded-md border border-gray-300 px-3 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            </div>
            <div>
                <label class="mb-1 block text-theme-sm text-gray-600 dark:text-gray-300">صاحب حساب</label>
                <input name="account_holder" value="{{ old('account_holder', $party->name) }}" class="h-10 w-full rounded-md border border-gray-300 px-3 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            </div>
            <div>
                <label class="mb-1 block text-theme-sm text-gray-600 dark:text-gray-300">شماره حساب</label>
                <input name="account_number" dir="ltr" value="{{ old('account_number') }}" class="h-10 w-full rounded-md border border-gray-300 px-3 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            </div>
            <div>
                <label class="mb-1 block text-theme-sm text-gray-600 dark:text-gray-300">شماره کارت</label>
                <input name="card_number" dir="ltr" value="{{ old('card_number') }}" class="h-10 w-full rounded-md border border-gray-300 px-3 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            </div>
            <div>
                <label class="mb-1 block text-theme-sm text-gray-600 dark:text-gray-300">شبا</label>
                <input name="iban" dir="ltr" value="{{ old('iban') }}" class="h-10 w-full rounded-md border border-gray-300 px-3 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            </div>
            <label class="flex items-center gap-2 text-theme-sm text-gray-600 dark:text-gray-300">
                <input type="checkbox" name="is_default" value="1" class="rounded border-gray-300">
                حساب پیش‌فرض
            </label>
            <button type="submit" class="h-9 w-full rounded-md bg-brand-500 px-3 text-sm font-medium text-white hover:bg-brand-600">ثبت حساب</button>
        </form>
    </x-common.component-card>
</div>
