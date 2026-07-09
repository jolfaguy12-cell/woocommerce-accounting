<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Models\WebhookEvent;
use App\Domain\Sync\Services\SystemHealthService;
use App\Domain\Sync\Services\WebhookProcessor;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Throwable;

class ToolsController extends Controller
{
    public function backup(): View
    {
        return view('pages.tools.backup', ['title' => 'بکاپ و بازیابی']);
    }

    public function systemStatus(SystemHealthService $health): View
    {
        return view('pages.tools.system-status', [
            'title' => 'وضعیت سیستم',
            'status' => $health->snapshot(),
        ]);
    }

    public function systemLogs(): View
    {
        return view('pages.tools.system-logs', [
            'title' => 'لاگ سیستم',
            'webhookEvents' => WebhookEvent::whereIn('status', ['failed', 'dead'])
                ->orderByDesc('id')
                ->limit(50)
                ->get(['id', 'event_uuid', 'event_type', 'status', 'attempts', 'last_error', 'correlation_id', 'created_at']),
            'syncRuns' => SyncRun::orderByDesc('id')
                ->limit(20)
                ->get(['id', 'type', 'status', 'stats', 'started_at', 'finished_at']),
        ]);
    }

    /** Mirrors `acc:sync:errors --retry`: gives failed/dead webhook events a fresh attempt budget. */
    public function retryWebhookEvents(WebhookProcessor $processor): RedirectResponse
    {
        $events = WebhookEvent::whereIn('status', ['failed', 'dead'])->get();
        $done = 0;
        $stillFailing = 0;

        foreach ($events as $event) {
            $event->update(['status' => 'received', 'attempts' => 0]);

            try {
                $processor->process($event->refresh());
                $done++;
            } catch (Throwable) {
                $stillFailing++;
            }
        }

        return back()->with('success', "تلاش مجدد انجام شد: {$done} موفق، {$stillFailing} همچنان ناموفق.");
    }
}
