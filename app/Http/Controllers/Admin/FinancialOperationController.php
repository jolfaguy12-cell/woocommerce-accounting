<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Exceptions\NegativeBalanceException;
use App\Domain\Accounting\Exceptions\OperationStateException;
use App\Domain\Accounting\Exceptions\PeriodLockedException;
use App\Domain\Accounting\Models\AccountTransaction;
use App\Domain\Accounting\Models\AccountTransfer;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\AccountTransactionService;
use App\Domain\Accounting\Services\AccountTransferService;
use App\Domain\Accounting\Services\FinancialOperationService;
use App\Domain\Accounting\Support\CounterAccountPolicy;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Accounting\Support\OperationPolicy;
use App\Domain\Accounting\Support\OperationStatus;
use App\Domain\Expenses\Models\BankAccount;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * «عملیات مالی جدید» — one entry point for every money movement that had none.
 *
 * Two operations live here today (internal transfer, direct deposit/withdrawal).
 * Both share one lifecycle, one approval gate and one reversal path, so a third
 * operation is a new service + a new branch in `store()`, not a new screen.
 */
class FinancialOperationController extends Controller
{
    public const TYPES = [
        'transfer' => 'انتقال بین حساب‌ها',
        'deposit' => 'واریز مستقیم به حساب',
        'withdrawal' => 'برداشت مستقیم از حساب',
    ];

    public function __construct(
        private readonly AccountTransferService $transfers,
        private readonly AccountTransactionService $transactions,
        private readonly OperationPolicy $policy,
        private readonly CounterAccountPolicy $counterAccountPolicy,
    ) {}

    /**
     * Both operation types in one list. They are separate tables (their columns
     * genuinely differ), so they are merged in PHP after each is fetched — the
     * volume here is human-scale, and a UNION view would buy nothing but a way
     * for the two shapes to drift apart.
     */
    public function index(Request $request): View
    {
        $status = $request->string('status')->value();
        $type = $request->string('type')->value();

        $rows = collect();

        if ($type !== 'deposit' && $type !== 'withdrawal') {
            $rows = $rows->concat(
                AccountTransfer::with(['fromBankAccount', 'toBankAccount', 'creator'])
                    ->when($status, fn ($q) => $q->where('status', $status))
                    ->latest('id')->limit(200)->get()
                    ->map(fn (AccountTransfer $t) => $this->rowForTransfer($t))
            );
        }

        if ($type !== 'transfer') {
            $rows = $rows->concat(
                AccountTransaction::with(['bankAccount', 'counterAccount', 'creator'])
                    ->when($status, fn ($q) => $q->where('status', $status))
                    ->when($type === 'deposit', fn ($q) => $q->where('direction', AccountTransaction::DIRECTION_IN))
                    ->when($type === 'withdrawal', fn ($q) => $q->where('direction', AccountTransaction::DIRECTION_OUT))
                    ->latest('id')->limit(200)->get()
                    ->map(fn (AccountTransaction $t) => $this->rowForTransaction($t))
            );
        }

        return view('pages.financial-operations.index', [
            'title' => 'عملیات مالی',
            'operations' => $rows->sortByDesc('date')->values(),
            'statuses' => OperationStatus::cases(),
            'types' => self::TYPES,
            'filters' => ['status' => $status, 'type' => $type],
        ]);
    }

