<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Receivables\Models\PartyPayment;
use App\Domain\Receivables\Services\PaymentRecorder;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * A single shared "edit this payment's note" endpoint, reused from both the
 * supplier transactions page and the bank-account ("Account Management")
 * ledger — both already display the same PartyPayment-linked journal lines.
 * The note edit itself is logged via PartyPayment::getActivitylogOptions().
 *
 * `reverse()` is the ONE reversal action for every PartyPayment — a salary
 * payment, a supplier settlement, an advance, a reimbursement — because they
 * are all rows in the same table with the same immutability rule: a posted
 * payment is never edited, only reversed. It lives here rather than on a
 * per-feature controller so a payment reversed from the employee page and one
 * reversed from the supplier page go through the exact same code.
 */
class PartyPaymentController extends Controller
{
    public function updateNote(Request $request, PartyPayment $payment): RedirectResponse
    {
        $data = $request->validate(['note' => 'nullable|string|max:500']);

        $payment->update(['note' => $data['note'] ?? null, 'updated_by' => $request->user()->id]);

        return back()->with('success', 'یادداشت تراکنش به‌روزرسانی شد.');
    }

    public function reverse(Request $request, PartyPayment $payment, PaymentRecorder $recorder): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:255'], [
            'reason.required' => 'دلیل برگشت باید ثبت شود.',
        ]);

        try {
            $recorder->reverse($payment, $data['reason'], $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('success', 'پرداخت برگشت خورد. سند اصلی دست‌نخورده ماند و سند معکوس آن ثبت شد.');
    }
}
