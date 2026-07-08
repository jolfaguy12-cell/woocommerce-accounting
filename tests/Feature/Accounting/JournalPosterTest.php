<?php

use App\Domain\Accounting\Exceptions\ImmutableJournalException;
use App\Domain\Accounting\Exceptions\PeriodLockedException;
use App\Domain\Accounting\Exceptions\UnbalancedEntryException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\JalaliPeriod;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->cash = Account::create(['code' => '1000', 'name' => 'صندوق', 'type' => 'asset']);
    $this->sales = Account::create(['code' => '4000', 'name' => 'فروش', 'type' => 'revenue']);
    $this->poster = app(JournalPoster::class);
});

function draft(array $overrides = []): array
{
    return array_merge([
        'entry_date' => Carbon::parse('2026-07-08', 'Asia/Tehran'),
        'description' => 'فروش نقدی تست',
        'idempotency_key' => 'test:'.uniqid(),
    ], $overrides);
}

it('posts a balanced entry and derives the jalali period', function () {
    $entry = $this->poster->post(draft(), [
        ['account' => $this->cash, 'debit' => 250_000],
        ['account' => $this->sales, 'credit' => 250_000],
    ]);

    expect($entry->status)->toBe('posted')
        ->and($entry->jalali_period)->toBe(JalaliPeriod::fromDate(Carbon::parse('2026-07-08', 'Asia/Tehran')))
        ->and($entry->lines)->toHaveCount(2)
        ->and($entry->lines->sum('debit'))->toBe(250_000)
        ->and($entry->lines->sum('debit'))->toBe($entry->lines->sum('credit'));

    expect(AccountingPeriod::where('jalali_period', $entry->jalali_period)->exists())->toBeTrue();
});

it('rejects an unbalanced entry', function () {
    $this->poster->post(draft(), [
        ['account' => $this->cash, 'debit' => 100_000],
        ['account' => $this->sales, 'credit' => 90_000],
    ]);
})->throws(UnbalancedEntryException::class);

it('rejects a line with both debit and credit set', function () {
    $this->poster->post(draft(), [
        ['account' => $this->cash, 'debit' => 100_000, 'credit' => 100_000],
        ['account' => $this->sales, 'credit' => 0],
    ]);
})->throws(UnbalancedEntryException::class);

it('rejects a zero-total entry', function () {
    $this->poster->post(draft(), [
        ['account' => $this->cash, 'debit' => 0],
        ['account' => $this->sales, 'credit' => 0],
    ]);
})->throws(UnbalancedEntryException::class);

it('returns the existing entry for a duplicate idempotency key without double-posting', function () {
    $key = 'order:123:profit:v1';

    $first = $this->poster->post(draft(['idempotency_key' => $key]), [
        ['account' => $this->cash, 'debit' => 50_000],
        ['account' => $this->sales, 'credit' => 50_000],
    ]);
    $second = $this->poster->post(draft(['idempotency_key' => $key]), [
        ['account' => $this->cash, 'debit' => 999_999],
        ['account' => $this->sales, 'credit' => 999_999],
    ]);

    expect($second->id)->toBe($first->id)
        ->and(JournalEntry::count())->toBe(1);
});

it('refuses to post into a locked period', function () {
    $date = Carbon::parse('2026-07-08', 'Asia/Tehran');
    AccountingPeriod::forDate($date)->update(['status' => 'locked']);

    $this->poster->post(draft(['entry_date' => $date]), [
        ['account' => $this->cash, 'debit' => 10_000],
        ['account' => $this->sales, 'credit' => 10_000],
    ]);
})->throws(PeriodLockedException::class);

it('reverses an entry with opposing lines and marks the original reversed', function () {
    $entry = $this->poster->post(draft(), [
        ['account' => $this->cash, 'debit' => 70_000],
        ['account' => $this->sales, 'credit' => 70_000],
    ]);

    $reversal = $this->poster->reverse($entry, 'برگشت تست');
    $entry->refresh();

    expect($entry->status)->toBe('reversed')
        ->and($entry->reversed_by_entry_id)->toBe($reversal->id)
        ->and($reversal->reversal_of_entry_id)->toBe($entry->id)
        ->and($reversal->lines->firstWhere('account_id', $this->cash->id)->credit)->toBe(70_000)
        ->and($reversal->lines->firstWhere('account_id', $this->sales->id)->debit)->toBe(70_000);
});

it('refuses to reverse an already-reversed entry', function () {
    $entry = $this->poster->post(draft(), [
        ['account' => $this->cash, 'debit' => 70_000],
        ['account' => $this->sales, 'credit' => 70_000],
    ]);
    $this->poster->reverse($entry, 'بار اول');
    $this->poster->reverse($entry->refresh(), 'بار دوم');
})->throws(ImmutableJournalException::class);

it('never allows deleting journal entries or lines', function () {
    $entry = $this->poster->post(draft(), [
        ['account' => $this->cash, 'debit' => 10_000],
        ['account' => $this->sales, 'credit' => 10_000],
    ]);

    expect(fn () => $entry->delete())->toThrow(ImmutableJournalException::class)
        ->and(fn () => $entry->lines->first()->delete())->toThrow(ImmutableJournalException::class);
});
