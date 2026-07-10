<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Expenses\Models\BankAccount;
use App\Domain\Expenses\Models\BankDeposit;
use App\Domain\Expenses\Services\ZibalDepositImporter;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BankDepositController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->only(['search', 'status', 'psp', 'date_from', 'date_to', 'bank_account_id']);

        $query = BankDeposit::with('bankAccount')->latest('deposited_at');

        if ($filters['search'] ?? null) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('external_reference', 'like', "%{$search}%")
                    ->orWhere('account_holder_name', 'like', "%{$search}%")
                    ->orWhere('tracking_id', 'like', "%{$search}%");
            });
        }
        if ($filters['status'] ?? null) {
            $query->where('status', $filters['status']);
        }
        if ($filters['psp'] ?? null) {
            $query->where('psp_label', $filters['psp']);
        }
        if ($filters['bank_account_id'] ?? null) {
            $query->where('bank_account_id', $filters['bank_account_id']);
        }
        if ($filters['date_from'] ?? null) {
            $query->whereDate('deposited_at', '>=', $filters['date_from']);
        }
        if ($filters['date_to'] ?? null) {
            $query->whereDate('deposited_at', '<=', $filters['date_to']);
        }

        $deposits = (clone $query)->paginate(20)->withQueryString();
        $totalAmount = (clone $query)->sum('amount_toman');

        return view('pages.bank-accounts.deposits.index', [
            'title' => 'واریزی‌های زیبال',
            'deposits' => $deposits,
            'filters' => $filters,
            'statuses' => BankDeposit::query()->select('status')->distinct()->whereNotNull('status')->pluck('status'),
            'pspLabels' => BankDeposit::query()->select('psp_label')->distinct()->whereNotNull('psp_label')->pluck('psp_label'),
            'bankAccounts' => BankAccount::orderBy('name')->get(['id', 'name']),
            'totalAmount' => $totalAmount,
        ]);
    }

    public function import(Request $request, ZibalDepositImporter $importer): RedirectResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        $import = $importer->import($request->file('file'), $request->user());

        $message = sprintf(
            'ایمپورت انجام شد: %d ردیف جدید، %d تکراری (نادیده گرفته شد)، %d حساب بانکی جدید شناسایی شد.',
            $import->new_count,
            $import->duplicate_count,
            $import->new_bank_accounts_count,
        );

        if ($import->date_parse_failed_count > 0) {
            $message .= sprintf(' توجه: تاریخ واریز %d ردیف قابل تشخیص نبود و آن ردیف‌ها ایمپورت نشدند — لطفاً فایل را بررسی کنید.', $import->date_parse_failed_count);
        }

        return redirect()->route('deposits.index')->with($import->date_parse_failed_count > 0 ? 'warning' : 'success', $message);
    }
}
