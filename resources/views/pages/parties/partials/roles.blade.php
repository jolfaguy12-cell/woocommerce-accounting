@php
    use App\Domain\Accounting\Support\PartyRoleType;

    $roleRows = $party->roles->keyBy('role');
@endphp

{{--
    Role management. Deactivating a role never deletes the party or any of its
    history — the row stays, flagged inactive, so "was a supplier from X to Y"
    survives and every journal line keeps pointing at the same party.
--}}
<x-common.component-card title="مدیریت نقش‌ها"
    desc="یک طرف حساب می‌تواند هم‌زمان چند نقش داشته باشد. غیرفعال کردن یک نقش هیچ سند مالی یا سابقه‌ای را حذف نمی‌کند.">

    <div class="overflow-x-auto">
        <table class="w-full min-w-max">
            <thead class="border-b border-gray-200 dark:border-gray-800">
                <tr>
                    <th class="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 sm:px-6 dark:text-gray-400">نقش</th>
                    <th class="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 sm:px-6 dark:text-gray-400">وضعیت</th>
                    <th class="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 sm:px-6 dark:text-gray-400">تاریخ فعال‌سازی</th>
                    <th class="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 sm:px-6 dark:text-gray-400">تاریخ غیرفعال‌سازی</th>
                    <th class="px-5 py-3 text-end text-theme-xs font-medium text-gray-500 sm:px-6 dark:text-gray-400">عملیات</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($allRoles as $role)
                    @php
                        $row = $roleRows->get($role->value);
                        $isActive = (bool) ($row?->is_active);
                    @endphp
                    <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                        <td class="px-5 py-3 text-theme-sm font-medium text-gray-800 sm:px-6 dark:text-white/90">{{ $role->label() }}</td>
                        <td class="px-5 py-3 sm:px-6">
                            <x-ui.status :status="$isActive ? 'approved' : 'archived'" :label="$isActive ? 'فعال' : 'غیرفعال'" />
                        </td>
                        <x-tables.ltr :value="$row?->activated_at ? \App\Domain\Accounting\Support\JalaliPeriod::fmtDateTime($row->activated_at) : null" tone="muted" />
                        <x-tables.ltr :value="$row?->deactivated_at ? \App\Domain\Accounting\Support\JalaliPeriod::fmtDateTime($row->deactivated_at) : null" tone="muted" />
                        <td class="px-5 py-3 text-end sm:px-6">
                            <form method="POST"
                                action="{{ $isActive ? route('parties.roles.deactivate', $party) : route('parties.roles.activate', $party) }}"
                                class="inline">
                                @csrf
                                <input type="hidden" name="role" value="{{ $role->value }}">
                                <button type="submit"
                                    @class([
                                        'inline-flex h-8 items-center rounded-md px-3 text-theme-sm font-medium',
                                        'border border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5' => $isActive,
                                        'bg-brand-500 text-white hover:bg-brand-600' => ! $isActive,
                                    ])>
                                    {{ $isActive ? 'غیرفعال کردن' : 'فعال کردن' }}
                                </button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-common.component-card>
