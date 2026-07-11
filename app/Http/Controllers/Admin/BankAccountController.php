<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Expenses\Models\BankAccount;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BankAccountController extends Controller
{
    public function index(Request $request): View
    {
        $accounts = BankAccount::with('account')->where('is_active', true)->orderBy('name')->get()
            ->map(fn ($bankAccount) => [
                'model' => $bankAccount,
                'balance' => $bankAccount->account->balance(),
            ]);

        return view('pages.bank-accounts.index', [
            'title' => 'حساب‌ها',
            'accounts' => $accounts,
            // /new-bank-account renders this same list with the create modal opened.
            'openCreate' => $request->routeIs('bank-accounts.create'),
        ]);
    }

    public function store(Request $request, BankAccountManager $manager): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'bank_name' => 'nullable|string|max:100',
            'card_number' => 'nullable|string|max:25',
            'iban' => 'nullable|string|max:34',
            'is_cash' => 'boolean',
        ]);

        $manager->create($data);

        return redirect()->route('bank-accounts.index')->with('success', 'حساب جدید با حساب دفترکل اختصاصی ساخته شد.');
    }

    public function update(Request $request, BankAccount $bankAccount, BankAccountManager $manager): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'bank_name' => 'nullable|string|max:100',
            'card_number' => 'nullable|string|max:25',
            'iban' => 'nullable|string|max:34',
        ]);

        $manager->update($bankAccount, $data);

        return redirect()->route('bank-accounts.index')->with('success', 'حساب به‌روزرسانی شد.');
    }

    public function show(Request $request, BankAccount $bankAccount): View
    {
        $bankAccount->load('account');

        $search = $request->string('search')->trim()->value();
        $dateFrom = $request->string('date_from')->value();
        $dateTo = $request->string('date_to')->value();

        // Running balance reads chronologically forward regardless of any
        // filter/page, since "balance after this line" depends on every line
        // before it, not just the ones currently shown.
        $runningBalance = [];
        $balance = 0;
        $bankAccount->account->lines()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->orderBy('journal_entries.entry_date')->orderBy('journal_lines.id')
            ->get(['journal_lines.id', 'journal_lines.debit', 'journal_lines.credit'])
            ->each(function ($line) use (&$balance, &$runningBalance) {
                $balance += $line->debit - $line->credit;
                $runningBalance[$line->id] = $balance;
            });

        $transactions = $bankAccount->account->lines()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->with(['entry', 'party'])
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($w) use ($search) {
                    $w->where('journal_entries.description', 'like', "%{$search}%")
                        ->orWhereHas('party', fn ($p) => $p->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($dateFrom, fn ($q) => $q->whereDate('journal_entries.entry_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('journal_entries.entry_date', '<=', $dateTo))
            ->select('journal_lines.*')
            ->orderByDesc('journal_entries.entry_date')->orderByDesc('journal_lines.id')
            ->paginate(20)
            ->withQueryString();

        $transactions->getCollection()->transform(function ($line) use ($runningBalance) {
            $line->balance_after = $runningBalance[$line->id] ?? null;

            return $line;
        });

        return view('pages.bank-accounts.show', [
            'title' => 'حساب — '.$bankAccount->name,
            'bankAccount' => $bankAccount,
            'balance' => $bankAccount->account->balance(),
            'transactions' => $transactions,
            'filters' => $request->only('search', 'date_from', 'date_to'),
        ]);
    }
}
