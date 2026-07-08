<?php

namespace App\Console\Commands;

use App\Domain\Sync\Models\ReviewItem;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Models\WebhookEvent;
use App\Domain\Sync\Services\HubClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthCommand extends Command
{
    protected $signature = 'acc:health {--json : Machine-readable output}';

    protected $description = 'Overall system health: database, hub, queue backlog, sync errors, open reviews';

    public function handle(HubClient $hub): int
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
            $hub->health();
        } catch (Throwable $e) {
            $hubOk = false;
            $hubError = mb_substr($e->getMessage(), 0, 200);
        }

        $result = [
            'database' => $database,
            'hub' => $hubOk,
            'hub_error' => $hubError,
            'pending_jobs' => (int) DB::table('jobs')->count(),
            'failed_jobs' => (int) DB::table('failed_jobs')->count(),
            'dead_webhook_events' => WebhookEvent::where('status', 'dead')->count(),
            'open_review_items' => ReviewItem::where('status', 'open')->count(),
            'last_order_poll' => SyncRun::where('type', 'poll_orders')->where('status', 'done')
                ->latest('finished_at')->value('finished_at')?->toIso8601String(),
            'ok' => $database && $hubOk,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($result as $key => $value) {
                $this->line(sprintf('%-22s %s', $key.':', is_bool($value) ? ($value ? '✔' : '✘') : ($value ?? '—')));
            }
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
