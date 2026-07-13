<?php

namespace App\Domain\Accounting\Support;

/**
 * The nine things a partner can do with the company's money, each with its own
 * accounts — never a generic income or expense.
 *
 * The distinction matters because these look identical in a bank statement and
 * mean completely different things: a partner who puts in 50m has increased their
 * stake; a partner who lends 50m must be paid back; a partner who takes out 50m
 * has drawn against their share. Post all three as "money moved" and the partner
 * report can no longer tell an owner from a creditor.
 */
enum PartnerOperationType: string
{
    /** آورده شریک — capital in. Increases their stake, is never repaid as a debt. */
    case Contribution = 'contribution';

    /** کاهش سرمایه — capital out. The stake itself shrinks. */
    case CapitalReduction = 'capital_reduction';

    /** برداشت شریک — drawings against their share of profit, tracked apart from capital. */
    case Withdrawal = 'withdrawal';

    /** بازپرداخت هزینه شریک — they paid a company expense from their own pocket; we now owe them. */
    case ExpenseReimbursement = 'expense_reimbursement';

    /** توزیع سود — declaring a partner's share of RETAINED EARNINGS as a debt owed to them. */
    case ProfitDistribution = 'profit_distribution';

    /** پرداخت سود شریک — actually paying out a share that was already declared. */
    case ProfitPayablePayment = 'profit_payable_payment';

    /** وام از شریک — they lend the company money. A real loan, with a schedule. */
    case LoanFromPartner = 'loan_from_partner';

    /** وام به شریک — the company lends them money. */
    case LoanToPartner = 'loan_to_partner';

    /** تسویه حساب جاری شریک — settling their current account in cash. */
    case CurrentAccountSettlement = 'current_account_settlement';

    public function label(): string
    {
        return match ($this) {
            self::Contribution => 'آورده شریک',
            self::CapitalReduction => 'کاهش سرمایه',
            self::Withdrawal => 'برداشت شریک',
            self::ExpenseReimbursement => 'بازپرداخت هزینه شریک',
            self::ProfitDistribution => 'توزیع سود',
            // «سود سهم شرکا پرداختنی» is the name of the ACCOUNT (2500) this
            // settles, not the name of the action. The action is paying it.
            self::ProfitPayablePayment => 'پرداخت سود شریک',
            self::LoanFromPartner => 'وام از شریک',
            self::LoanToPartner => 'وام به شریک',
            self::CurrentAccountSettlement => 'تسویه حساب جاری شریک',
        };
    }

    /** Does money actually move? Profit distribution and reimbursement recognition do not. */
    public function movesCash(): bool
    {
        return ! in_array($this, [self::ProfitDistribution, self::ExpenseReimbursement], true);
    }

    /** Does the cash leave us (rather than arrive)? Drives the overdraft guard. */
    public function isOutflow(): bool
    {
        return in_array($this, [
            self::CapitalReduction,
            self::Withdrawal,
            self::ProfitPayablePayment,
            self::LoanToPartner,
            self::CurrentAccountSettlement,
        ], true);
    }

    /** Only expense reimbursement needs to say WHICH expense the partner covered. */
    public function needsCounterAccount(): bool
    {
        return $this === self::ExpenseReimbursement;
    }

    /**
     * A partner loan is a LOAN, not a partner-shaped journal entry.
     *
     * It has a maturity date, an interest method and a repayment schedule, and none
     * of that exists unless a Loan contract is created for it. So these two types do
     * not post their own lines at all — they delegate to LoanService, which posts
     * exactly one entry, and this row records the same event on the partner's file
     * through `loan_id`. Two entries for one disbursement would double the money.
     */
    public function createsLoan(): bool
    {
        return in_array($this, [self::LoanFromPartner, self::LoanToPartner], true);
    }

    /**
     * The party-facing account — the one that carries the partner's party_id and
     * therefore shows up in their unified statement. `null` for the loan types,
     * whose account is decided by LoanService (2200) or is 1600 directly.
     */
    public function partyAccount(): ?AccountCode
    {
        return match ($this) {
            self::Contribution, self::CapitalReduction => AccountCode::Capital,
            self::Withdrawal => AccountCode::PartnerWithdrawal,
            self::ExpenseReimbursement, self::CurrentAccountSettlement => AccountCode::PartnerCurrentAccount,
            self::ProfitDistribution, self::ProfitPayablePayment => AccountCode::PartnerProfitPayable,
            self::LoanFromPartner => AccountCode::LoansPayable,
            self::LoanToPartner => AccountCode::LoansReceivable,
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $t) => [$t->value => $t->label()])->all();
    }
}
