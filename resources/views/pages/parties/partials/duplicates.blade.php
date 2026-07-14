{{--
    Duplicate review, now with a merge action behind it.

    Nothing merges automatically and nothing merges without a stated reason: a
    shared phone, email or Telegram id is evidence for a human to weigh, never
    proof that two parties are the same person — a household shares a line, and
    one person uses three numbers. The merge itself rewrites no journal line (see
    PartyMergeService); it records that two ids are one identity and aggregates
    them here.
--}}
<x-common.component-card title="موارد احتمالی تکراری"
    desc="این موارد بر اساس شناسه‌های مشترک پیشنهاد شده‌اند و به‌طور خودکار ادغام نمی‌شوند.">

    @if ($duplicateMatches->isEmpty())
        <x-states.state variant="empty"
            title="موردی یافت نشد"
            message="طرف حساب دیگری با شناسه‌های مشترک با این پرونده پیدا نشد." />
    @else
        <div class="overflow-x-auto">
            <table class="w-full min-w-max">
                <thead class="border-b border-gray-200 dark:border-gray-800">
                    <tr>
                        <th class="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 sm:px-6 dark:text-gray-400">طرف حساب</th>
                        <th class="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 sm:px-6 dark:text-gray-400">دلیل</th>
                        <th class="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 sm:px-6 dark:text-gray-400">اعتبار نشانه</th>
                        <th class="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 sm:px-6 dark:text-gray-400">نقش‌ها</th>
                        @if ($canMerge)
                            <th class="px-5 py-3 text-end text-theme-xs font-medium text-gray-500 sm:px-6 dark:text-gray-400">ادغام طرف حساب‌ها</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($duplicateMatches as $match)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                            <td class="px-5 py-3 sm:px-6">
                                <a href="{{ route('parties.show', $match['party']) }}" class="font-medium text-brand-500 hover:underline">{{ $match['party']->name }}</a>
                            </td>
                            <td class="px-5 py-3 text-theme-sm text-gray-600 sm:px-6 dark:text-gray-300">{{ $match['reason'] }}</td>
                            <td class="px-5 py-3 sm:px-6">
                                <x-ui.status :status="$match['strength'] === 'strong' ? 'needs_review' : 'pending'"
                                    :label="$match['strength'] === 'strong' ? 'نشانه قوی' : 'نشانه ضعیف'" />
                            </td>
                            <td class="px-5 py-3 sm:px-6">
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($match['party']->roles->where('is_active', true) as $role)
                                        <x-ui.badge color="light" size="sm">{{ \App\Domain\Accounting\Support\PartyRoleType::coerce($role->role)->label() }}</x-ui.badge>
                                    @endforeach
                                </div>
                            </td>
                            @if ($canMerge)
                                {{-- Merges the OTHER party into this one. The reason is required,
                                     stored on the alias and activity-logged — a merge you cannot
                                     explain later is a merge nobody can audit. --}}
                                <td class="px-5 py-3 sm:px-6">
                                    <form method="POST" action="{{ route('parties.merge', $party) }}"
                                        class="flex items-center justify-end gap-2"
                                        onsubmit="return confirm('«{{ $match['party']->name }}» در این پرونده ادغام شود؟ هیچ سند حسابداری حذف یا تغییر نمی‌کند.')">
                                        @csrf
                                        <input type="hidden" name="merged_party_id" value="{{ $match['party']->id }}">
                                        <input type="text" name="reason" required maxlength="255" placeholder="دلیل ادغام"
                                            class="h-8 w-44 rounded-md border border-gray-300 bg-white px-2 text-theme-xs text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                                        <button type="submit"
                                            class="h-8 shrink-0 rounded-md bg-brand-500 px-3 text-theme-xs font-medium text-white hover:bg-brand-600">
                                            ادغام
                                        </button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-common.component-card>
