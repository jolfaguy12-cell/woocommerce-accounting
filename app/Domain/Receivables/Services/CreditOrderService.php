<?php

namespace App\Domain\Receivables\Services;

use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Receivables\Models\CreditOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreditOrderService
{
    public function __construct(private readonly JournalPoster $poster) {}

    /** Manual credit sale (goods now, money later): Dr AR / Cr sales. */
    public function openManual(Party $party, int $amount, string $description, ?Carbon $dueDate = null, ?int $by = null): CreditOrder
    {
        return DB::transaction(function () use ($party, $amount, $description, $dueDate, $by) {
            $credit = CreditOrder::create([
                'uuid' => (string) Str::uuid(),
                'party_id' => $party->id,
                'total_due' => $amount,
                'due_date' => $dueDate?->toDateString(),
                'description' => $description,
                'created_by' => $by,
            ]);

            $entry = $this->poster->post([
                'entry_date' => Carbon::now(JalaliPeriod::TIMEZONE),
                'description' => "فروش اعتباری: {$description} — {$party->name}",
                'idempotency_key' => "credit_order:{$credit->uuid}",
                'source' => $credit,
                'created_by' => $by,
            ], [
                ['account' => '1200', 'debit' => $amount, 'party_id' => $party->id],
                ['account' => '4000', 'credit' => $amount],
            ]);

            $credit->update(['journal_entry_id' => $entry->id]);

            return $credit->load('journalEntry.lines');
        });
    }
}
