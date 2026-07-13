<?php

namespace App\Domain\Receivables\Support;

use App\Domain\Accounting\Support\AccountCode;

/**
 * Which way a loan runs — and the labels are the opposite of what they look like.
 *
 * A loan we PAID OUT («وام پرداختی») is an ASSET: the money left, and what we hold
 * is the borrower's obligation to give it back. A loan we RECEIVED («وام دریافتی»)
 * is a LIABILITY: the money arrived, and what we hold is our own obligation to
 * repay. The Persian names describe the cash movement, the accounts describe the
 * claim, and they point in opposite directions. Getting this backwards puts an
 * asset in the liabilities column — so the direction, its account and its label are
 * fixed together here rather than being re-derived at each call site.
 */
enum LoanDirection: string
{
    /** وام پرداختی — we lent the money out. Asset (1600). */
    case Receivable = 'receivable';

    /** وام دریافتی — we borrowed the money. Liability (2200). */
    case Payable = 'payable';

    public function label(): string
    {
        return match ($this) {
            self::Receivable => 'وام پرداختی',
            self::Payable => 'وام دریافتی',
        };
    }

    /** The account carrying the outstanding principal. */
    public function principalAccount(): AccountCode
    {
        return match ($this) {
            self::Receivable => AccountCode::LoansReceivable,
            self::Payable => AccountCode::LoansPayable,
        };
    }

    /**
     * Where the interest lands. Interest on money we borrowed is a COST; interest
     * on money we lent is INCOME. Same word, opposite sides of the P&L.
     */
    public function interestAccount(): AccountCode
    {
        return match ($this) {
            self::Receivable => AccountCode::InterestIncome,   // 4200 سود و کارمزد دریافتی
            self::Payable => AccountCode::FinanceCost,         // 6300 هزینه‌های مالی و بهره
        };
    }

    /** A fee we pay the bank is an expense; a fee we charge a borrower is income. */
    public function feeAccount(): AccountCode
    {
        return match ($this) {
            self::Receivable => AccountCode::InterestIncome,   // 4200 «سود و کارمزد دریافتی» — literally this
            self::Payable => AccountCode::BankFee,             // 6350 کارمزد بانکی
        };
    }

    /**
     * A late penalty we pay is «جریمه دیرکرد» (6370). A penalty we COLLECT is not
     * interest and not a fee, so it does not belong in 4200 — it goes to other
     * income (4900), where an unusual receipt is visible rather than blended into
     * the loan's yield.
     */
    public function penaltyAccount(): AccountCode
    {
        return match ($this) {
            self::Receivable => AccountCode::OtherIncome,      // 4900 سایر درآمدها
            self::Payable => AccountCode::LatePenalty,         // 6370 جریمه دیرکرد
        };
    }

    /** Does the disbursement take money OUT of our account? Only for a loan we give. */
    public function disbursementIsOutflow(): bool
    {
        return $this === self::Receivable;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $d) => [$d->value => $d->label()])->all();
    }
}
