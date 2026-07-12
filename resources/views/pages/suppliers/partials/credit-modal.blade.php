{{-- Manual "retained balance" credit: SupplierCreditService::recordManualCredit() (SupplierController::storeCredit). --}}
<div x-data="{ open: false }" @open-credit-supplier-modal.window="open = true">
    <x-ui.modal :isOpen="$errors->has('description')" @open-credit-supplier-modal.window="open = true" class="max-w-sm p-6">
        <h4 class="mb-4 text-lg font-semibold text-gray-800 dark:text-white/90">ثبت اعتبار دستی برای {{ $supplier->name }}</h4>

        <form method="POST" action="{{ route('suppliers.credit', $supplier) }}" class="space-y-4">
            @csrf

            <div>
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">مبلغ (تومان)</label>
                <input type="number" name="amount" min="1" required dir="ltr" value="{{ old('amount') }}"
                    class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                @error('amount')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">دلیل / توضیح</label>
                <input type="text" name="description" required value="{{ old('description') }}"
                    class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                @error('description')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
                <p class="mt-1 text-xs text-gray-400">برای مواردی که نه بازگشت کالاست نه بازپرداخت نقدی — مثلاً انتقال مانده افتتاحیه یا اعتبار توافقی با تامین‌کننده.</p>
            </div>

            <div class="flex justify-end gap-3">
                <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300">انصراف</button>
                <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">ثبت اعتبار</button>
            </div>
        </form>
    </x-ui.modal>
</div>
