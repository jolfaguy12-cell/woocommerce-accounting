<?php

use App\Domain\Accounting\Models\Account;
use Illuminate\Database\Migrations\Migration;

/**
 * «سود (زیان) انباشته» — the account a declared profit share is taken OUT of.
 *
 * Commit 5 debited Capital (3000) when declaring a partner's profit share, for
 * the simple reason that the chart had nowhere else to put it. That is wrong, and
 * wrong in a way that quietly destroys the one number a partner actually cares
 * about: capital is what they PUT IN, and distributing profit does not give any of
 * it back. Debiting 3000 makes a partner who has been paid their earnings look as
 * though they had progressively withdrawn their stake — after enough profitable
 * years, a founder who invested 100m and was paid 100m of earnings reads as owning
 * nothing at all.
 *
 * Earnings accumulate here instead. Declaring a share moves it from retained
 * earnings (equity the company has built up) to 2500 (a debt owed to the partner);
 * the stake in 3000 is never touched.
 *
 * Existing equity codes: 3000 capital, 3100 partner drawings (contra). 3200 keeps
 * the equity block contiguous. Additive and idempotent, matching
 * 2026_07_11_120300_add_bad_debt_expense_account.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Account::firstOrCreate(['code' => '3200'], [
            'name' => 'سود (زیان) انباشته',
            'type' => 'equity',
            'is_system' => true,
        ]);
    }

    public function down(): void
    {
        // Only if nothing was ever posted to it — a used account is history and
        // history is never deleted.
        $account = Account::where('code', '3200')->first();

        if ($account && ! $account->lines()->exists()) {
            $account->delete();
        }
    }
};
