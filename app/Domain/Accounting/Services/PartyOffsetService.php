<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Models\PartyOffset;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Accounting\Support\OperationPolicy;
use App\Domain\Accounting\Support\OperationStatus;
use App\Domain\Accounting\Support\PartyOffsetType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Netting two balances the SAME party holds — no cash, one balanced entry.
 *
 * The two guards that make this safe rather than dangerous:
 *
 *  1. The combination is a PartyOffsetType, never an arbitrary account pair. Two
 *     arbitrary accounts always balance; that is what "balanced" means. It says
 *     nothing about whether the entry is *true*.
 *  2. The amount can exceed NEITHER side's balance. Offsetting more than exists
 *     does not settle a debt — it invents one in the opposite direction, and the
 *     party is left owing us money they never owed.
 */
class PartyOffsetService extends FinancialOperationService
{
    public function __construct(
        JournalPoster $poster,
        OperationPolicy $policy,
        private readonly PartyLedgerService $ledger,
    ) {
        parent::__construct($poster, $policy);
    }

    /**
     * $data: party, type, amount, offset_date, reason
     *        [reference, notes, created_by]
     */
    public function create(array $data): PartyOffset
    {
        /** @var Party $party */
        $party = $data['party'];
        $type = $data['type'] instanceof PartyOffsetType
            ? $data['type']
            : PartyOffsetType::from($data['type']);
        $amount = (int) $data['amount'];

        $this->assertOffsettable($party, $type, $amount);

        $date = $data['offset_date'] instanceof Carbon
            ? $data['offset_date']
            : Carbon::parse($data['offset_date'], JalaliPeriod::TIMEZONE);

        return DB::transaction(function () use ($data, $party, $type, $amount, $date) {
            $offset = PartyOffset::create([
                'uuid' => (string) Str::uuid(),
                'status' => OperationStatus::Draft->value,
                'party_id' => $party->id,
                'type' => $type,
                'amount' => $amount,
                'offset_date' => $date->toDateString(),
                'jalali_period' => JalaliPeriod::fromDate($date),
                'reason' => $data['reason'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? null,
            ]);

            return $this->finalizeCreation($offset, $data['created_by'] ?? null);
        });
    }

    /** What this party could legitimately offset right now, per type. @return array<string, int> */
    public function eligibleAmounts(Party $party): array
    {
        $eligible = [];

        foreach (PartyOffsetType::cases() as $type) {
            $eligible[$type->value] = $this->cap($party, $type);
        }

        return $eligible;
    }

    /** The most that can be offset: whichever of the two balances runs out first. */
    public function cap(Party $party, PartyOffsetType $type): int
    {
        [$debitSide, $creditSide] = $type->capAccounts();

        return max(0, min(
            $this->ledger->balanceOn($party, $debitSide),
            $this->ledger->balanceOn($party, $creditSide),
        ));
    }

    private function assertOffsettable(Party $party, PartyOffsetType $type, int $amount): void
    {
        if ($amount < 1) {
            throw new InvalidArgumentException('مبلغ تهاتر باید بزرگ‌تر از صفر باشد.');
        }

        $cap = $this->cap($party, $type);

        if ($cap === 0) {
            throw new InvalidArgumentException(
                "برای «{$party->name}» در ترکیب «{$type->label()}» مانده‌ای برای تهاتر وجود ندارد."
            );
        }

        if ($amount > $cap) {
            throw new InvalidArgumentException(
                'مبلغ تهاتر بیشتر از مانده قابل تهاتر است: حداکثر '.number_format($cap).' تومان. '
                .'تهاتر بیش از مانده، بدهی را تسویه نمی‌کند بلکه بدهی معکوس می‌سازد.'
            );
        }
    }

    protected function lines(Model $operation): array
    {
        /** @var PartyOffset $operation */
        $type = $operation->type;

        // Both legs carry the SAME party_id — that is what makes this an offset and
        // not a transfer of one person's debt onto another, and it is what puts both
        // legs in this party's unified statement.
        return [
            [
                'account' => $type->debitAccount(),
                'debit' => $operation->amount,
                'party_id' => $operation->party_id,
                'memo' => "تهاتر: {$operation->reason}",
            ],
            [
                'account' => $type->creditAccount(),
                'credit' => $operation->amount,
                'party_id' => $operation->party_id,
                'memo' => "تهاتر: {$operation->reason}",
            ],
        ];
    }

    protected function description(Model $operation): string
    {
        /** @var PartyOffset $operation */
        $operation->loadMissing('party');

        return "تهاتر {$operation->type->label()} — {$operation->party->name}";
    }

    protected function idempotencyKey(Model $operation): string
    {
        return "party_offset:{$operation->uuid}";
    }

    protected function entryDate(Model $operation): Carbon
    {
        return Carbon::parse($operation->offset_date, JalaliPeriod::TIMEZONE);
    }

    /** An offset moves no cash, so it can never overdraw a bank account. */
    protected function outflows(Model $operation): array
    {
        return [];
    }
}
