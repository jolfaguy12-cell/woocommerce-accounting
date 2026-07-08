<?php

namespace App\Domain\Receivables\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\Party;
use App\Domain\Receivables\Models\CreditOrder;
use Illuminate\Support\Carbon;

class ReceivablesService
{
    /** Open credit-order balances per party, with overdue split (by due_date). */
    public function aging(): array
    {
        $today = Carbon::now('Asia/Tehran')->startOfDay();

        return CreditOrder::where('status', 'open')
            ->get()
            ->groupBy('party_id')
            ->map(function ($orders, $partyId) use ($today) {
                $overdue = $orders->filter(fn ($o) => $o->due_date && $o->due_date->lt($today));

                return [
                    'party_id' => $partyId,
                    'party_name' => $orders->first()->party->name,
                    'total_due' => (int) $orders->sum(fn ($o) => $o->remaining()),
                    'overdue' => (int) $overdue->sum(fn ($o) => $o->remaining()),
                    'oldest_due_date' => $orders->min('due_date')?->toDateString(),
                ];
            })
            ->values()
            ->all();
    }

    /** Credit a customer holds with us (liability 2400 balance for the party). */
    public function customerCreditBalance(Party $party): int
    {
        $account = Account::firstWhere('code', '2400');

        $lines = $account->lines()->where('party_id', $party->id);

        return (int) $lines->sum('credit') - (int) $lines->sum('debit');
    }
}
