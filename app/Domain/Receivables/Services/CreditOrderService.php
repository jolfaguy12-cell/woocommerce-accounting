<?php

namespace App\Domain\Receivables\Services;

use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Receivables\Models\BadDebtWriteOff;
use App\Domain\Receivables\Models\CreditOrder;
use App\Domain\Receivables\Models\CreditOrderSettlement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreditOrderService
{
    private const AR = '1200';

    private const BAD_DEBT_EXPENSE = '6400';

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly CreditOrderAllocator $allocator,
    ) {}

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

    /**
     * Manual "forgive" of part of what a customer owes — settles their oldest
     * open orders first (same allocator as a real payment), but posts Dr bad
     * debt expense / Cr AR instead of Dr bank, since no cash actually arrived.
     * Caps automatically at whatever AR is genuinely open, even if the
     * caller's own validation somehow lets a larger amount through.
     */
    public function writeOff(Party $party, int $amount, string $description, ?int $by = null): BadDebtWriteOff
    {
        return DB::transaction(function () use ($party, $amount, $description, $by) {
            $allocation = $this->allocator->apply($party, $amount);

            if ($allocation['applied'] <= 0) {
                throw new InvalidArgumentException('این مشتری مانده بدهکاری بازی برای سوخت کردن ندارد.');
            }

            $writeOff = BadDebtWriteOff::create([
                'uuid' => (string) Str::uuid(),
                'party_id' => $party->id,
                'amount' => $allocation['applied'],
                'description' => $description,
                'created_by' => $by,
            ]);

            $entry = $this->poster->post([
                'entry_date' => Carbon::now(JalaliPeriod::TIMEZONE),
                'description' => "سوخت مطالبات: {$description} — {$party->name}",
                'idempotency_key' => "bad_debt_write_off:{$writeOff->uuid}",
                'source' => $writeOff,
                'created_by' => $by,
            ], [
                ['account' => self::BAD_DEBT_EXPENSE, 'debit' => $allocation['applied']],
                ['account' => self::AR, 'credit' => $allocation['applied'], 'party_id' => $party->id],
            ]);

            foreach ($allocation['lines'] as $line) {
                CreditOrderSettlement::create([
                    'credit_order_id' => $line['credit_order']->id,
                    'source_type' => $writeOff->getMorphClass(),
                    'source_id' => $writeOff->id,
                    'amount' => $line['amount'],
                ]);
            }

            $writeOff->update(['journal_entry_id' => $entry->id]);

            return $writeOff->load('journalEntry.lines', 'settlements.creditOrder');
        });
    }
}
