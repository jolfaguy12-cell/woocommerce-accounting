<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Exceptions\PeriodLockedException;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\PartyLedgerService;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Expenses\Models\BankAccount;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Expenses\Services\ExpenseSettlementService;
use App\Domain\Expenses\Support\ExpenseFundingSource;
use App\Domain\Expenses\Support\ExpenseSettlementStatus;
use App\Domain\Expenses\Support\ReimbursementType;
use App\Domain\Receivables\Services\PaymentRecorder;
use App\Http\Controllers\Controller;
use App\Support\Design\TableQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * «هزینه‌ها» — the list, and the two things that were missing from it.
 *
 * Recording an expense has worked for a long time; what had no home at all was
 * what happens AFTERWARDS to an expense the company had not actually paid:
 *
 *   «تسویه هزینه پرداخت‌نشده» — the bill (2000) gets paid.
 *   «بازپرداخت هزینه کارمند/شریک» — the person who covered it out of their own
 *   pocket (2350 / 2600) gets their money back.
 *
 * Neither creates a second expense. The cost was recognised the day the expense was
 * entered; recognising it again on the day it is paid would double it in the books
 * — and balance perfectly while doing so, which is why the mistake survives review.
 */
class ExpenseController extends Controller
{
    public function __construct(
        private readonly ExpenseSettlementService $settlements,
        private readonly PaymentRecorder $payments,
        private readonly PartyLedgerService $ledger,
    ) {}

    public function index(Request $request): View
    {
        $query = new TableQuery(
            request: $request,
            sortable: ['date' => 'expense_date', 'amount' => 'amount', 'id' => 'id'],
            filters: ['funding_source', 'settlement'],
            defaultSort: '-date',
        );

        $source = $request->string('funding_source')->value();
        $settlement = $request->string('settlement')->value();

        $expenses = Expense::query()
            ->with(['category', 'party', 'fundedByParty', 'bankAccount'])
            ->when(filled($source), fn ($q) => $q->where('funding_source', $source))
            ->when($query->search(), fn ($q, string $search) => $q->where(fn ($w) => $w
                ->where('description', 'like', "%{$search}%")
                ->orWhereHas('fundedByParty', fn ($p) => $p->where('name', 'like', "%{$search}%"))))
            ->tap(fn ($q) => $query->apply($q))
            ->paginate($query->perPage())
            ->withQueryString();

        // Settlement status is a LEDGER read, so it cannot be a SQL filter — there is
        // no column to filter on, by design (see ExpenseSettlementService). Filtering
        // in PHP over the current page is honest about that; a stored `paid` flag
        // would be the alternative, and it would be wrong the first time a settlement
        // was reversed.
        $rows = collect($expenses->items())->map(fn (Expense $e) => [
            'expense' => $e,
            'status' => $this->settlements->status($e),
            'settled' => $this->settlements->isSettleable($e) ? $this->settlements->settled($e) : 0,
            'remaining' => $this->settlements->remaining($e),
            'date_fa' => JalaliPeriod::fmtDate(Carbon::parse($e->expense_date)),
        ]);

        if (filled($settlement)) {
            $rows = $rows->filter(fn (array $row) => $row['status']->value === $settlement)->values();
        }

        return view('pages.expenses.index', [
            'title' => 'هزینه‌ها',
            'expenses' => $expenses,
            'rows' => $rows,
            'query' => $query,
            'fundingSources' => ExpenseFundingSource::options(),
            'settlementStatuses' => collect(ExpenseSettlementStatus::cases())
                ->mapWithKeys(fn (ExpenseSettlementStatus $s) => [$s->value => $s->label()])->all(),
            'filters' => $request->only('search', 'funding_source', 'settlement'),
        ]);
    }

    /** One expense, its settlement history, and the form to settle the rest of it. */
    public function show(Expense $expense): View
    {
        $expense->load(['category', 'party', 'fundedByParty', 'bankAccount', 'journalEntry.lines.account']);

        return view('pages.expenses.show', [
            'title' => $expense->description,
            'expense' => $expense,
            'status' => $this->settlements->status($expense),
            'settled' => $this->settlements->isSettleable($expense) ? $this->settlements->settled($expense) : 0,
            'remaining' => $this->settlements->remaining($expense),
            'settlements' => $this->settlements->settlements($expense),
            'settleable' => $this->settlements->isSettleable($expense),
            'banks' => BankAccount::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'today' => Carbon::now(JalaliPeriod::TIMEZONE)->toDateString(),
            'date_fa' => JalaliPeriod::fmtDate(Carbon::parse($expense->expense_date)),
        ]);
    }

