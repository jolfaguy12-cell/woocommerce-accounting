{{--
    One shared modal for both "add supplier" (dispatch open-supplier-modal with
    no payload) and "edit supplier" (dispatch it with the supplier's fields) —
    used from both the suppliers list and the supplier detail page header.
--}}
<div x-data="{ mode: 'create', record: {} }"
    @open-supplier-modal.window="mode = $event.detail ? 'edit' : 'create'; record = $event.detail || {}">
    <x-ui.modal :isOpen="$errors->any()" @open-supplier-modal.window="open = true" class="max-w-lg p-6">
        <h4 class="mb-4 text-lg font-semibold text-gray-800 dark:text-white/90" x-text="mode === 'edit' ? 'ویرایش تامین‌کننده' : 'تامین‌کننده جدید'"></h4>

        <form method="POST" :action="mode === 'edit' ? `{{ url('suppliers') }}/${record.id}` : '{{ route('suppliers.store') }}'" class="space-y-4">
            @csrf
            <template x-if="mode === 'edit'">
                <input type="hidden" name="_method" value="PUT">
            </template>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">نام و نام خانوادگی</label>
                    <input type="text" name="name" required :value="record.name"
                        class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    @error('name')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">نام فروشگاه (اختیاری)</label>
                    <input type="text" name="shop_name" :value="record.shop_name"
                        class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    @error('shop_name')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">شماره تلفن (اختیاری)</label>
                    <input type="text" name="phone" dir="ltr" :value="record.phone"
                        class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    @error('phone')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">ایمیل (اختیاری)</label>
                    <input type="email" name="email" dir="ltr" :value="record.email"
                        class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    @error('email')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">شماره حساب (اختیاری)</label>
                    <input type="text" name="bank_account_number" dir="ltr" :value="record.bank_account_number"
                        class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    @error('bank_account_number')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
                </div>

                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">آدرس (اختیاری)</label>
                    <input type="text" name="address" :value="record.address"
                        class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    @error('address')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
                </div>

                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">یادداشت (اختیاری)</label>
                    <textarea name="notes" rows="2" :value="record.notes"
                        class="w-full rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"></textarea>
                    @error('notes')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="flex justify-end gap-3">
                <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300">انصراف</button>
                <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">ذخیره</button>
            </div>
        </form>
    </x-ui.modal>
</div>
