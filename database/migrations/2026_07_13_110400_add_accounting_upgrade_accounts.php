<?php

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Support\AccountCode;
use Illuminate\Database\Migrations\Migration;

/**
 * Chart rows the upgrade needs and that did not exist (same shape as
 * 2026_07_11_120300_add_bad_debt_expense_account.php). Purely additive: nothing
 * posts to these yet — the services that do arrive in later commits.
 *
 * Mirrored in ChartOfAccountsSeeder so a fresh install gets them too.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string}> */
    private array $accounts = [
        AccountCode::SupplierAdvance->value => ['پیش‌پرداخت به تأمین‌کننده', 'asset'],
        AccountCode::LoansReceivable->value => ['تسهیلات اعطایی (وام پرداختی)', 'asset'],
        AccountCode::PartnerProfitPayable->value => ['سود سهم شرکا پرداختنی', 'liability'],
        AccountCode::PartnerCurrentAccount->value => ['حساب جاری شرکا', 'liability'],
        AccountCode::InterestIncome->value => ['سود و کارمزد دریافتی', 'revenue'],
        AccountCode::BankFee->value => ['کارمزد بانکی', 'expense'],
        AccountCode::LatePenalty->value => ['جریمه دیرکرد', 'expense'],
    ];

    public function up(): void
    {
        foreach ($this->accounts as $code => [$name, $type]) {
            Account::firstOrCreate(['code' => $code], [
                'name' => $name,
                'type' => $type,
                'is_system' => true,
            ]);
        }
    }

    public function down(): void
    {
        // Never remove an account that has been posted to — that would orphan journal lines.
        Account::whereIn('code', array_keys($this->accounts))->whereDoesntHave('lines')->delete();
    }
};
