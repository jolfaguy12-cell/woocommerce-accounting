<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Models\PartyBankAccount;
use App\Domain\Accounting\Services\PartyDuplicateService;
use App\Domain\Accounting\Services\PartyLedgerService;
use App\Domain\Accounting\Services\PartyMergeService;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Accounting\Support\PartyRoleType;
use App\Domain\Receivables\Models\Cheque;
use App\Domain\Receivables\Models\Loan;
use App\Domain\Receivables\Services\EmployeeAccountService;
use App\Domain\Receivables\Services\LoanService;
use App\Http\Controllers\Controller;
use App\Support\Design\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

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
        private readonly PartyMergeService $merges,
        private readonly EmployeeAccountService $employees,
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
            // A party that has been merged into another is not a separate identity
            // any more — listing it would offer the reader the duplicate they just
            // resolved.
            ->notMerged()
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

    /**
     * Server-side party search for <x-form.party-select>.
     *
     * Every financial form used to render its own `<select>` of the first 300–500
     * parties. With ~1,100 of them that is not a picker: the party you needed was
     * routinely absent, silently, and the form offered you somebody else. This
     * searches the whole table and pages — so one endpoint, one component, and no
     * form ever has to cap the list again.
     */
    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        $role = $request->query('role');
        $perPage = 20;

        $parties = Party::query()
            ->with('roles')
            ->notMerged() // a merged duplicate must never become selectable again
            ->when(filled($role) && in_array($role, PartyRoleType::values(), true),
                fn ($q) => $q->withRole($role))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('normalized_phone', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('national_id', 'like', "%{$term}%")))
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'results' => $parties->getCollection()->map(fn (Party $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'phone' => $p->phone,
                'roles' => $p->activeRoles()->map(fn ($r) => PartyRoleType::coerce($r->role)->label())->values(),
            ])->values(),
            'has_more' => $parties->hasMorePages(),
        ]);
    }

    public function show(Request $request, Party $party): View|RedirectResponse
    {
        // A merged party is the same person as its survivor, so its URL is not a
        // 404 — it is a redirect. Any link, bookmark or old report still lands on
        // the identity that is alive.
        if ($party->isMerged()) {
            return redirect()
                ->route('parties.show', $party->canonical())
                ->with('success', 'این طرف حساب ادغام شده است؛ پرونده اصلی نمایش داده می‌شود.');
        }

        $party->load('roles', 'bankAccounts', 'customerProfile', 'supplierProfile', 'partnerProfile', 'employee', 'aliases.mergedParty');

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
            'canMerge' => $request->user()?->hasRole('admin') ?? false,
            // «حساب کارمند» — six existing accounts read on one identity. No employee
            // ledger, no stored balance: see EmployeeAccountService.
            'employeeAccount' => $tab === 'employee' && $party->hasRole(PartyRoleType::Employee)
                ? $this->employees->summary($party)
                : null,
            'employeeContexts' => $tab === 'employee' && $party->hasRole(PartyRoleType::Employee)
                ? $this->employees->contexts($party)
                : [],
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
                    'next_due_fa' => $next?->due_date ? JalaliPeriod::fmtDate($next->due_date) : null,
                    'status' => $loan->status->badgeStatus(),
                    'status_label' => $loan->status->label(),
                ];
            });
    }

    /**
     * «بررسی موارد تکراری» — suggestions for a human, who then decides.
     *
     * The page is now actionable: each group can be merged, but only by a person,
     * only with a stated reason, and never automatically. A shared phone number is
     * evidence, not proof — two real people share a household line.
     */
    public function duplicates(): View
    {
        return view('pages.parties.duplicates', [
            'title' => 'بررسی موارد تکراری',
            'groups' => $this->duplicates->candidates(),
            'canMerge' => request()->user()?->hasRole('admin') ?? false,
        ]);
    }

    /**
     * «ادغام طرف حساب‌ها» — admin only, reason required, fully audited.
     *
     * Nothing in the ledger is rewritten: the absorbed party keeps its id and every
     * journal line it was ever posted against. See PartyMergeService.
     */
    public function merge(Request $request, Party $party): RedirectResponse
    {
        $data = $request->validate([
            'merged_party_id' => ['required', 'integer', 'different:'.$party->id, Rule::exists('parties', 'id')],
            'reason' => 'required|string|max:255',
        ], [
            'reason.required' => 'دلیل ادغام باید ثبت شود.',
        ]);

        try {
            $this->merges->merge(
                $party,
                Party::findOrFail($data['merged_party_id']),
                $data['reason'],
                $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['merged_party_id' => $e->getMessage()]);
        }

        return redirect()->route('parties.show', $party)->with(
            'success',
            'طرف حساب‌ها ادغام شدند. هیچ سند حسابداری تغییر نکرد؛ سوابق طرف حساب ادغام‌شده در همین پرونده تجمیع می‌شود.',
        );
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
