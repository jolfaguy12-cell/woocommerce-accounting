<?php

namespace App\Console\Commands;

use App\Domain\Sync\Services\SystemHealthService;
use Illuminate\Console\Command;

class HealthCommand extends Command
{
    protected $signature = 'acc:health {--json : Machine-readable output}';

    protected $description = 'Overall system health: database, hub, queue backlog, sync errors, open reviews';

    public function handle(SystemHealthService $health): int
    {
        $result = $health->snapshot();

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
