<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Models\PartyBankAccount;
use App\Domain\Accounting\Services\PartyDuplicateService;
use App\Domain\Accounting\Services\PartyLedgerService;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Accounting\Support\PartyRoleType;
use App\Domain\Receivables\Models\Cheque;
use App\Domain\Receivables\Models\Loan;
use App\Domain\Receivables\Services\LoanService;
use App\Http\Controllers\Controller;
use App\Support\Design\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The unified Party profile («پرونده طرف حساب») — one screen for one real
 * person or company, whatever roles they hold.
 *
 * The existing customer and supplier pages are NOT replaced: they remain
 * role-filtered views over the same Party identity (a supplier's purchase
 * history and pay/refund actions live there, and always did). What was missing,
 * and is what this adds, is the identity itself: which roles a party holds,
 * every balance it carries across all of them, and its complete statement.
 */
class PartyController extends Controller
{
    public function __construct(
        private readonly PartyLedgerService $ledger,
        private readonly PartyDuplicateService $duplicates,
        private readonly LoanService $loans,
    ) {}

    public function index(Request $request): View
    {
        $query = new TableQuery(
            request: $request,
            sortable: ['name' => 'name', 'id' => 'id', 'created_at' => 'created_at'],
            filters: ['role', 'kind'],
            defaultSort: 'name',
        );

        $role = $request->input('role');
        $kind = $request->input('kind');

        $parties = Party::query()
            ->with('roles')
            ->when(filled($role), fn ($q) => $q->withRole($role))
            ->when(filled($kind), fn ($q) => $q->where('party_kind', $kind))
            ->when($query->search(), fn ($q, string $search) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('normalized_phone', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('national_id', 'like', "%{$search}%")))
            ->tap(fn ($q) => $query->apply($q))
            ->paginate($query->perPage())
            ->withQueryString();

        return view('pages.parties.index', [
            'title' => 'طرف حساب‌ها',
            'parties' => $parties,
            'query' => $query,
            'filters' => $request->only('search', 'role', 'kind'),
            'roles' => PartyRoleType::cases(),
        ]);
    }

    public function show(Request $request, Party $party): View
    {
        $party->load('roles', 'bankAccounts', 'customerProfile', 'supplierProfile', 'partnerProfile', 'employee');

        $tab = $request->query('tab', 'overview');

        $statementQuery = new TableQuery(
            request: $request,
            sortable: [],
            defaultSort: '',
        );

        return view('pages.parties.show', [
            'title' => $party->name,
            'party' => $party,
            'tab' => $tab,
            'activeRoles' => $party->activeRoles(),
            'allRoles' => PartyRoleType::cases(),
            'balances' => $this->ledger->balances($party),
            'consolidated' => $this->ledger->consolidatedPosition($party),
            // Only paid for on the tab that shows it — a party with thousands of
            // journal lines should not pay for that query to render its overview.
            'statement' => $tab === 'statement' ? $this->ledger->statement($party, $statementQuery) : null,
            'statementQuery' => $statementQuery,
            'duplicateMatches' => $tab === 'duplicates' ? $this->duplicates->matchesFor($party) : collect(),
            // Same rule as the statement: a tab pays for its own query and no other's.
            // «مانده اصل وام» is read from the ledger per loan, never from a column.
            'loans' => $tab === 'loans' ? $this->partyLoans($party) : collect(),
            'cheques' => $tab === 'cheques'
                ? Cheque::where('party_id', $party->id)->with('bankAccount')->orderBy('due_date')->get()
                : collect(),
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function partyLoans(Party $party)
    {
        return Loan::where('party_id', $party->id)
            ->with(['bankAccount', 'installments'])
            ->orderByDesc('id')
            ->get()
            ->map(function (Loan $loan) {
                $next = $loan->nextInstallment();
                $paid = $this->loans->paidTotals($loan);

                return [
                    'loan' => $loan,
                    'direction' => $loan->direction->label(),
                    'principal' => (int) $loan->principal,
                    'remaining_principal' => $this->loans->remainingPrincipal($loan),
                    'paid_total' => $paid['total'],
                    'next_due_fa' => $next?->due_date ? JalaliPeriod::fmtDateTime($next->due_date) : null,
                    'status' => $loan->status->badgeStatus(),
                    'status_label' => $loan->status->label(),
                ];
            });
    }

    /** Duplicate REVIEW: suggestions for a human. Nothing here merges anything. */
    public function duplicates(): View
    {
        return view('pages.parties.duplicates', [
            'title' => 'بررسی موارد تکراری',
            'groups' => $this->duplicates->candidates(),
        ]);
    }

    public function activateRole(Request $request, Party $party): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in(PartyRoleType::values())],
        ]);

        $party->activateRole($data['role'], $request->user()->id);

        return back()->with('success', 'نقش «'.PartyRoleType::coerce($data['role'])->label().'» فعال شد.');
    }

    public function deactivateRole(Request $request, Party $party): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in(PartyRoleType::values())],
        ]);

        $party->deactivateRole($data['role'], $request->user()->id);

        return back()->with('success', 'نقش «'.PartyRoleType::coerce($data['role'])->label().'» غیرفعال شد. سوابق مالی دست‌نخورده باقی می‌ماند.');
    }

    public function storeBankAccount(Request $request, Party $party): RedirectResponse
    {
        $data = $request->validate([
            'bank_name' => 'nullable|string|max:100',
            'account_holder' => 'nullable|string|max:150',
            'account_number' => 'nullable|string|max:50',
            'card_number' => 'nullable|string|max:32',
            'iban' => 'nullable|string|max:34',
            'notes' => 'nullable|string|max:255',
            'is_default' => 'nullable|boolean',
        ]);

        if (blank($data['account_number'] ?? null) && blank($data['card_number'] ?? null) && blank($data['iban'] ?? null)) {
            return back()->withErrors(['account_number' => 'حداقل یکی از شماره حساب، شماره کارت یا شبا لازم است.']);
        }

        $isDefault = (bool) ($data['is_default'] ?? false) || $party->bankAccounts()->active()->doesntExist();

        if ($isDefault) {
            $party->bankAccounts()->update(['is_default' => false]);
        }

        $party->bankAccounts()->create($data + [
            'is_default' => $isDefault,
            'is_active' => true,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'حساب بانکی طرف حساب ثبت شد.');
    }

    /** Deactivates, never deletes — a payment may already reference this account. */
    public function destroyBankAccount(Party $party, PartyBankAccount $bankAccount): RedirectResponse
    {
        abort_unless($bankAccount->party_id === $party->id, 404);

        $bankAccount->update(['is_active' => false, 'is_default' => false]);

        return back()->with('success', 'حساب بانکی غیرفعال شد.');
    }
}
