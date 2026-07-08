<?php

namespace App\Console\Commands;

use App\Domain\Sync\Services\HubClient;
use Illuminate\Console\Command;
use Throwable;

class HubCheckCommand extends Command
{
    protected $signature = 'acc:hub:check {--json : Machine-readable output}';

    protected $description = 'Check connectivity and auth against the mirror hub data API';

    public function handle(HubClient $hub): int
    {
        try {
            $health = $hub->health();
            $result = ['ok' => true, 'base_url' => config('hub.base_url'), 'health' => $health];
        } catch (Throwable $e) {
            $result = ['ok' => false, 'base_url' => config('hub.base_url'), 'error' => $e->getMessage()];
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
        } else {
            $this->line($result['ok'] ? '✔ hub reachable: '.$result['base_url'] : '✘ hub unreachable: '.($result['error'] ?? ''));
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
