<?php

use App\Domain\Accounting\Models\Account;
use Illuminate\Database\Migrations\Migration;

/**
 * `2350 حساب جاری کارمند` — what the company owes an employee for money the
 * employee spent on its behalf.
 *
 * Without it, an employee-funded expense had nowhere to go but a company bank
 * account, which is a lie twice over: the bank balance drops although no company
 * money moved, and the employee's claim on the company never appears anywhere.
 *
 * It is deliberately NOT `2300 حقوق پرداختنی`: netting a reimbursement into the
 * payroll payable would corrupt «مانده حقوق» — the salary balance would silently
 * include expenses that are not salary. Same reasoning as `1450` (supplier
 * advance) and `2600` (partner current account): one context, one account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Account::firstOrCreate(
            ['code' => '2350'],
            ['name' => 'حساب جاری کارمند', 'type' => 'liability', 'is_system' => true],
        );
    }

    public function down(): void
    {
        // Only if it never carried a posting — an account with journal lines is
        // history, and history is not rolled back.
        $account = Account::where('code', '2350')->first();

        if ($account && $account->lines()->doesntExist()) {
            $account->delete();
        }
    }
};
