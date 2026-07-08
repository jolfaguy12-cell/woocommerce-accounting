<?php

namespace App\Console\Commands;

use App\Domain\Sync\Models\WebhookEvent;
use App\Domain\Sync\Services\WebhookProcessor;
use Illuminate\Console\Command;
use Throwable;

class SyncErrorsCommand extends Command
{
    protected $signature = 'acc:sync:errors {--retry : Re-process failed/dead events} {--json : Machine-readable output}';

    protected $description = 'Show failed/dead webhook events; optionally retry them';

    public function handle(WebhookProcessor $processor): int
    {
        $events = WebhookEvent::whereIn('status', ['failed', 'dead'])->orderBy('id')->get();

        if ($this->option('retry')) {
            $retried = ['done' => 0, 'still_failing' => 0];

            foreach ($events as $event) {
                // Manual retry gets a fresh attempt budget.
                $event->update(['status' => 'received', 'attempts' => 0]);

                try {
                    $processor->process($event->refresh());
                    $retried['done']++;
                } catch (Throwable) {
                    $retried['still_failing']++;
                }
            }

            $this->option('json')
                ? $this->line(json_encode($retried))
                : $this->info("retried: {$retried['done']} ok, {$retried['still_failing']} still failing");

            return self::SUCCESS;
        }

        $rows = $events->map(fn ($e) => [
            'event_uuid' => $e->event_uuid,
            'event_type' => $e->event_type,
            'status' => $e->status,
            'attempts' => $e->attempts,
            'last_error' => $e->last_error,
        ])->all();

        $this->option('json')
            ? $this->line(json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            : $this->table(['uuid', 'type', 'status', 'attempts', 'error'],
                array_map(fn ($r) => [$r['event_uuid'], $r['event_type'], $r['status'], $r['attempts'], mb_substr((string) $r['last_error'], 0, 60)], $rows));

        return self::SUCCESS;
    }
}
