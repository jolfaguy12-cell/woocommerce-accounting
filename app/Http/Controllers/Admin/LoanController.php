<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Exceptions\NegativeBalanceException;
use App\Domain\Accounting\Exceptions\OperationStateException;
use App\Domain\Accounting\Exceptions\PeriodLockedException;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Accounting\Support\OperationPolicy;
use App\Domain\Expenses\Models\BankAccount;
use App\Domain\Receivables\Models\Loan;
use App\Domain\Receivables\Models\LoanInstallment;
use App\Domain\Receivables\Services\LoanService;
use App\Domain\Receivables\Support\InterestMethod;
use App\Domain\Receivables\Support\LoanDirection;
use App\Domain\Receivables\Support\LoanStatus;
use App\Http\Controllers\Controller;
use App\Support\Design\TableQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** «وام و اقساط» — loans in both directions, with their schedules. */
class LoanController extends Controller
{
    public function __construct(
        private readonly LoanService $loans,
        private readonly OperationPolicy $policy,
    ) {}

    public function index(Request $request): View
    {
        $query = new TableQuery(
            request: $request,
            sortable: ['id' => 'id', 'principal' => 'principal', 'received_at' => 'received_at'],
            filters: ['direction', 'status'],
            defaultSort: '-id',
        );

        $loans = Loan::query()
            ->with(['party', 'bankAccount', 'installments'])
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->input('direction')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($query->search(), fn ($q, string $s) => $q->whereHas('party', fn ($p) => $p->where('name', 'like', "%{$s}%")))
            ->tap(fn ($q) => $query->apply($q))
            ->paginate($query->perPage())
            ->withQueryString();

        // Prepared here, never in the view: a Blade file that computes a balance is a
        // Blade file that has to be trusted with the ledger.
        $rows = $loans->getCollection()->map(fn (Loan $loan) => $this->summarise($loan));

        return view('pages.loans.index', [
            'title' => 'وام و اقساط',
            'loans' => $loans,
            'rows' => $rows,
            'query' => $query,
            'directions' => LoanDirection::options(),
            'statuses' => LoanStatus::cases(),
            'filters' => $request->only('direction', 'status', 'search'),
        ]);
    }