    public function create(Request $request): View
    {
        return view('pages.financial-operations.create', [
            'title' => 'ثبت عملیات مالی جدید',
            'types' => self::TYPES,
            'selectedType' => array_key_exists($request->string('type')->value(), self::TYPES)
                ? $request->string('type')->value()
                : 'transfer',
            'bankAccounts' => BankAccount::with('account')->where('is_active', true)->orderBy('name')
                ->get()->map(fn (BankAccount $b) => [
                    'id' => $b->id,
                    'name' => $b->name,
                    'balance' => $b->account->balance(),
                ]),
            'counterAccounts' => $this->counterAccounts(),
            'purposes' => AccountTransaction::PURPOSES,
            'methods' => AccountTransfer::METHODS,
            'parties' => Party::orderBy('name')->limit(300)->get(['id', 'name']),
            'today' => Carbon::now(JalaliPeriod::TIMEZONE)->toDateString(),
            'approvalThreshold' => $this->policy->approvalThreshold(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $type = $request->string('type')->value();

        abort_unless(array_key_exists($type, self::TYPES), 404);
        abort_unless($this->policy->canCreate($request->user()), 403);

        try {
            $operation = match ($type) {
                'transfer' => $this->storeTransfer($request),
                default => $this->storeTransaction($request, $type),
            };
        } catch (InvalidArgumentException|NegativeBalanceException|PeriodLockedException $e) {
            // Domain guards (same account, inactive account, overdraft under
            // `block`, locked period) are real validation failures — they belong
            // on the form, not on an error page.
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return redirect()
            ->route($this->showRoute($operation), $operation)
            ->with('success', $operation->isPendingApproval()
                ? 'عملیات ثبت شد و در انتظار تأیید است. تا زمان تأیید، هیچ سندی در دفتر ثبت نمی‌شود.'
                : 'عملیات ثبت و سند حسابداری آن صادر شد.')
            ->with('warning', $this->overdraftWarning($operation));
    }

    public function showTransfer(AccountTransfer $transfer): View
    {
        return $this->renderShow(
            $transfer->load(['fromBankAccount', 'toBankAccount', 'journalEntry.lines.account', 'reversalEntry',
                'creator', 'approver', 'reverser', 'canceller']),
            'transfer',
        );
    }

    public function showTransaction(AccountTransaction $transaction): View
    {
        return $this->renderShow(
            $transaction->load(['bankAccount', 'counterAccount', 'party', 'journalEntry.lines.account',
                'reversalEntry', 'creator', 'approver', 'reverser', 'canceller']),
            'transaction',
        );
    }

    public function approveTransfer(Request $request, AccountTransfer $transfer): RedirectResponse
    {
        return $this->runControl($request, $transfer, $this->transfers, 'approve');
    }

    public function approveTransaction(Request $request, AccountTransaction $transaction): RedirectResponse
    {
        return $this->runControl($request, $transaction, $this->transactions, 'approve');
    }

    public function reverseTransfer(Request $request, AccountTransfer $transfer): RedirectResponse
    {
        return $this->runControl($request, $transfer, $this->transfers, 'reverse');
    }

    public function reverseTransaction(Request $request, AccountTransaction $transaction): RedirectResponse
    {
        return $this->runControl($request, $transaction, $this->transactions, 'reverse');
    }

    public function cancelTransfer(Request $request, AccountTransfer $transfer): RedirectResponse
    {
        return $this->runControl($request, $transfer, $this->transfers, 'cancel');
    }

    public function cancelTransaction(Request $request, AccountTransaction $transaction): RedirectResponse
    {
        return $this->runControl($request, $transaction, $this->transactions, 'cancel');
    }

    /* ---------------------------------------------------------------------- */

    private function storeTransfer(Request $request): AccountTransfer
    {
        $data = $request->validate([
            'from_bank_account_id' => 'required|exists:bank_accounts,id',
            'to_bank_account_id' => 'required|different:from_bank_account_id|exists:bank_accounts,id',
            'amount' => 'required|integer|min:1',
            'bank_fee' => 'nullable|integer|min:0',
            'transfer_date' => 'required|date',
            'method' => ['nullable', Rule::in(array_keys(AccountTransfer::METHODS))],
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ], [
            'to_bank_account_id.different' => 'مبدأ و مقصد انتقال نمی‌توانند یک حساب باشند.',
        ]);

        return $this->transfers->create($data + ['created_by' => $request->user()->id]);
    }

    private function storeTransaction(Request $request, string $type): AccountTransaction
    {
        $data = $request->validate([
            'bank_account_id' => 'required|exists:bank_accounts,id',
            // Mandatory by design: an operation that can move a balance without
            // naming the other side is a plug, and the ledger stops balancing.
            'counter_account_id' => 'required|exists:accounts,id',
            'purpose' => ['required', Rule::in(array_keys(AccountTransaction::PURPOSES))],
            'party_id' => 'nullable|exists:parties,id',
            'amount' => 'required|integer|min:1',
            'transaction_date' => 'required|date',
            'description' => 'required|string|max:255',
            'method' => 'nullable|string|max:30',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        return $this->transactions->create($data + [
            'direction' => $type === 'deposit' ? AccountTransaction::DIRECTION_IN : AccountTransaction::DIRECTION_OUT,
            'created_by' => $request->user()->id,
        ]);
    }

    /** approve / reverse / cancel, for either operation type. */
    private function runControl(Request $request, Model $operation, FinancialOperationService $service, string $action): RedirectResponse
    {
        $user = $request->user();

        $reason = $action === 'approve' ? null : $request->validate([
            'reason' => 'required|string|max:255',
        ])['reason'];

        try {
            match ($action) {
                'approve' => $service->approve($operation, $user),
                'reverse' => $service->reverse($operation, $reason, $user),
                'cancel' => $service->cancel($operation, $reason, $user),
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

    private function renderShow(Model $operation, string $kind): View
    {
        $user = request()->user();
        $service = $kind === 'transfer' ? $this->transfers : $this->transactions;

        return view('pages.financial-operations.show', [
            'title' => 'عملیات مالی',
            'operation' => $operation,
            'kind' => $kind,
            'summary' => $kind === 'transfer'
                ? $this->rowForTransfer($operation)
                : $this->rowForTransaction($operation),
            'canApprove' => $operation->isPendingApproval() && $this->policy->canApprove($user, $operation),
            'canReverse' => $operation->isPosted() && $this->policy->canReverse($user),
            'canCancel' => $operation->operationStatus()->isCancellable(),
            // Whoever is about to approve this deserves to know it will overdraw
            // the account BEFORE they approve it, not after.
            'overdrafts' => $service->overdrafts($operation),
        ]);
    }

    /**
     * The form offers exactly what the service will accept — same policy object,
     * so the dropdown can never drift into offering an account that then gets
     * refused (or, far worse, quietly accepted).
     */
    private function counterAccounts()
    {
        return $this->counterAccountPolicy->eligible();
    }

    private function rowForTransfer(AccountTransfer $t): array
    {
        return [
            'id' => $t->id,
            'kind' => 'transfer',
            'type_label' => self::TYPES['transfer'],
            'date' => $t->transfer_date,
            'date_fa' => JalaliPeriod::fmtDateTime($t->transfer_date),
            'amount' => $t->amount,
            'fee' => $t->bank_fee,
            'from' => $t->fromBankAccount->name,
            'to' => $t->toBankAccount->name,
            'summary' => "{$t->fromBankAccount->name} ← {$t->toBankAccount->name}",
            'status' => $t->operationStatus()->badgeStatus(),
            'status_label' => $t->operationStatus()->label(),
            'creator' => $t->creator?->name,
            'url' => route('financial-operations.transfers.show', $t),
        ];
    }

    private function rowForTransaction(AccountTransaction $t): array
    {
        return [
            'id' => $t->id,
            'kind' => 'transaction',
            'type_label' => self::TYPES[$t->isDeposit() ? 'deposit' : 'withdrawal'],
            'date' => $t->transaction_date,
            'date_fa' => JalaliPeriod::fmtDateTime($t->transaction_date),
            'amount' => $t->amount,
            'fee' => 0,
            'from' => $t->isDeposit() ? $t->counterAccount->name : $t->bankAccount->name,
            'to' => $t->isDeposit() ? $t->bankAccount->name : $t->counterAccount->name,
            'summary' => $t->description,
            'status' => $t->operationStatus()->badgeStatus(),
            'status_label' => $t->operationStatus()->label(),
            'creator' => $t->creator?->name,
            'url' => route('financial-operations.transactions.show', $t),
        ];
    }

    private function showRoute(Model $operation): string
    {
        return $operation instanceof AccountTransfer
            ? 'financial-operations.transfers.show'
            : 'financial-operations.transactions.show';
    }

    /** A `warn`-mode overdraft is surfaced, not swallowed — the money still moved. */
    private function overdraftWarning(Model $operation): ?string
    {
        if ($this->policy->negativeBalanceMode() !== OperationPolicy::MODE_WARN) {
            return null;
        }

        $service = $operation instanceof AccountTransfer ? $this->transfers : $this->transactions;

        return $service->overdrafts($operation) === []
            ? null
            : 'توجه: موجودی حساب مبدأ پس از این عملیات منفی است.';
    }
}
