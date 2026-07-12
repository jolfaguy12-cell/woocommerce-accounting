{{--
    Inline editable note for a PartyPayment, shared between the supplier
    transactions tab and the bank-account ("Account Management") ledger — both
    already display the same PartyPayment-linked journal lines. Expects
    $payment (a PartyPayment). The edit itself is logged via
    PartyPayment::getActivitylogOptions(), so history is preserved even though
    this control only ever shows the current value.
--}}
<div x-data="{ editing: false }">
    <div x-show="!editing" class="flex items-center gap-1.5">
        <span>{{ $payment->note ?? '—' }}</span>
        <button type="button" @click="editing = true" class="text-theme-xs text-brand-500 hover:underline">ویرایش</button>
    </div>
    <form x-show="editing" x-cloak method="POST" action="{{ route('party-payments.notes.update', $payment) }}" class="flex items-center gap-1.5">
        @csrf
        @method('PUT')
        <input type="text" name="note" value="{{ $payment->note }}" maxlength="500"
            class="h-8 w-40 rounded-md border border-gray-300 px-2 text-theme-xs text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
        <button type="submit" class="text-theme-xs text-brand-500 hover:underline">ذخیره</button>
        <button type="button" @click="editing = false" class="text-theme-xs text-gray-400 hover:underline">لغو</button>
    </form>
    @if ($payment->updated_by && $payment->updated_by !== $payment->created_by)
        <p class="mt-0.5 text-theme-xs text-gray-400">آخرین ویرایش: {{ $payment->editor->name ?? '—' }}</p>
    @endif
</div>
