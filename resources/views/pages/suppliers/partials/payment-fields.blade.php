{{-- Shared amount + bank account + method + reference fields for pay/refund modals. --}}
<div>
    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">مبلغ (تومان)</label>
    <input type="number" name="amount" min="1" required dir="ltr" value="{{ old('amount') }}"
        class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
    @error('amount')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
</div>

<div>
    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">{{ $bankLabel ?? 'حساب' }}</label>
    <select name="bank_account_id" required class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
        <option value="">انتخاب کنید…</option>
        @foreach ($bankAccounts as $account)
            <option value="{{ $account->id }}" @selected(old('bank_account_id') == $account->id)>{{ $account->name }}</option>
        @endforeach
    </select>
    @error('bank_account_id')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">روش (اختیاری)</label>
        <select name="method" class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            <option value="">—</option>
            @foreach (['bank_transfer' => 'انتقال بانکی', 'cash' => 'نقدی', 'card' => 'کارت به کارت', 'other' => 'سایر'] as $value => $label)
                <option value="{{ $value }}" @selected(old('method') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        @error('method')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">شماره پیگیری (اختیاری)</label>
        <input type="text" name="reference" dir="ltr" value="{{ old('reference') }}"
            class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
        @error('reference')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
    </div>
</div>
