<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Exceptions\OperationStateException;
use App\Domain\Accounting\Exceptions\PeriodLockedException;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Models\PartyOffset;
use App\Domain\Accounting\Services\PartyLedgerService;
use App\Domain\Accounting\Services\PartyOffsetService;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Accounting\Support\OperationPolicy;
use App\Domain\Accounting\Support\OperationStatus;
use App\Domain\Accounting\Support\PartyOffsetType;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * «حساب‌های دوطرفه» — the offset workflow, at the /mutual-accounts URLs the
 * sidebar has been pointing at since long before they existed.
 */
class PartyOffsetController extends Controller
{
    public function __construct(
        private readonly PartyOffsetService $offsets,
        private readonly PartyLedgerService $ledger,
        private readonly OperationPolicy $policy,
    ) {}

    public function index(Request $request): View
    {
        $status = $request->string('status')->value();

        return view('pages.mutual-accounts.index', [
            'title' => 'حساب‌های دوطرفه',
            'offsets' => PartyOffset::with(['party', 'creator'])
                ->when($status, fn ($q) => $q->where('status', $status))
                ->latest('id')->paginate(25)->withQueryString(),
            'statuses' => OperationStatus::cases(),
            'filters' => ['status' => $status],
        ]);
    }

    /**
     * Only parties who actually HAVE something to offset are offered, each with the
     * three caps already computed. An offset form listing every party in the
     * database would be a form whose every option but a handful is a dead end.
     */
    public function create(): View
    {
        $candidates = Party::orderBy('name')->get()
            ->map(fn (Party $party) => [
                'id' => $party->id,
                'name' => $party->name,
                'caps' => $this->offsets->eligibleAmounts($party),
            ])
            ->filter(fn (array $row) => array_sum($row['caps']) > 0)
            ->values();

        return view('pages.mutual-accounts.create', [
            'title' => 'ثبت تهاتر جدید',
            'candidates' => $candidates,
            'types' => PartyOffsetType::options(),
            'today' => Carbon::now(JalaliPeriod::TIMEZONE)->toDateString(),
            'approvalThreshold' => $this->policy->approvalThreshold(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->policy->canCreate($request->user()), 403);

        $data = $request->validate([
            'party_id' => 'required|exists:parties,id',
            'type' => ['required', Rule::in(array_keys(PartyOffsetType::options()))],
            'amount' => 'required|integer|min:1',
            'offset_date' => 'required|date',
            'reason' => 'required|string|max:255',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $offset = $this->offsets->create($data + [
                'party' => Party::findOrFail($data['party_id']),
                'created_by' => $request->user()->id,
            ]);
        } catch (InvalidArgumentException|PeriodLockedException $e) {
            // An over-cap offset is a real validation failure, not a server error.
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return redirect()
            ->route('mutual-accounts.show', $offset)
            ->with('success', $offset->isPendingApproval()
                ? 'تهاتر ثبت شد و در انتظار تأیید است. تا زمان تأیید، هیچ سندی در دفتر ثبت نمی‌شود.'
                : 'تهاتر ثبت و سند حسابداری آن صادر شد.');
    }

    public function show(Request $request, PartyOffset $offset): View
    {
        $offset->load(['party', 'journalEntry.lines.account', 'reversalEntry',
            'creator', 'approver', 'reverser', 'canceller']);

        return view('pages.mutual-accounts.show', [
            'title' => 'تهاتر',
            'offset' => $offset,
            'balances' => $this->ledger->balances($offset->party),
            'canApprove' => $offset->isPendingApproval() && $this->policy->canApprove($request->user(), $offset),
            'canReverse' => $offset->isPosted() && $this->policy->canReverse($request->user()),
            'canCancel' => $offset->operationStatus()->isCancellable(),
        ]);
    }

    public function approve(Request $request, PartyOffset $offset): RedirectResponse
    {
        return $this->control($request, $offset, 'approve');
    }

    public function reverse(Request $request, PartyOffset $offset): RedirectResponse
    {
        return $this->control($request, $offset, 'reverse');
    }

    public function cancel(Request $request, PartyOffset $offset): RedirectResponse
    {
        return $this->control($request, $offset, 'cancel');
    }

    private function control(Request $request, PartyOffset $offset, string $action): RedirectResponse
    {
        $user = $request->user();
        $reason = $action === 'approve' ? null : $request->validate(['reason' => 'required|string|max:255'])['reason'];

        try {
            match ($action) {
                'approve' => $this->offsets->approve($offset, $user),
                'reverse' => $this->offsets->reverse($offset, $reason, $user),
                'cancel' => $this->offsets->cancel($offset, $reason, $user),
            };
        } catch (OperationStateException|PeriodLockedException $e) {
            return back()->withErrors(['operation' => $e->getMessage()]);
        }

        return back()->with('success', match ($action) {
            'approve' => 'تهاتر تأیید و سند آن در دفتر ثبت شد.',
            'reverse' => 'تهاتر برگشت خورد. سند اصلی دست‌نخورده ماند و سند معکوس آن صادر شد.',
            'cancel' => 'تهاتر لغو شد. هیچ سندی در دفتر ثبت نشده بود.',
        });
    }
}
