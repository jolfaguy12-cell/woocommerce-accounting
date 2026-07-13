<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\Setting;
use App\Domain\Reports\Models\PartnerReport;
use App\Domain\Sync\Models\WebhookEvent;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Spatie\Permission\Models\Role;

class SettingController extends Controller
{
    public function general(): View
    {
        return view('pages.system-settings.general', [
            'title' => 'تنظیمات کلی',
            'config' => [
                'app_name' => config('app.name'),
                'timezone' => config('app.timezone'),
                'environment' => App::environment(),
                'currency_divisor' => config('accounting.currency_divisor'),
                'low_stock_threshold' => config('accounting.low_stock_threshold'),
                'queue_connection' => config('queue.default'),
            ],
        ]);
    }

    public function reportSettings(): View
    {
        return view('pages.system-settings.report-settings', [
            'title' => 'تنظیمات گزارشات',
            'periods' => AccountingPeriod::orderByDesc('jalali_period')->limit(12)
                ->get(['id', 'jalali_period', 'status', 'locked_at']),
            'reportCounts' => PartnerReport::selectRaw('state, count(*) as count')
                ->groupBy('state')->pluck('count', 'state'),
        ]);
    }

    public function roleManagement(): View
    {
        return view('pages.system-settings.role-management', [
            'title' => 'مدیریت نقش‌ها',
            'roles' => Role::withCount('users')->orderBy('name')->get()
                ->map(fn (Role $r) => ['id' => $r->id, 'name' => $r->name, 'users_count' => $r->users_count]),
            'totalUsers' => User::count(),
        ]);
    }

    public function apiWebhookManagement(): View
    {
        $telegramToken = Setting::getEncrypted('telegram_bot_token');

        return view('pages.system-settings.api-webhook-management', [
            'title' => 'مدیریت وبهوک‌ها و API',
            'hub' => [
                'base_url' => config('hub.base_url'),
                'api_key_configured' => filled(config('hub.api_key')),
                'webhook_secret_configured' => filled(config('hub.webhook_secret')),
                'webhook_max_attempts' => config('hub.webhook_max_attempts'),
                'webhook_endpoint' => route('webhooks.hub'),
            ],
            'webhookEventCounts' => WebhookEvent::selectRaw('status, count(*) as count')
                ->groupBy('status')->pluck('count', 'status'),
            // Never pass the raw token to the view — only whether it's set and a masked hint.
            'telegram' => [
                'configured' => filled($telegramToken),
                'masked' => $telegramToken ? '••••'.substr($telegramToken, -4) : null,
            ],
        ]);
    }

    /** Saves the Telegram bot token, encrypted at rest — never logged or shown back in full (see Setting::setEncrypted()). */
    public function updateTelegram(Request $request): RedirectResponse
    {
        $data = $request->validate(['bot_token' => 'required|string|min:20|max:255']);

        Setting::setEncrypted('telegram_bot_token', $data['bot_token']);

        return back()->with('success', 'کلید ربات تلگرام ذخیره شد.');
    }

    public function resetTelegram(): RedirectResponse
    {
        Setting::setEncrypted('telegram_bot_token', null);

        return back()->with('success', 'کلید ربات تلگرام حذف شد.');
    }
}
