<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Models\CostCenter;
use App\Domain\Accounting\Models\Party;
use App\Domain\Channels\Models\Channel;
use App\Domain\Channels\Services\ChannelCostRecorder;
use App\Domain\Expenses\Models\BankAccount;
use App\Domain\Expenses\Models\ExpenseCategory;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Domain\Expenses\Services\ExpenseRecorder;
use App\Domain\Expenses\Support\ExpenseFundingSource;
use App\Domain\Receivables\Models\CreditOrder;
use App\Domain\Receivables\Services\PaymentRecorder;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class FastFormController extends Controller
{
    public function index(): View
    {
        return view('pages.fast-forms.index', [
            'title' => 'فرم‌های سریع',
            'categories' => ExpenseCategory::where('is_active', true)->get(['id', 'name']),
            'cost_centers' => CostCenter::where('is_active', true)->get(['id', 'name']),
            'banks' => BankAccount::where('is_active', true)->get(['id', 'name', 'is_cash']),
            'channels' => Channel::where('is_active', true)
                ->whereIn('cost_model', ['wallet_topup', 'manual_period'])->get(['id', 'name']),
            'fundingSources' => ExpenseFundingSource::options(),
            'today' => Carbon::now('Asia/Tehran')->toDateString(),
            'open_credits' => CreditOrder::with('party:id,name')->where('status', 'open')->get()
                ->map(fn ($c) => ['id' => $c->id, 'party_id' => $c->party_id, 'party' => $c->party->name,
                    'remaining' => $c->remaining(), 'description' => $c->description]),
        ]);
    }

    public function storeExpense(Request $request, ExpenseRecorder $recorder): RedirectResponse
    {
        // An expense with no stated funding source is a company-paid expense —
        // which is what every expense was before the source existed. So an older
        // caller that never heard of `funding_source` keeps working, and keeps
        // meaning exactly what it always meant.
        $request->mergeIfMissing(['funding_source' => ExpenseFundingSource::Bank->value]);

        $data = $request->validate([
            'expense_category_id' => 'required|exists:expense_categories,id',
            // «منبع پرداخت» — who actually paid. Only a bank-funded expense has a
            // bank account; the others create a debt instead of moving cash.
            'funding_source' => ['required', Rule::in(array_column(ExpenseFundingSource::cases(), 'value'))],
            'bank_account_id' => 'nullable|required_if:funding_source,bank|exists:bank_accounts,id',
            'funded_by_party_id' => 'nullable|required_unless:funding_source,bank|exists:parties,id',
            'cost_center_id' => 'nullable|exists:cost_centers,id',
            'amount' => 'required|integer|min:1',
            'expense_date' => 'nullable|date',
            'description' => 'required|string|max:255',
            'affects_partner_profit' => 'boolean',
            'is_capital' => 'boolean',
        ], [
            'bank_account_id.required_if' => 'برای هزینه پرداخت‌شده از حساب شرکت، انتخاب حساب بانکی یا صندوق الزامی است.',
            'funded_by_party_id.required_unless' => 'مشخص کنید بدهی این هزینه به کدام طرف حساب ثبت می‌شود.',
        ]);

        try {
            $recorder->record($data + [
                'expense_date' => $data['expense_date'] ?? Carbon::now('Asia/Tehran'),
                'created_by' => $request->user()->id,
            ]);
        } catch (InvalidArgumentException $e) {
            // The recorder's own guards (a party without the role it is being billed
            // to, a missing counterparty) are validation failures, not crashes.
            return back()->withErrors(['funded_by_party_id' => $e->getMessage()])->withInput();
        }

        return back()->with('success', match (ExpenseFundingSource::from($data['funding_source'])) {
            ExpenseFundingSource::Bank => 'هزینه ثبت و سند آن صادر شد.',
            ExpenseFundingSource::Unpaid => 'هزینه ثبت شد و به‌عنوان بدهی پرداخت‌نشده در حساب طرف حساب نشست.',
            ExpenseFundingSource::Employee => 'هزینه ثبت شد و به‌عنوان بدهی شرکت به کارمند ثبت شد؛ موجودی بانک تغییر نکرد.',
            ExpenseFundingSource::Partner => 'هزینه ثبت شد و به حساب جاری شریک منظور شد؛ موجودی بانک تغییر نکرد.',
        });
    }

    public function storeTopup(Request $request, ChannelCostRecorder $recorder): RedirectResponse
    {
        $data = $request->validate([
            'channel_id' => 'required|exists:channels,id',
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'amount' => 'required|integer|min:1',
            'note' => 'nullable|string|max:255',
        ]);

        $channel = Channel::findOrFail($data['channel_id']);

        $recorder->record(
            $channel,
            $channel->cost_model === 'wallet_topup' ? 'topup' : 'manual',
            $data['amount'],
            Carbon::now('Asia/Tehran'),
            $data['bank_account_id'],
            $data['note'] ?? null,
            $request->user()->id,
        );

        return back()->with('success', "هزینه/شارژ کانال {$channel->name} ثبت شد.");
    }

    public function storePayment(Request $request, PaymentRecorder $recorder): RedirectResponse
    {
        $data = $request->validate([
            'party_id' => 'required|exists:parties,id',
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'amount' => 'required|integer|min:1',
            'credit_order_id' => 'nullable|exists:credit_orders,id',
        ]);

        $recorder->receive(
            Party::findOrFail($data['party_id']),
            $data['amount'],
            $data['bank_account_id'],
            isset($data['credit_order_id']) ? CreditOrder::find($data['credit_order_id']) : null,
            $request->user()->id,
        );

        return back()->with('success', 'دریافت مشتری ثبت شد.');
    }

    public function storeBank(Request $request, BankAccountManager $manager): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'bank_name' => 'nullable|string|max:100',
            'iban' => 'nullable|string|max:34',
            'is_cash' => 'boolean',
        ]);

        $manager->create($data);

        return back()->with('success', 'حساب بانکی/صندوق با حساب دفترکل اختصاصی ساخته شد.');
    }
}
