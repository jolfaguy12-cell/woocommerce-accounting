<?php

namespace App\Domain\Accounting\Support;

/**
 * The only three balance pairs that may be netted against each other.
 *
 * An offset is NOT "post any two accounts that happen to balance". Netting a
 * payable against sales revenue balances perfectly and is nonsense; netting one
 * party's receivable against another's payable balances too, and is theft with a
 * clean audit trail. So the combinations are enumerated, not configured: each
 * case fixes which account is debited and which is credited, and both legs always
 * carry the SAME party.
 *
 * Read each case as "reduce both of these by the offset amount".
 */
enum PartyOffsetType: string
{
    /** They owe us as a customer, we owe them as a supplier — the same person. */
    case ReceivableAgainstPayable = 'receivable_against_payable';

    /** We hold their credit; they also owe us on an invoice. */
    case CreditAgainstReceivable = 'credit_against_receivable';

    /** We paid them ahead of the invoice; the invoice has now arrived. */
    case AdvanceAgainstPayable = 'advance_against_payable';

    public function label(): string
    {
        return match ($this) {
            self::ReceivableAgainstPayable => 'دریافتنی مشتری ↔ پرداختنی تأمین‌کننده',
            self::CreditAgainstReceivable => 'اعتبار مشتری ↔ دریافتنی مشتری',
            self::AdvanceAgainstPayable => 'پیش‌پرداخت به تأمین‌کننده ↔ پرداختنی تأمین‌کننده',
        };
    }

    /**
     * The account DEBITED. In every case this is a balance we owe them (a
     * liability) or an asset being consumed — reducing it is a debit.
     */
    public function debitAccount(): AccountCode
    {
        return match ($this) {
            self::ReceivableAgainstPayable => AccountCode::AccountsPayable,  // reduce what we owe them
            self::CreditAgainstReceivable => AccountCode::CustomerCredit,    // consume the credit we hold
            self::AdvanceAgainstPayable => AccountCode::AccountsPayable,     // reduce what we owe them
        };
    }

    /** The account CREDITED: the asset they owed us, now settled without cash. */
    public function creditAccount(): AccountCode
    {
        return match ($this) {
            self::ReceivableAgainstPayable => AccountCode::AccountsReceivable, // they no longer owe us this
            self::CreditAgainstReceivable => AccountCode::AccountsReceivable,  // invoice settled from their credit
            self::AdvanceAgainstPayable => AccountCode::SupplierAdvance,       // the advance is now consumed
        };
    }

    /**
     * The two balances the offset consumes, in the order [debit side, credit side].
     * The amount can never exceed EITHER of them: offsetting more than exists on
     * one side does not settle a debt, it invents one in the opposite direction.
     */
    public function capAccounts(): array
    {
        return [$this->debitAccount(), $this->creditAccount()];
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $t) => [$t->value => $t->label()])->all();
    }
}
