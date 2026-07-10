<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Alerts\Models\AlertEvent;
use App\Domain\Alerts\Models\AlertType;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class AlertController extends Controller
{
    public function index(): View
    {
        return view('pages.tools.alerts.index', [
            'title' => 'هشدارها',
            'alertTypes' => AlertType::orderBy('name')->get(),
            'roles' => Role::orderBy('name')->pluck('name'),
            'recentEvents' => AlertEvent::with('alertType')
                ->latest('created_at')
                ->limit(20)
                ->get(),
        ]);
    }

    public function toggleActive(AlertType $alertType): RedirectResponse
    {
        $alertType->update(['is_active' => ! $alertType->is_active]);

        return back()->with('success', 'وضعیت هشدار به‌روزرسانی شد.');
    }

    public function updateRoles(Request $request, AlertType $alertType): RedirectResponse
    {
        $data = $request->validate([
            'roles' => 'array',
            'roles.*' => 'string|exists:roles,name',
        ]);

        $alertType->syncRoles($data['roles'] ?? []);

        return back()->with('success', 'نقش‌های هدف این هشدار به‌روزرسانی شد.');
    }

    public function updateTemplate(Request $request, AlertType $alertType): RedirectResponse
    {
        $data = $request->validate([
            'message_template' => 'required|string|max:2000',
        ]);

        $alertType->update($data);

        return back()->with('success', 'الگوی پیام به‌روزرسانی شد.');
    }
}
