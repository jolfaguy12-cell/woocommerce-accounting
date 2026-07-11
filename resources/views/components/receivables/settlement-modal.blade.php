@props([
    'party',
    'bankAccounts',
])

{{--
    One-click "record a customer payment" — allocates across the customer's
    open orders oldest-first (see CreditOrderAllocator), so the same action
    works whether it's opened from this order's page or the customer's own
    page. Mirrors the products/show.blade.php cost-entry modal pattern
    exactly: display/hidden amount pair + formatTomanInput, Cancel/Submit.
--}}
<x-ui.modal :isOpen="$errors->hasAny(['amount', 'bank_account_id']) && old('_settlement_modal')" @open-settlement-modal.window="open = true" class="max-w-sm p-6">
    <form method="POST" action="{{ route('customers.settlement', $party) }}">
        @csrf
        <input type="hidden" name="_settlement_modal" value="1">
        <h4 class="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">ثبت تسویه</h4>
        <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">مبلغ ابتدا قدیمی‌ترین سفارش باز {{ $party->name }} را تسویه می‌کند و باقی‌مانده به ترتیب روی سفارش‌های بعدی اعمال می‌شود؛ مازاد به‌عنوان اعتبار مشتری نگه داشته می‌شود.</p>

        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">مبلغ واریزی (تومان)</label>
        <input type="text" inputmode="numeric" dir="ltr" autocomplete="off" required
            value="{{ old('amount') ? number_format((int) old('amount')) : '' }}"
            oninput="formatTomanInput(this, '#settlement-amount-raw')"
            class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
        <input type="hidden" id="settlement-amount-raw" name="amount" value="{{ old('amount') }}">
        @error('amount')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror

        <label class="mb-1.5 mt-4 block text-sm font-medium text-gray-700 dark:text-gray-400">واریز به حساب</label>
        <select name="bank_account_id" required class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            <option value="">انتخاب کنید…</option>
            @foreach ($bankAccounts as $account)
                <option value="{{ $account->id }}" @selected(old('bank_account_id') == $account->id)>{{ $account->name }}</option>
            @endforeach
        </select>
        @error('bank_account_id')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror

        <div class="mt-5 flex justify-end gap-3">
            <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300">انصراف</button>
            <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">ثبت تسویه</button>
        </div>
    </form>
</x-ui.modal>
