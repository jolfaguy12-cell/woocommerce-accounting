<?php

namespace App\Domain\Expenses\Support;

use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Accounting\Support\PartyRoleType;
use App\Domain\Accounting\Support\PaymentPurpose;

/**
 * «بازپرداخت هزینه» — paying somebody back for a company expense they funded.
 *
 * ExpenseFundingSource records the debt at the moment the expense is entered:
 * an employee who buys packing tape with their own card is credited to 2350, a
 * partner who covers the rent is credited to 2600. Neither touches a company bank
 * account, and that is exactly right — no company money moved.
 *
 * This is the other half, and it was missing entirely: the day we hand the money
 * back. It debits the very account the expense credited, and credits the bank the
 * money actually left. The debt disappears because it was paid, not because a flag
 * was flipped.
 *
 * The two cases are one operation because they are one event with one rule (never
 * pay back more than is outstanding); they differ only in which account carries
 * the debt, which is what this enum exists to say.
 */
enum ReimbursementType: string
{
    case Employee = 'employee';
    case Partner = 'partner';

    public function label(): string
    {
        return match ($this) {
            self::Employee => 'بازپرداخت هزینه کارمند',
            self::Partner => 'بازپرداخت هزینه شریک',
        };
    }

    /** What the outstanding balance is called on the party's own file. */
    public function balanceLabel(): string
    {
        return match ($this) {
            self::Employee => 'هزینه پرداخت‌شده توسط کارمند',
            self::Partner => 'هزینه پرداخت‌شده توسط شریک',
        };
    }

    /** The debt account this reimbursement pays down — and the one the expense credited. */
    public function debtAccount(): AccountCode
    {
        return match ($this) {
            self::Employee => AccountCode::EmployeeCurrentAccount,  // 2350
            self::Partner => AccountCode::PartnerCurrentAccount,    // 2600
        };
    }

    /** The role the party must actually hold — you cannot reimburse an employee who is not one. */
    public function requiredRole(): PartyRoleType
    {
        return match ($this) {
            self::Employee => PartyRoleType::Employee,
            self::Partner => PartyRoleType::Partner,
        };
    }

    public function purpose(): PaymentPurpose
    {
        return match ($this) {
            self::Employee => PaymentPurpose::EmployeeExpenseReimbursement,
            self::Partner => PaymentPurpose::PartnerExpenseReimbursement,
        };
    }

    /** The funding source whose debt this settles — the link back to the original expense. */
    public function fundingSource(): ExpenseFundingSource
    {
        return match ($this) {
            self::Employee => ExpenseFundingSource::Employee,
            self::Partner => ExpenseFundingSource::Partner,
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $t) => [$t->value => $t->label()])->all();
    }
}