    public function create(Request $request): View
    {
        return view('pages.loans.create', [
            'title' => 'ثبت وام جدید',
            // The picker searches the server (parties.search); the form only needs to
            // know the name of an already-chosen party so a validation bounce does not
            // render an empty field with a hidden id in it.
            'selectedPartyName' => Party::whereKey(old('party_id'))->value('name'),
            'bankAccounts' => BankAccount::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'directions' => LoanDirection::options(),
            'methods' => collect(InterestMethod::cases())->map(fn (InterestMethod $m) => [
                'value' => $m->value,
                'label' => $m->label(),
                'needs_rate' => $m->needsRate(),
                'needs_amount' => $m->needsAmount(),
            ])->values(),
            'today' => Carbon::now(JalaliPeriod::TIMEZONE)->toDateString(),
            'approvalThreshold' => $this->policy->approvalThreshold(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->policy->canCreate($request->user()), 403);

        $data = $request->validate([
            'party_id' => 'required|exists:parties,id',
            'direction' => ['required', Rule::in(array_keys(LoanDirection::options()))],
            'principal' => 'required|integer|min:1',
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'received_at' => 'required|date',
            'maturity_date' => 'nullable|date|after_or_equal:received_at',
            'interest_method' => ['required', Rule::in(array_column(InterestMethod::cases(), 'value'))],
            'interest_rate' => 'nullable|numeric|min:0|max:200',
            'interest_amount' => 'nullable|integer|min:0',
            'installment_count' => 'nullable|integer|min:0|max:600',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $loan = $this->loans->create($data + [
                'party' => Party::findOrFail($data['party_id']),
                'created_by' => $request->user()->id,
            ]);
        } catch (InvalidArgumentException|NegativeBalanceException|PeriodLockedException $e) {
            // Domain guards are validation failures — they belong on the form, not on an
            // error page the operator has to back out of.
            throw ValidationException::withMessages(['principal' => $e->getMessage()]);
        }

        return redirect()->route('loans.show', $loan)->with('success', $loan->status === LoanStatus::PendingApproval
            ? 'وام ثبت شد و در انتظار تأیید است. تا زمان تأیید هیچ سندی در دفتر ثبت نمی‌شود.'
            : 'وام ثبت و سند حسابداری آن صادر شد.');
    }

    public function show(Loan $loan): View
    {
        $loan->load(['party', 'bankAccount', 'installments', 'journalEntry.lines.account', 'creator', 'approver']);

        $user = request()->user();

        return view('pages.loans.show', [
            'title' => $loan->direction->label(),
            'loan' => $loan,
            'summary' => $this->summarise($loan),
            'bankAccounts' => BankAccount::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canApprove' => $loan->status === LoanStatus::PendingApproval && $this->policy->canApprove($user, $loan),
            'canReverse' => $loan->status->isDisbursed() && $loan->status !== LoanStatus::Reversed && $this->policy->canReverse($user),
            'canCancel' => $loan->status->isCancellable(),
            'canPay' => $loan->status->isRepaying(),
            'today' => Carbon::now(JalaliPeriod::TIMEZONE)->toDateString(),
        ]);
    }

    public function approve(Request $request, Loan $loan): RedirectResponse
    {
        return $this->run(fn () => $this->loans->approve($loan, $request->user()),
            'وام تأیید و سند آن در دفتر ثبت شد.');
    }

    public function cancel(Request $request, Loan $loan): RedirectResponse
    {
        $reason = $request->validate(['reason' => 'required|string|max:255'])['reason'];

        return $this->run(fn () => $this->loans->cancel($loan, $reason, $request->user()),
            'وام لغو شد. هیچ سندی در دفتر ثبت نشده بود.');
    }

    public function reverse(Request $request, Loan $loan): RedirectResponse
    {
        $reason = $request->validate(['reason' => 'required|string|max:255'])['reason'];

        return $this->run(fn () => $this->loans->reverse($loan, $reason, $request->user()),
            'وام برگشت خورد. سند اصلی دست‌نخورده ماند و سند معکوس آن صادر شد.');
    }

    /** Pay or receive one installment — the direction decides which, not the caller. */
    public function payInstallment(Request $request, Loan $loan): RedirectResponse
    {
        abort_unless($this->policy->canCreate($request->user()), 403);

        $data = $request->validate([
            'installment_id' => 'nullable|integer|exists:loan_installments,id',
            'amount' => 'required|integer|min:1',
            'principal_part' => 'required|integer|min:0',
            'fee_part' => 'nullable|integer|min:0',
            'penalty_part' => 'nullable|integer|min:0',
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'paid_at' => 'required|date',
        ]);

        $installment = isset($data['installment_id'])
            ? LoanInstallment::find($data['installment_id'])
            : null;

        $args = [
            $loan,
            (int) $data['amount'],
            (int) $data['principal_part'],
            (int) $data['bank_account_id'],
            Carbon::parse($data['paid_at'], JalaliPeriod::TIMEZONE),
            (int) ($data['fee_part'] ?? 0),
            (int) ($data['penalty_part'] ?? 0),
            $installment,
            $request->user()->id,
        ];

        try {
            $loan->isReceivable()
                ? $this->loans->receiveInstallment(...$args)
                : $this->loans->payInstallment(...$args);
        } catch (InvalidArgumentException|OperationStateException|NegativeBalanceException|PeriodLockedException $e) {
            return back()->withErrors(['amount' => $e->getMessage()])->withInput();
        }

        return back()->with('success', 'قسط ثبت و سند آن صادر شد.');
    }

    public function reverseInstallment(Request $request, Loan $loan, LoanInstallment $installment): RedirectResponse
    {
        abort_unless((int) $installment->loan_id === (int) $loan->id, 404);

        $reason = $request->validate(['reason' => 'required|string|max:255'])['reason'];

        return $this->run(fn () => $this->loans->reverseInstallment($installment, $reason, $request->user()),
            'قسط برگشت خورد. سند اصلی باقی ماند و این قسط دوباره در انتظار پرداخت است.');
    }

    /* ---------------------------------------------------------------------- */

    /**
     * Every figure the loan screens show, computed once here.
     *
     * «مانده اصل وام» comes from the LEDGER, not from subtracting the paid installments
     * from the principal — those two agree only while nothing has been reversed, and the
     * ledger is the one that is right when they disagree.
     */
    private function summarise(Loan $loan): array
    {
        $paid = $this->loans->paidTotals($loan);
        $next = $loan->nextInstallment();

        // Overdue is DERIVED, here, on read. It is purely a label — being late does not
        // change a single balance — so it does not need a scheduler to keep it true, and
        // a page that told you a loan was current because a nightly job had not run yet
        // would be worse than no label at all. (`loans:refresh-overdue` persists the same
        // fact for anything querying the column directly; neither writes to the ledger.)
        $isOverdue = $loan->status->isRepaying() && $next?->isLate();

        return [
            'is_overdue' => (bool) $isOverdue,
            'id' => $loan->id,
            'party' => $loan->party->name,
            'party_id' => $loan->party_id,
            'direction' => $loan->direction->label(),
            'principal' => (int) $loan->principal,          // اصل وام
            'interest' => (int) $loan->interest_amount,     // سود
            'paid_principal' => $paid['principal'],
            'paid_interest' => $paid['interest'],
            'paid_fee' => $paid['fee'],                     // کارمزد
            'paid_penalty' => $paid['penalty'],             // جریمه دیرکرد
            'paid_total' => $paid['total'],
            'remaining_principal' => $this->loans->remainingPrincipal($loan), // مانده اصل وام
            'next_due' => $next?->due_date,                                    // سررسید بعدی
            'next_due_fa' => $next?->due_date ? JalaliPeriod::fmtDate($next->due_date) : null,
            'next_amount' => $next?->total(),
            // The derived answer wins over the stored one: the column is only as fresh as
            // the last time the command ran, and the due date is true right now.
            'status' => $isOverdue ? LoanStatus::Overdue->badgeStatus() : $loan->status->badgeStatus(),
            'status_label' => $isOverdue ? LoanStatus::Overdue->label() : $loan->status->label(),
            'received_at_fa' => JalaliPeriod::fmtDate($loan->received_at),
            'maturity_fa' => $loan->maturity_date ? JalaliPeriod::fmtDate($loan->maturity_date) : null,
            'bank' => $loan->bankAccount?->name,
            'url' => route('loans.show', $loan),
        ];
    }

    private function run(callable $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (OperationStateException|InvalidArgumentException|NegativeBalanceException|PeriodLockedException $e) {
            return back()->withErrors(['loan' => $e->getMessage()]);
        }

        return back()->with('success', $message);
    }
}
