<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Receivables\Models\PartyPayment;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A single shared "edit this payment's note" endpoint, reused from both the
 * supplier transactions page and the bank-account ("Account Management")
 * ledger — both already display the same PartyPayment-linked journal lines.
 * The note edit itself is logged via PartyPayment::getActivitylogOptions().
 */
class PartyPaymentController extends Controller
{
    public function updateNote(Request $request, PartyPayment $payment): RedirectResponse
    {
        $data = $request->validate(['note' => 'nullable|string|max:500']);

        $payment->update(['note' => $data['note'] ?? null, 'updated_by' => $request->user()->id]);

        return back()->with('success', 'یادداشت تراکنش به‌روزرسانی شد.');
    }
}
