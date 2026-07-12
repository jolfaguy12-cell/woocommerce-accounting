{{-- Supplier refunding money back to us: PaymentRecorder::receiveRefund() (SupplierController::refund). --}}
<div x-data="{ open: false }" @open-refund-supplier-modal.window="open = true">
    <x-ui.modal :isOpen="$errors->has('amount') && old('_form') === 'refund'" @open-refund-supplier-modal.window="open = true" class="max-w-sm p-6">
        <h4 class="mb-4 text-lg font-semibold text-gray-800 dark:text-white/90">دریافت بازپرداخت از {{ $supplier->name }}</h4>

        <form method="POST" action="{{ route('suppliers.refund', $supplier) }}" class="space-y-4">
            @csrf
            <input type="hidden" name="_form" value="refund">

            @include('pages.suppliers.partials.payment-fields', ['bankAccounts' => $bankAccounts, 'bankLabel' => 'واریز به حساب'])

            <div class="flex justify-end gap-3">
                <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300">انصراف</button>
                <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">ثبت بازپرداخت</button>
            </div>
        </form>
    </x-ui.modal>
</div>
