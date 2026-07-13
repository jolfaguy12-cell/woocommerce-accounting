<?php

namespace App\Domain\Accounting\Support;

use App\Domain\Accounting\Models\Account;
use App\Domain\Expenses\Models\BankAccount;
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
     * @return Collection<int, Account>
     */
    public function eligible(): Collection
    {
        return Account::query()
            ->where('is_active', true)
            ->whereIn('code', $this->allowedCodes())
            ->whereNotIn('code', array_keys(self::CONTROL_ACCOUNTS))
            ->whereNotIn('id', BankAccount::pluck('account_id'))
            ->whereDoesntHave('children')
            ->orderBy('code')
            ->get();
    }

    public function isEligible(Account $account): bool
    {
        return $this->eligible()->contains('id', $account->id);
    }

    /** @throws InvalidArgumentException with the workflow to use instead, when there is one. */
    public function assertEligible(Account $account): void
    {
        if ($this->isEligible($account)) {
            return;
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
}
