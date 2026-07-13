{{--
    Duplicate REVIEW. Suggestions only — nothing here merges anything, and no
    merge action exists. A shared phone, email or Telegram id is evidence for a
    human to weigh, never proof that two parties are the same person.
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
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-common.component-card>
