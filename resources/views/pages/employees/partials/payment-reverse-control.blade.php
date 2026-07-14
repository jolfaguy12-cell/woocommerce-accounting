{{--
    Inline reversal control for a PartyPayment — the salary-payment analogue of
    suppliers/partials/note-edit-control.blade.php's inline-toggle pattern.

    A posted payment is immutable; this is its ONE correction path (PaymentRecorder::reverse).
    The original row and its entry stay exactly as posted — an opposing entry
    cancels the money — so every balance that counted this payment un-counts it
    automatically once reversed. Expects $payment (a PartyPayment).
--}}
@if ($payment->isReversed())
    <span class="text-theme-xs text-gray-400">
        برگشت‌خورده — {{ $payment->reverser?->name ?? '—' }}
    </span>
@else
    <div x-data="{ reversing: false }">
        <button type="button" @click="reversing = true" x-show="!reversing"
            class="text-theme-xs text-error-500 hover:underline">برگشت</button>
        <form x-show="reversing" x-cloak method="POST" action="{{ route('party-payments.reverse', $payment) }}"
            class="flex items-center gap-1.5">
            @csrf
            <input type="text" name="reason" placeholder="دلیل برگشت" required
                class="h-8 w-40 rounded-md border border-gray-300 px-2 text-theme-xs text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            <button type="submit" class="text-theme-xs text-error-500 hover:underline">تأیید</button>
            <button type="button" @click="reversing = false" class="text-theme-xs text-gray-400 hover:underline">لغو</button>
        </form>
    </div>
@endif
