<?php

namespace App\Domain\Accounting\Support;

use App\Domain\Accounting\Models\Account;
use App\Domain\Expenses\Models\BankAccount;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Which accounts a DIRECT account operation is allowed to touch as its
 * counter-account — and, far more importantly, which it is not.
 *
 * A direct deposit/withdrawal is the system's generic movement. Left open, it
 * would be a back door into every subsidiary ledger in the app: you could clear
 * a supplier's payable without a payment record, settle a customer's receivable
 * without touching their account, pay salary with no payroll run, repay a loan
 * that keeps its full outstanding balance. In each case the journal would move
 * and the workflow that is supposed to own that balance would never know — and
 * the two would disagree forever, with nothing to reconcile them back.
 *
 * So the gate is an allowlist (config: accounting.direct_operation_counter_accounts):
 * genuine income, genuine expense, and explicitly-classified adjustments. The
 * control accounts below are named separately only so the refusal can say WHICH
 * workflow to use instead of "not allowed".
 */
class CounterAccountPolicy
{
    /**
     * Accounts owned by a typed workflow. Each is the ledger side of a subsidiary
     * record (a payment, an invoice, a loan, a payroll run, a partner operation),
     * and moving it without that record is what puts a ledger out of step with
     * itself.
     *
     * @var array<string, string> account code => the workflow that owns it
     */
    public const CONTROL_ACCOUNTS = [
        '1200' => 'دریافت از مشتری (ثبت پرداخت مشتری)',           // AccountsReceivable
        '2000' => 'پرداخت به تأمین‌کننده (صفحه تأمین‌کننده)',      // AccountsPayable
        '2400' => 'اعتبار مشتری (دریافت/استرداد مشتری)',           // CustomerCredit
        '1450' => 'پیش‌پرداخت به تأمین‌کننده (پرداخت به تأمین‌کننده)', // SupplierAdvance
        '1400' => 'مساعده کارکنان (کارگزینی)',                     // EmployeeAdvance
        '2300' => 'حقوق پرداختنی (لیست حقوق)',                     // PayrollPayable
        '1600' => 'وام به شریک / تسهیلات اعطایی (عملیات شریک)',    // LoansReceivable
        '2200' => 'وام از شریک / تسهیلات دریافتی (عملیات وام)',    // LoansPayable
        '2600' => 'حساب جاری شرکا (عملیات شریک)',                  // PartnerCurrentAccount
        '2500' => 'سود سهم شرکا پرداختنی (عملیات شریک)',           // PartnerProfitPayable
        '3000' => 'سرمایه (آورده / کاهش سرمایه شریک)',             // Capital
        '3100' => 'برداشت شریک (عملیات شریک)',                     // PartnerWithdrawal
        '3200' => 'سود (زیان) انباشته (توزیع سود شریک)',           // RetainedEarnings
        '1300' => 'موجودی کالا (فاکتور خرید)',                     // Inventory
        '1250' => 'اسناد دریافتنی (چک‌ها)',                        // ChequesReceivable
        '2100' => 'اسناد پرداختنی (چک‌ها)',                        // ChequesPayable
        '1150' => 'تسویه در جریان زیبال (ورود واریزی‌ها)',         // ZibalClearing
    ];

    /**
     * Every account a direct operation may legitimately use: active, postable
     * (a leaf — a parent account is a heading, and posting to it double-counts
     * against its children), and explicitly allowlisted.
     *
     * $user is the person the list is FOR. Adjustment accounts are admin-only, and
     * omitting $user means "the safest possible list" — so a caller that forgets to
     * pass one gets fewer accounts, never more.
     *
     * @return Collection<int, Account>
     */
    public function eligible(?User $user = null): Collection
    {
        $codes = array_diff($this->allowedCodes(), $this->mayAdjust($user) ? [] : $this->adjustmentCodes());

        return Account::query()
            ->where('is_active', true)
            ->whereIn('code', $codes)
            ->whereNotIn('code', array_keys(self::CONTROL_ACCOUNTS))
            ->whereNotIn('id', BankAccount::pluck('account_id'))
            ->whereDoesntHave('children')
            ->orderBy('code')
            ->get();
    }

    public function isEligible(Account $account, ?User $user = null): bool
    {
        return $this->eligible($user)->contains('id', $account->id);
    }

    /** An account that asserts the books were wrong, rather than that money moved. */
    public function isAdjustment(Account $account): bool
    {
        return in_array($account->code, $this->adjustmentCodes(), true);
    }

    public function mayAdjust(?User $user): bool
    {
        $roles = (array) config('accounting.adjustment_account_roles', ['admin']);

        return $user !== null && $user->hasAnyRole($roles);
    }

    /** @throws InvalidArgumentException with the workflow to use instead, when there is one. */
    public function assertEligible(Account $account, ?User $user = null): void
    {
        if ($this->isEligible($account, $user)) {
            return;
        }

        // Said before the generic refusal, because "not allowed" would be a lie:
        // the account IS allowed — just not to this person.
        if ($this->isAdjustment($account) && ! $this->mayAdjust($user)) {
            throw new InvalidArgumentException(
                "حساب «{$account->name}» یک حساب تعدیل است و فقط مدیر سیستم می‌تواند از آن استفاده کند."
            );
        }

        if ($workflow = self::CONTROL_ACCOUNTS[$account->code] ?? null) {
            throw new InvalidArgumentException(
                "حساب «{$account->name}» یک حساب کنترلی است و فقط از طریق «{$workflow}» قابل تغییر است، "
                .'نه با واریز/برداشت مستقیم.'
            );
        }

        // An internal bank/cash account is a transfer, and it has its own operation.
        if (BankAccount::where('account_id', $account->id)->exists()) {
            throw new InvalidArgumentException(
                'حساب مقابل یکی از حساب‌های داخلی است؛ برای جابه‌جایی بین حساب‌ها از «انتقال بین حساب‌ها» استفاده کنید.'
            );
        }

        if (! $account->is_active) {
            throw new InvalidArgumentException("حساب مقابل «{$account->name}» غیرفعال است.");
        }

        if ($account->children()->exists()) {
            throw new InvalidArgumentException("حساب «{$account->name}» یک سرفصل است و سند مستقیم نمی‌پذیرد.");
        }

        throw new InvalidArgumentException(
            "حساب «{$account->name}» برای واریز/برداشت مستقیم مجاز نیست. "
            .'فقط حساب‌های درآمد، هزینه و تعدیلِ مجاز قابل انتخاب‌اند.'
        );
    }

    /** @return list<string> */
    private function allowedCodes(): array
    {
        return (array) config('accounting.direct_operation_counter_accounts', []);
    }

    /** @return list<string> */
    private function adjustmentCodes(): array
    {
        return (array) config('accounting.adjustment_counter_accounts', []);
    }
}
