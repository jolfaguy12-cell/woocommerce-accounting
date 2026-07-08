<?php

namespace App\Domain\Expenses\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Expenses\Models\BankAccount;
use Illuminate\Support\Facades\DB;

class BankAccountManager
{
    /** Create a bank/cash account together with its own ledger account under 1100 (bank) or 1000 (cash). */
    public function create(array $data): BankAccount
    {
        return DB::transaction(function () use ($data) {
            $isCash = (bool) ($data['is_cash'] ?? false);
            $parent = Account::where('code', $isCash ? '1000' : '1100')->firstOrFail();

            $sequence = $parent->children()->count() + 1;
            $account = Account::create([
                'code' => sprintf('%s-%02d', $parent->code, $sequence),
                'name' => $data['name'],
                'type' => 'asset',
                'parent_id' => $parent->id,
            ]);

            return BankAccount::create([
                'name' => $data['name'],
                'account_id' => $account->id,
                'bank_name' => $data['bank_name'] ?? null,
                'iban' => $data['iban'] ?? null,
                'is_cash' => $isCash,
            ])->load('account');
        });
    }
}
