<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Exceptions\ImmutableJournalException;
use App\Domain\Accounting\Exceptions\PeriodLockedException;
use App\Domain\Accounting\Exceptions\UnbalancedEntryException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Support\JalaliPeriod;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class JournalPoster
{
    /**
     * Post a balanced, idempotent journal entry.
     *
     * $data: entry_date (Carbon), description, idempotency_key,
     *        [source (Model), correlation_id, created_by, allow_soft_closed (bool)]
     * $lines: each ['account' => Account|id|code, 'debit'|'credit' => int Toman,
     *        'party_id', 'cost_center_id', 'memo']
     */
    public function post(array $data, array $lines): JournalEntry
    {
        $normalized = $this->normalizeLines($lines);
        $this->assertBalanced($normalized);

        if ($existing = JournalEntry::firstWhere('idempotency_key', $data['idempotency_key'])) {
            return $existing;
        }

        $entryDate = $data['entry_date'] instanceof Carbon
            ? $data['entry_date']
            : Carbon::parse($data['entry_date'], JalaliPeriod::TIMEZONE);

        $period = AccountingPeriod::forDate($entryDate);
        $this->assertPeriodOpen($period, (bool) ($data['allow_soft_closed'] ?? false));

        try {
            return DB::transaction(function () use ($data, $normalized, $entryDate, $period) {
                $entry = JournalEntry::create([
                    'uuid' => (string) Str::uuid(),
                    'entry_date' => $entryDate->toDateString(),
                    'jalali_period' => $period->jalali_period,
                    'description' => $data['description'],
                    'status' => 'posted',
                    'source_type' => isset($data['source']) ? $data['source']->getMorphClass() : null,
                    'source_id' => isset($data['source']) ? $data['source']->getKey() : null,
                    'correlation_id' => $data['correlation_id'] ?? null,
                    'idempotency_key' => $data['idempotency_key'],
                    'reversal_of_entry_id' => $data['reversal_of_entry_id'] ?? null,
                    'created_by' => $data['created_by'] ?? null,
                ]);

                $entry->lines()->createMany($normalized);

                return $entry->load('lines');
            });
        } catch (QueryException $e) {
            // Lost an idempotency race: another process posted the same key first.
            if ($existing = JournalEntry::firstWhere('idempotency_key', $data['idempotency_key'])) {
                return $existing;
            }
            throw $e;
        }
    }

    /**
     * Reverse a posted entry with an opposing entry. The reversal is dated
     * $date (default: now) so post-lock corrections land in the current
     * open period; the original is only flagged, never mutated financially.
     */
    public function reverse(JournalEntry $entry, string $reason, ?int $createdBy = null, ?Carbon $date = null): JournalEntry
    {
        if ($entry->isReversed()) {
            throw new ImmutableJournalException("Entry {$entry->uuid} is already reversed.");
        }

        $lines = $entry->lines->map(fn ($line) => [
            'account' => $line->account_id,
            'debit' => $line->credit,
            'credit' => $line->debit,
            'party_id' => $line->party_id,
            'cost_center_id' => $line->cost_center_id,
            'memo' => $line->memo,
        ])->all();

        $reversal = $this->post([
            'entry_date' => $date ?? Carbon::now(JalaliPeriod::TIMEZONE),
            'description' => "برگشت: {$reason} (سند {$entry->uuid})",
            'idempotency_key' => "reverse:{$entry->uuid}",
            'correlation_id' => $entry->correlation_id,
            'reversal_of_entry_id' => $entry->id,
            'created_by' => $createdBy,
        ], $lines);

        $entry->forceFill([
            'status' => 'reversed',
            'reversed_by_entry_id' => $reversal->id,
        ])->save();

        return $reversal;
    }

    private function normalizeLines(array $lines): array
    {
        if (count($lines) < 2) {
            throw new UnbalancedEntryException('A journal entry needs at least two lines.');
        }

        return array_map(function (array $line) {
            $debit = (int) ($line['debit'] ?? 0);
            $credit = (int) ($line['credit'] ?? 0);

            if ($debit < 0 || $credit < 0) {
                throw new UnbalancedEntryException('Negative amounts are not allowed; swap debit/credit instead.');
            }
            if (($debit > 0) === ($credit > 0)) {
                throw new UnbalancedEntryException('Each line must have exactly one of debit or credit set.');
            }

            return [
                'account_id' => $this->resolveAccountId($line['account']),
                'debit' => $debit,
                'credit' => $credit,
                'party_id' => $line['party_id'] ?? null,
                'cost_center_id' => $line['cost_center_id'] ?? null,
                'memo' => $line['memo'] ?? null,
            ];
        }, $lines);
    }

    private function assertBalanced(array $normalized): void
    {
        $debits = array_sum(array_column($normalized, 'debit'));
        $credits = array_sum(array_column($normalized, 'credit'));

        if ($debits !== $credits) {
            throw new UnbalancedEntryException("Entry is unbalanced: debits {$debits} != credits {$credits}.");
        }
    }

    private function assertPeriodOpen(AccountingPeriod $period, bool $allowSoftClosed): void
    {
        if ($period->isLocked()) {
            throw new PeriodLockedException("Period {$period->jalali_period} is locked.");
        }
        if ($period->isSoftClosed() && ! $allowSoftClosed) {
            throw new PeriodLockedException("Period {$period->jalali_period} is soft-closed; requires explicit override.");
        }
    }

    private function resolveAccountId(Account|int|string $account): int
    {
        if ($account instanceof Account) {
            return $account->id;
        }
        if (is_int($account)) {
            return $account;
        }

        return Account::where('code', $account)->firstOrFail()->id;
    }
}
