<?php

namespace App\Domain\Channels\Services;

use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Channels\Models\Channel;
use App\Domain\Channels\Models\ChannelCost;
use App\Domain\Expenses\Models\BankAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChannelCostRecorder
{
    private const CHANNEL_FEE_ACCOUNT = '5200';

    public function __construct(private readonly JournalPoster $poster) {}

    /** Record a wallet top-up or manual period cost and post its journal entry. */
    public function record(Channel $channel, string $type, int $amount, Carbon $occurredAt, ?int $bankAccountId = null, ?string $note = null, ?int $by = null): ChannelCost
    {
        return DB::transaction(function () use ($channel, $type, $amount, $occurredAt, $bankAccountId, $note, $by) {
            $cost = ChannelCost::create([
                'uuid' => (string) Str::uuid(),
                'channel_id' => $channel->id,
                'jalali_period' => JalaliPeriod::fromDate($occurredAt),
                'type' => $type,
                'amount' => $amount,
                'occurred_at' => $occurredAt->toDateString(),
                'bank_account_id' => $bankAccountId,
                'note' => $note,
                'created_by' => $by,
            ]);

            $creditAccount = $bankAccountId
                ? BankAccount::findOrFail($bankAccountId)->account_id
                : '2000'; // unpaid manual cost → payable

            $entry = $this->poster->post([
                'entry_date' => $occurredAt,
                'description' => "هزینه کانال {$channel->name}".($note ? " — {$note}" : ''),
                'idempotency_key' => "channel_cost:{$cost->uuid}",
                'source' => $cost,
                'created_by' => $by,
            ], [
                ['account' => self::CHANNEL_FEE_ACCOUNT, 'debit' => $amount],
                ['account' => $creditAccount, 'credit' => $amount],
            ]);

            $cost->update(['journal_entry_id' => $entry->id]);

            return $cost->load('journalEntry.lines');
        });
    }
}