    /**
     * «تسویه هزینه پرداخت‌نشده».
     *
     * Partial and full settlement are the same operation; the cap is the remaining
     * balance, which is also what makes a duplicate submit harmless — the second one
     * finds nothing left to pay and is refused.
     */
    public function settle(Request $request, Expense $expense): RedirectResponse
    {
        $data = $request->validate([
            'amount' => 'required|integer|min:1',
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'accounting_date' => 'required|date',
            'reference' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:255',
        ]);

        try {
            $this->settlements->settle($expense, $data + ['created_by' => $request->user()->id]);
        } catch (InvalidArgumentException|PeriodLockedException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        $remaining = $this->settlements->remaining($expense->fresh());

        return back()->with('success', $remaining > 0
            ? 'پرداخت ثبت شد. این هزینه اکنون «بخشی پرداخت‌شده» است؛ مانده: '.number_format($remaining).' تومان.'
            : 'هزینه به‌طور کامل تسویه شد و بدهی آن از حساب‌های پرداختنی حذف گردید.');
    }

    /**
     * «بازپرداخت هزینه کارمند» / «بازپرداخت هزینه شریک» — one form, one operation,
     * two accounts. Which account is decided by ReimbursementType, never here.
     */
    public function createReimbursement(Request $request): View
    {
        $type = ReimbursementType::tryFrom((string) $request->query('type', '')) ?? ReimbursementType::Employee;
        $party = filled($request->query('party')) ? Party::find($request->query('party'))?->canonical() : null;

        return view('pages.expenses.reimburse', [
            'title' => 'بازپرداخت هزینه',
            'types' => collect(ReimbursementType::cases())->map(fn (ReimbursementType $t) => [
                'value' => $t->value,
                'label' => $t->label(),
                'role' => $t->requiredRole()->value,
                'balance_label' => $t->balanceLabel(),
                'account' => $t->debtAccount()->value,
            ])->values(),
            'type' => $type,
            'party' => $party,
            // Outstanding, if a party is preselected — so the form can say what the
            // ceiling actually is instead of only refusing at submit time.
            'outstanding' => $party ? max(0, $this->ledger->balanceOn($party, $type->debtAccount())) : null,
            // The expenses this person funded that are still unreimbursed, for the
            // optional link back to the original expense.
            'expenses' => $party
                ? Expense::where('funded_by_party_id', $party->id)
                    ->where('funding_source', $type->fundingSource()->value)
                    ->latest('expense_date')
                    ->limit(50)
                    ->get(['id', 'description', 'amount', 'expense_date'])
                    ->map(fn (Expense $e) => [
                        'id' => $e->id,
                        'label' => $e->description.' — '.number_format((int) $e->amount).' تومان'
                            .' ('.JalaliPeriod::fmtDate(Carbon::parse($e->expense_date)).')',
                    ])
                : collect(),
            'banks' => BankAccount::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'today' => Carbon::now(JalaliPeriod::TIMEZONE)->toDateString(),
        ]);
    }

    public function storeReimbursement(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(ReimbursementType::options()))],
            'party_id' => 'required|exists:parties,id',
            'amount' => 'required|integer|min:1',
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'accounting_date' => 'required|date',
            'reference' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:1000',
            'expense_id' => 'nullable|exists:expenses,id',
        ]);

        $type = ReimbursementType::from($data['type']);

        try {
            $this->payments->reimburse(
                type: $type,
                party: Party::live($data['party_id']),
                amount: $data['amount'],
                bankAccountId: $data['bank_account_id'],
                accountingDate: $data['accounting_date'],
                reference: $data['reference'] ?? null,
                note: $data['notes'] ?? null,
                expense: filled($data['expense_id'] ?? null) ? Expense::find($data['expense_id']) : null,
                by: $request->user()->id,
            );
        } catch (InvalidArgumentException|PeriodLockedException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return redirect()
            ->route('parties.show', ['party' => $data['party_id'], 'tab' => 'statement'])
            ->with('success', "«{$type->label()}» ثبت شد و بدهی شرکت به این طرف حساب کاهش یافت.");
    }
}
