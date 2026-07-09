<?php

namespace App\Domain\Sync\Services;

use App\Domain\Sync\Models\ReviewItem;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Shared by `acc:health` and the system-status admin page — keep both in sync by construction. */
class SystemHealthService
{
    public function __construct(private readonly HubClient $hub) {}

    public function snapshot(): array
    {
        $database = true;
        try {
            DB::select('SELECT 1');
        } catch (Throwable) {
            $database = false;
        }

        $hubOk = true;
        $hubError = null;
        try {
            $this->hub->health();
        } catch (Throwable $e) {
            $hubOk = false;
            $hubError = mb_substr($e->getMessage(), 0, 200);
        }

        return [
            'database' => $database,
            'hub' => $hubOk,
            'hub_error' => $hubError,
            'pending_jobs' => (int) DB::table('jobs')->count(),
            'failed_jobs' => (int) DB::table('failed_jobs')->count(),
            'dead_webhook_events' => WebhookEvent::where('status', 'dead')->count(),
            'open_review_items' => ReviewItem::where('status', 'open')->count(),
            'last_order_poll' => SyncRun::where('type', 'poll_orders')->where('status', 'done')
                ->latest('finished_at')->value('finished_at')?->toIso8601String(),
            'last_product_poll' => SyncRun::where('type', 'poll_products')->where('status', 'done')
                ->latest('finished_at')->value('finished_at')?->toIso8601String(),
            'last_backfill' => SyncRun::where('type', 'backfill_orders')->where('status', 'done')
                ->latest('finished_at')->value('finished_at')?->toIso8601String(),
            'ok' => $database && $hubOk,
        ];
    }
}
