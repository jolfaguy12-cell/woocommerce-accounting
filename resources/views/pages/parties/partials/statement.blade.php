{{--
    The complete statement: every journal line carrying this party_id, across
    every account. The one view a single-role page could never produce — a party
    that both buys and supplies had two separate ledgers and no shared history.
--}}
<x-common.component-card title="گردش کامل حساب">
    <x-tables.pro-table
        :columns="[
            ['key' => 'date', 'label' => 'تاریخ'],
            ['key' => 'description', 'label' => 'شرح سند'],
            ['key' => 'account', 'label' => 'حساب'],
            ['key' => 'debit', 'label' => 'بدهکار', 'align' => 'right'],
            ['key' => 'credit', 'label' => 'بستانکار', 'align' => 'right'],
        ]"
        :paginator="$statement"
        :query="$statementQuery"
        :searchValue="request('search')"
        searchPlaceholder="جستجو در شرح سند یا نام حساب"
        emptyMessage="هیچ سندی برای این طرف حساب ثبت نشده است"
        storageKey="parties.statement.visibleColumns"
    >
        @foreach ($statement as $line)
            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                <x-tables.ltr x-show="visible.date" :value="$line->jalali_date" tone="muted" />
                <td x-show="visible.description" class="px-5 py-3 text-theme-sm text-gray-800 sm:px-6 dark:text-white/90">
                    {{ $line->entry->description }}
                </td>
                <td x-show="visible.account" class="px-5 py-3 text-theme-sm text-gray-600 sm:px-6 dark:text-gray-300">
                    {{ $line->account->name }}
                </td>
                <x-tables.num x-show="visible.debit" :value="$line->debit" type="toman" :zero="'—'" />
                <x-tables.num x-show="visible.credit" :value="$line->credit" type="toman" :zero="'—'" />
            </tr>
        @endforeach
    </x-tables.pro-table>
</x-common.component-card>
