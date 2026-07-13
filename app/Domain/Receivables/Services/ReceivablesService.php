<?php

namespace App\Domain\Receivables\Services;

use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\PartyLedgerService;
use App\Domain\Receivables\Models\CreditOrder;
use Illuminate\Support\Carbon;

class ReceivablesService
{
    public function __construct(private readonly PartyLedgerService $ledger) {}

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
        return $this->ledger->customerCredit($party);
    }

    /** This party's total open receivable balance — computed in PHP via remaining() so the clamp semantics never drift from CreditOrder's own. */
    public function partyOpenBalance(Party $party): int
    {
        return (int) CreditOrder::where('party_id', $party->id)->where('status', 'open')->get()
            ->sum(fn (CreditOrder $c) => $c->remaining());
    }

    /** >0: the customer owes the store (debtor). <0: the store owes the customer (creditor, held credit exceeds open debt). 0: settled. */
    public function partyNetBalance(Party $party): int
    {
        return $this->partyOpenBalance($party) - $this->customerCreditBalance($party);
    }
}
