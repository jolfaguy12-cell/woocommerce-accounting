<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Alerts\Models\AlertDelivery;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/** In-app delivery of the role-based alert framework — parity with NoteController's order-notes bell. */
class AlertNotificationController extends Controller
{
    public function index(): View
    {
        $deliveries = AlertDelivery::with('event.alertType')
            ->where('user_id', auth()->id())
            ->where('channel', 'in_app')
            ->latest('created_at')
            ->limit(50)
            ->get();

        AlertDelivery::where('user_id', auth()->id())
            ->where('channel', 'in_app')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return view('pages.notifications.alerts', [
            'title' => 'اعلان‌های سیستم',
            'deliveries' => $deliveries,
        ]);
    }

    /** Clicking a bell item: mark read, then land on whatever the alert links to. */
    public function open(AlertDelivery $delivery): RedirectResponse
    {
        abort_unless($delivery->user_id === auth()->id(), 404);

        if (! $delivery->read_at) {
            $delivery->update(['read_at' => now()]);
        }

        return $delivery->event->url ? redirect($delivery->event->url) : back();
    }
}
