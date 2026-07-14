<?php

namespace App\Domain\Expenses\Support;

use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Accounting\Support\PartyRoleType;

/**
 * The CREDIT side of an expense — who actually paid.
 *
 * The debit side never changes: an expense is an expense, and a capital purchase
 * is still a fixed asset, whoever handed over the money. What changes is where
 * the money came from, and that is the half the old recorder could not express:
 * every expense credited a company bank account, so "we haven't paid this yet"
 * and "an employee paid this from their own pocket" were both recorded as
 * company cash leaving the bank.
 */
enum ExpenseFundingSource: string
{
    /** Paid now, from a company bank account or cash box. Credit the bank. */
    case Bank = 'bank';

    /** Incurred, not paid. Credit accounts payable — this is a real debt. */
    case Unpaid = 'unpaid';

    /** An employee paid it for the company. Credit their current account: we owe them. */
    case Employee = 'employee';

    /** A partner paid it for the company. Credit their current account: we owe them. */
    case Partner = 'partner';

    public function label(): string
    {
        return match ($this) {
            self::Bank => 'پرداخت‌شده از حساب شرکت',
            self::Unpaid => 'پرداخت‌نشده (بدهی به طرف حساب)',
            self::Employee => 'هزینه پرداخت‌شده توسط کارمند',
            self::Partner => 'پرداخت‌شده توسط شریک',
        };
    }

    /** The account credited when the expense posts. */
    public function creditAccount(): ?AccountCode
    {
        return match ($this) {
            self::Bank => null, // the bank account's own ledger account, chosen per row
            self::Unpaid => AccountCode::AccountsPayable,
            self::Employee => AccountCode::EmployeeCurrentAccount,
            self::Partner => AccountCode::PartnerCurrentAccount,
        };
    }

    /** A bank-funded expense needs a bank account; every other source needs a party. */
    public function needsBankAccount(): bool
    {
        return $this === self::Bank;
    }

    public function needsParty(): bool
    {
        return $this !== self::Bank;
    }

    /**
     * The role the funding party must actually hold. An expense credited to
     * «حساب جاری کارمند» for someone who is not an employee would put a debt on
     * an account that nobody reads for them.
     */
    public function requiredRole(): ?PartyRoleType
    {
        return match ($this) {
            self::Employee => PartyRoleType::Employee,
            self::Partner => PartyRoleType::Partner,
            default => null, // an unpaid expense can be owed to anyone we buy from
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
