<?php

namespace App\Console\Commands;

use App\Domain\Costing\Services\ExcelCostImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportCostsCommand extends Command
{
    protected $signature = 'acc:import:costs {file} {--dry-run : Preview without writing} {--json : Machine-readable output}';

    protected $description = 'Import historical purchase costs from an XLSX (name | sku | unit_cost | effective_date)';

    public function handle(ExcelCostImporter $importer): int
    {
        $file = $this->argument('file');

        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        try {
            $report = $importer->import($file, dryRun: (bool) $this->option('dry-run'));
        } catch (Throwable $e) {
            $this->error("Import failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $mode = $report['dry_run'] ? '[DRY RUN] ' : '';
            $this->info("{$mode}rows: {$report['rows']}, imported: {$report['imported']}, skipped: {$report['skipped']}, new items: {$report['new_items']}");
            foreach ($report['errors'] as $error) {
                $this->warn("  {$error}");
            }
        }

        return self::SUCCESS;
    }
}
