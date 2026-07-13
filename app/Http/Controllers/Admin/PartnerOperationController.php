<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Exceptions\NegativeBalanceException;
use App\Domain\Accounting\Exceptions\OperationStateException;
use App\Domain\Accounting\Exceptions\PeriodLockedException;
use App\Domain\Accounting\Models\PartnerOperation;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\PartnerOperationService;
use App\Domain\Accounting\Services\PartyLedgerService;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Accounting\Support\OperationPolicy;
use App\Domain\Accounting\Support\OperationStatus;
use App\Domain\Accounting\Support\PartnerOperationType;
use App\Domain\Accounting\Support\PartyRoleType;
use App\Domain\Expenses\Models\BankAccount;
use App\Domain\Receivables\Support\InterestMethod;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** «عملیات شرکا» — capital, drawings, profit shares and partner loans. */
class PartnerOperationController extends Controller
{
    public function __construct(
        private readonly PartnerOperationService $operations,
        private readonly PartyLedgerService $ledger,
        private readonly OperationPolicy $policy,
    ) {}

    public function index(Request $request): View
    {
        $status = $request->string('status')->value();
        $type = $request->string('type')->value();

        return view('pages.partner-operations.index', [
            'title' => 'عملیات شرکا',
            'operations' => PartnerOperation::with(['party', 'bankAccount', 'creator'])
                ->when($status, fn ($q) => $q->where('status', $status))
                ->when($type, fn ($q) => $q->where('type', $type))
                ->latest('id')->paginate(25)->withQueryString(),
            'types' => PartnerOperationType::options(),
            'statuses' => OperationStatus::cases(),
            'filters' => ['status' => $status, 'type' => $type],
        ]);
    }

    public function create(): View
    {
        $partners = Party::withRole(PartyRoleType::Partner)->orderBy('name')->get(['id', 'name']);

        return view('pages.partner-operations.create', [
            'title' => 'ثبت عملیات شریک',
            'partners' => $partners->map(fn (Party $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'balances' => $this->ledger->balances($p),
            ]),
            'types' => collect(PartnerOperationType::cases())->map(fn (PartnerOperationType $t) => [
                'value' => $t->value,
                'label' => $t->label(),
                'moves_cash' => $t->movesCash(),
                'needs_counter' => $t->needsCounterAccount(),
                // A partner loan is a real loan: it needs a maturity, an interest method
                // and a schedule, and the form has to ask for them at the moment the
                // operation is created — not when it is finally approved, days later.
                'creates_loan' => $t->createsLoan(),
            ])->values(),
            'bankAccounts' => BankAccount::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'expenseAccounts' => $this->operations->reimbursableAccounts(),
            'interestMethods' => collect(InterestMethod::cases())->map(fn (InterestMethod $m) => [
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
            'type' => ['required', Rule::in(array_keys(PartnerOperationType::options()))],
            'amount' => 'required|integer|min:1',
            'operation_date' => 'required|date',
            'description' => 'required|string|max:255',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
            'counter_account_id' => 'nullable|exists:accounts,id',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
            // Loan terms — only meaningful for the two loan types, and carried through to
            // LoanService, which is what actually posts them.
            'maturity_date' => 'nullable|date|after_or_equal:operation_date',
            'interest_method' => ['nullable', Rule::in(array_column(InterestMethod::cases(), 'value'))],
            'interest_rate' => 'nullable|numeric|min:0|max:200',
            'interest_amount' => 'nullable|integer|min:0',
            'installment_count' => 'nullable|integer|min:0|max:600',
        ]);

        try {
            $operation = $this->operations->create($data + [
                'party' => Party::findOrFail($data['party_id']),
                'created_by' => $request->user()->id,
            ]);
        } catch (InvalidArgumentException|NegativeBalanceException|PeriodLockedException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return redirect()
            ->route('partner-operations.show', $operation)
            ->with('success', $operation->isPendingApproval()
                ? 'عملیات ثبت شد و در انتظار تأیید است. تا زمان تأیید، هیچ سندی در دفتر ثبت نمی‌شود.'
                : 'عملیات ثبت و سند حسابداری آن صادر شد.');
    }

    public function show(Request $request, PartnerOperation $partnerOperation): View
    {
        $partnerOperation->load(['party', 'bankAccount', 'counterAccount', 'journalEntry.lines.account',
            'reversalEntry', 'creator', 'approver', 'reverser', 'canceller', 'loan']);

        return view('pages.partner-operations.show', [
            'title' => 'عملیات شریک',
            'operation' => $partnerOperation,
            'balances' => $this->ledger->balances($partnerOperation->party),
            'canApprove' => $partnerOperation->isPendingApproval() && $this->policy->canApprove($request->user(), $partnerOperation),
            'canReverse' => $partnerOperation->isPosted() && $this->policy->canReverse($request->user()),
            'canCancel' => $partnerOperation->operationStatus()->isCancellable(),
        ]);
    }

    public function approve(Request $request, PartnerOperation $partnerOperation): RedirectResponse
    {
        return $this->control($request, $partnerOperation, 'approve');
    }

    public function reverse(Request $request, PartnerOperation $partnerOperation): RedirectResponse
    {
        return $this->control($request, $partnerOperation, 'reverse');
    }

    public function cancel(Request $request, PartnerOperation $partnerOperation): RedirectResponse
    {
        return $this->control($request, $partnerOperation, 'cancel');
    }

    private function control(Request $request, PartnerOperation $operation, string $action): RedirectResponse
    {
        $user = $request->user();
        $reason = $action === 'approve' ? null : $request->validate(['reason' => 'required|string|max:255'])['reason'];

        try {
            match ($action) {
                'approve' => $this->operations->approve($operation, $user),
                'reverse' => $this->operations->reverse($operation, $reason, $user),
                'cancel' => $this->operations->cancel($operation, $reason, $user),
            };
        } catch (OperationStateException|NegativeBalanceException|PeriodLockedException $e) {
            return back()->withErrors(['operation' => $e->getMessage()]);
        }

        return back()->with('success', match ($action) {
            'approve' => 'عملیات تأیید و سند آن در دفتر ثبت شد.',
            'reverse' => 'عملیات برگشت خورد. سند اصلی دست‌نخورده ماند و سند معکوس آن صادر شد.',
            'cancel' => 'عملیات لغو شد. هیچ سندی در دفتر ثبت نشده بود.',
        });
    }
}
