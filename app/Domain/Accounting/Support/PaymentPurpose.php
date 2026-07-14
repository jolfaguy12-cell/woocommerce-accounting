<?php

namespace App\Domain\Accounting\Support;

/**
 * What a party payment was FOR.
 *
 * `party_payments` already carried the money and the direction; what it could not
 * say was why. Two outgoing payments of the same amount to the same party can be
 * an invoice settlement, an advance, a payroll run or a partner's drawings — four
 * different accounts and four different meanings — and the row looked identical.
 *
 * NULL remains valid: rows written before purposes existed keep it, and nothing
 * guesses one for them retroactively.
 */
enum PaymentPurpose: string
{
    case SupplierInvoiceSettlement = 'supplier_invoice_settlement';
    case SupplierAdvance = 'supplier_advance';
    case SupplierRefund = 'supplier_refund';
    case CustomerReceipt = 'customer_receipt';
    case CustomerRefund = 'customer_refund';
    case EmployeeAdvance = 'employee_advance';
    case PayrollPayment = 'payroll_payment';
    // Paying back money somebody spent on the company out of their own pocket.
    // Not salary, not a drawing, not a supplier invoice: a debt WE incurred the
    // moment they paid, sitting on 2350 (employee) or 2600 (partner).
    case EmployeeExpenseReimbursement = 'employee_expense_reimbursement';
    case PartnerExpenseReimbursement = 'partner_expense_reimbursement';
    // Paying an expense that was recorded as owed rather than paid (2000).
    case UnpaidExpenseSettlement = 'unpaid_expense_settlement';
    case PartnerContribution = 'partner_contribution';
    case PartnerWithdrawal = 'partner_withdrawal';
    case PartnerLoan = 'partner_loan';
    case LoanInstallment = 'loan_installment';
    case ExpensePayment = 'expense_payment';
    case IncomeReceipt = 'income_receipt';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::SupplierInvoiceSettlement => 'تسویه فاکتور تأمین‌کننده',
            self::SupplierAdvance => 'پیش‌پرداخت به تأمین‌کننده',
            self::SupplierRefund => 'بازپرداخت از تأمین‌کننده',
            self::CustomerReceipt => 'دریافت از مشتری',
            self::CustomerRefund => 'استرداد به مشتری',
            self::EmployeeAdvance => 'مساعده کارمند',
            self::PayrollPayment => 'پرداخت حقوق',
            self::EmployeeExpenseReimbursement => 'بازپرداخت هزینه کارمند',
            self::PartnerExpenseReimbursement => 'بازپرداخت هزینه شریک',
            self::UnpaidExpenseSettlement => 'تسویه هزینه پرداخت‌نشده',
            self::PartnerContribution => 'آورده شریک',
            self::PartnerWithdrawal => 'برداشت شریک',
            self::PartnerLoan => 'وام شریک',
            self::LoanInstallment => 'قسط وام',
            self::ExpensePayment => 'پرداخت هزینه',
            self::IncomeReceipt => 'دریافت درآمد',
            self::Other => 'سایر',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $p) => [$p->value => $p->label()])->all();
    }
}
