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

    public function show(BankAccount $bankAccount): View
    {
        $bankAccount->load('account');

        $transactions = $bankAccount->account->lines()
            ->with(['entry', 'party'])
            ->get()
            ->sortByDesc(fn ($line) => $line->entry->entry_date)
            ->values();

        return view('pages.bank-accounts.show', [
            'title' => 'حساب — '.$bankAccount->name,
            'bankAccount' => $bankAccount,
            'balance' => $bankAccount->account->balance(),
            'transactions' => $transactions,
        ]);
    }
}
