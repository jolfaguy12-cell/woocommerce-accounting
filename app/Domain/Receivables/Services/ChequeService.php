<?php

namespace App\Domain\Receivables\Services;

use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Expenses\Models\BankAccount;
use App\Domain\Receivables\Models\Cheque;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ChequeService
{
    private const AR = '1200';

    private const CHEQUES_RECEIVABLE = '1250';

    private const AP = '2000';

    private const CHEQUES_PAYABLE = '2100';

    public function __construct(private readonly JournalPoster $poster) {}

    /** Customer cheque received against their AR balance. */
    public function registerReceivable(Party $party, int $amount, Carbon $dueDate, ?string $serial = null): Cheque
    {
        return $this->register('receivable', $party, $amount, $dueDate, $serial,
            [['account' => self::CHEQUES_RECEIVABLE, 'debit' => $amount],
                ['account' => self::AR, 'credit' => $amount, 'party_id' => $party->id]]);
    }

    /** Our cheque handed to a supplier against AP. */
    public function registerPayable(Party $party, int $amount, Carbon $dueDate, ?string $serial = null): Cheque
    {
        return $this->register('payable', $party, $amount, $dueDate, $serial,
            [['account' => self::AP, 'debit' => $amount, 'party_id' => $party->id],
                ['account' => self::CHEQUES_PAYABLE, 'credit' => $amount, 'party_id' => $party->id]]);
    }

    public function clear(Cheque $cheque, int $bankAccountId): void
    {
        $this->assertPending($cheque);

        $bankLedger = BankAccount::findOrFail($bankAccountId)->account_id;
        $lines = $cheque->direction === 'receivable'
            ? [['account' => $bankLedger, 'debit' => $cheque->amount],
                ['account' => self::CHEQUES_RECEIVABLE, 'credit' => $cheque->amount]]
            : [['account' => self::CHEQUES_PAYABLE, 'debit' => $cheque->amount, 'party_id' => $cheque->party_id],
                ['account' => $bankLedger, 'credit' => $cheque->amount]];

        $this->settle($cheque, 'cleared', 'وصول چک', $lines);
    }

    public function bounce(Cheque $cheque): void
    {
        $this->assertPending($cheque);

        $lines = $cheque->direction === 'receivable'
            ? [['account' => self::AR, 'debit' => $cheque->amount, 'party_id' => $cheque->party_id],
                ['account' => self::CHEQUES_RECEIVABLE, 'credit' => $cheque->amount]]
            : [['account' => self::CHEQUES_PAYABLE, 'debit' => $cheque->amount, 'party_id' => $cheque->party_id],
                ['account' => self::AP, 'credit' => $cheque->amount, 'party_id' => $cheque->party_id]];

        $this->settle($cheque, 'bounced', 'برگشت چک', $lines);
    }

    private function register(string $direction, Party $party, int $amount, Carbon $dueDate, ?string $serial, array $lines): Cheque
    {
        return DB::transaction(function () use ($direction, $party, $amount, $dueDate, $serial, $lines) {
            $cheque = Cheque::create([
                'uuid' => (string) Str::uuid(),
                'direction' => $direction,
                'party_id' => $party->id,
                'amount' => $amount,
                'due_date' => $dueDate->toDateString(),
                'serial' => $serial,
                'status' => 'pending',
            ]);

            $entry = $this->poster->post([
                'entry_date' => Carbon::now(JalaliPeriod::TIMEZONE),
                'description' => ($direction === 'receivable' ? 'دریافت چک از ' : 'صدور چک برای ').$party->name,
                'idempotency_key' => "cheque:{$cheque->uuid}",
                'source' => $cheque,
            ], $lines);

            $cheque->update(['journal_entry_id' => $entry->id]);

            return $cheque;
        });
    }

    private function settle(Cheque $cheque, string $status, string $label, array $lines): void
    {
        DB::transaction(function () use ($cheque, $status, $label, $lines) {
            $entry = $this->poster->post([
                'entry_date' => Carbon::now(JalaliPeriod::TIMEZONE),
                'description' => "{$label} {$cheque->serial} — {$cheque->party->name}",
                'idempotency_key' => "cheque:{$cheque->uuid}:{$status}",
                'source' => $cheque,
            ], $lines);

            $cheque->update(['status' => $status, 'settlement_entry_id' => $entry->id]);
        });
    }

    private function assertPending(Cheque $cheque): void
    {
        if ($cheque->status !== 'pending') {
            throw new InvalidArgumentException("Cheque {$cheque->uuid} is already {$cheque->status}.");
        }
    }
}
