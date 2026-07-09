<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Reports\Models\PartnerReport;
use App\Domain\Sync\Models\WebhookEvent;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
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
        ]);
    }
}
