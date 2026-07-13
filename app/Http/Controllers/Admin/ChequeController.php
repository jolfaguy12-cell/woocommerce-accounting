<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Exceptions\OperationStateException;
use App\Domain\Accounting\Exceptions\PeriodLockedException;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Accounting\Support\OperationPolicy;
use App\Domain\Expenses\Models\BankAccount;
use App\Domain\Receivables\Models\Cheque;
use App\Domain\Receivables\Services\ChequeService;
use App\Http\Controllers\Controller;
use App\Support\Design\TableQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** «چک‌ها» — registration, clearing, bouncing, cancellation and reversal. */
class ChequeController extends Controller
{
    public function __construct(
        private readonly ChequeService $cheques,
        private readonly OperationPolicy $policy,
    ) {}

    public function index(Request $request): View
    {
        $query = new TableQuery(
            request: $request,
            sortable: ['id' => 'id', 'amount' => 'amount', 'due_date' => 'due_date'],
            filters: ['direction', 'status'],
            defaultSort: 'due_date',
        );

        $cheques = Cheque::query()
            ->with(['party', 'bankAccount'])
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->input('direction')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($query->search(), fn ($q, string $s) => $q->where(fn ($w) => $w
                ->where('serial', 'like', "%{$s}%")
                ->orWhereHas('party', fn ($p) => $p->where('name', 'like', "%{$s}%"))))
            ->tap(fn ($q) => $query->apply($q))
            ->paginate($query->perPage())
            ->withQueryString();

        return view('pages.cheques.index', [
            'title' => 'چک‌ها',
            'cheques' => $cheques,
            'query' => $query,
            'directions' => ['receivable' => 'چک دریافتی', 'payable' => 'چک پرداختی'],
            'statuses' => [
                Cheque::PENDING => 'در جریان',
                Cheque::CLEARED => 'وصول‌شده',
                Cheque::BOUNCED => 'برگشتی',
                Cheque::CANCELLED => 'ابطال‌شده',
            ],
            'filters' => $request->only('direction', 'status', 'search'),
        ]);
    }

    public function create(): View
    {
        return view('pages.cheques.create', [
            'title' => 'ثبت چک جدید',
            'parties' => Party::orderBy('name')->limit(500)->get(['id', 'name']),
            'directions' => ['receivable' => 'چک دریافتی', 'payable' => 'چک پرداختی'],
            'today' => Carbon::now(JalaliPeriod::TIMEZONE)->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->policy->canCreate($request->user()), 403);

        $data = $request->validate([
            'party_id' => 'required|exists:parties,id',
            'direction' => ['required', Rule::in([Cheque::RECEIVABLE, Cheque::PAYABLE])],
            'amount' => 'required|integer|min:1',
            'due_date' => 'required|date',
            'serial' => 'nullable|string|max:60',
            'bank_name' => 'nullable|string|max:120',
            'reference' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        $party = Party::findOrFail($data['party_id']);
        $dueDate = Carbon::parse($data['due_date'], JalaliPeriod::TIMEZONE);
        $meta = array_intersect_key($data, array_flip(['bank_name', 'reference', 'description', 'notes']))
            + ['created_by' => $request->user()->id];

        try {
            $cheque = $data['direction'] === Cheque::RECEIVABLE
                ? $this->cheques->registerReceivable($party, (int) $data['amount'], $dueDate, $data['serial'] ?? null, $meta)
                : $this->cheques->registerPayable($party, (int) $data['amount'], $dueDate, $data['serial'] ?? null, $meta);
        } catch (InvalidArgumentException|PeriodLockedException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return redirect()->route('cheques.show', $cheque)
            ->with('success', 'چک ثبت و سند حسابداری آن صادر شد.');
    }

    public function show(Cheque $cheque): View
    {
        $cheque->load(['party', 'bankAccount', 'journalEntry.lines.account', 'settlementEntry.lines.account', 'creator']);

        $user = request()->user();

        return view('pages.cheques.show', [
            'title' => $cheque->directionLabel(),
            'cheque' => $cheque,
            'bankAccounts' => BankAccount::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canSettle' => $cheque->isPending() && $this->policy->canCreate($user),
            'canCancel' => $cheque->isPending() && $this->policy->canReverse($user),
            'canReverse' => $cheque->isSettled() && $this->policy->canReverse($user),
        ]);
    }

    public function clear(Request $request, Cheque $cheque): RedirectResponse
    {
        abort_unless($this->policy->canCreate($request->user()), 403);

        $data = $request->validate(['bank_account_id' => 'required|exists:bank_accounts,id']);

        return $this->run(
            fn () => $this->cheques->clear($cheque, (int) $data['bank_account_id'], $request->user()->id),
            'چک وصول شد و وجه آن به حساب نشست.'
        );
    }

    public function bounce(Request $request, Cheque $cheque): RedirectResponse
    {
        abort_unless($this->policy->canCreate($request->user()), 403);

        return $this->run(
            fn () => $this->cheques->bounce($cheque, $request->user()->id),
            'چک برگشت خورد. بدهی/طلب به حالت پیش از چک بازگشت.'
        );
    }

    public function cancel(Request $request, Cheque $cheque): RedirectResponse
    {
        $reason = $request->validate(['reason' => 'required|string|max:255'])['reason'];

        return $this->run(
            fn () => $this->cheques->cancel($cheque, $reason, $request->user()),
            'چک ابطال شد. سند ثبت اولیه باقی ماند و سند معکوس آن صادر شد.'
        );
    }

    public function reverse(Request $request, Cheque $cheque): RedirectResponse
    {
        $reason = $request->validate(['reason' => 'required|string|max:255'])['reason'];

        return $this->run(
            fn () => $this->cheques->reverseSettlement($cheque, $reason, $request->user()),
            'تسویهٔ چک برگشت خورد و چک دوباره در جریان است.'
        );
    }

    private function run(callable $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (OperationStateException|InvalidArgumentException|PeriodLockedException $e) {
            return back()->withErrors(['cheque' => $e->getMessage()]);
        }

        return back()->with('success', $message);
    }
}
