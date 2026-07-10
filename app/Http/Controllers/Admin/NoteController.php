<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderNote;
use App\Domain\Orders\Models\OrderNoteRecipient;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class NoteController extends Controller
{
    /** Notifications > Notes inbox: written by me or assigned to me; viewing it clears my unread badge. */
    public function index(Request $request): View
    {
        $userId = $request->user()->id;

        $notes = OrderNote::with('order', 'author', 'recipients.user')
            ->where(function ($q) use ($userId) {
                $q->where('created_by', $userId)
                    ->orWhereHas('recipients', fn ($r) => $r->where('user_id', $userId));
            })
            ->latest()
            ->paginate(20);

        OrderNoteRecipient::where('user_id', $userId)->whereNull('read_at')->update(['read_at' => now()]);

        return view('pages.notifications.notes', ['title' => 'یادداشت‌ها', 'notes' => $notes]);
    }

    public function store(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'body' => 'required|string|max:2000',
            'recipients' => 'nullable|array',
            'recipients.*' => 'integer|exists:users,id',
        ]);

        $note = $order->notes()->create([
            'body' => $data['body'],
            'created_by' => $request->user()->id,
        ]);

        $recipientIds = array_diff($data['recipients'] ?? [], [$request->user()->id]);
        foreach ($recipientIds as $userId) {
            $note->recipients()->create(['user_id' => $userId]);
        }

        return back()->with('success', 'یادداشت ثبت شد.');
    }

    public function destroy(Request $request, OrderNote $note): RedirectResponse
    {
        abort_unless($note->created_by === $request->user()->id || $request->user()->hasRole('admin'), 403);

        $note->delete();

        return back()->with('success', 'یادداشت حذف شد.');
    }

    /** Recipient options for the note form — everyone except the current user. */
    public static function recipientOptions(int $exceptUserId): Collection
    {
        return User::where('id', '!=', $exceptUserId)->orderBy('name')->get(['id', 'name']);
    }
}
