<?php

namespace App\Domain\Costing\Services;

use App\Domain\Costing\Models\CostItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;

class ExcelCostImporter
{
    /**
     * Import historical purchase costs from an XLSX with the header
     * name | sku | unit_cost | effective_date. Dry-run reports what would
     * happen without touching the database.
     */
    public function import(string $path, bool $dryRun = false, ?int $by = null): array
    {
        $report = ['rows' => 0, 'imported' => 0, 'skipped' => 0, 'new_items' => 0, 'errors' => [], 'dry_run' => $dryRun];

        $run = function () use ($path, $dryRun, $by, &$report) {
            foreach ($this->rows($path) as $index => $row) {
                $report['rows']++;

                $name = trim((string) ($row[0] ?? ''));
                $sku = trim((string) ($row[1] ?? '')) ?: null;
                $cost = (int) round((float) ($row[2] ?? 0));
                $date = $row[3] ?? null;

                if ($name === '' || $cost <= 0) {
                    $report['skipped']++;
                    $report['errors'][] = 'ردیف '.($index + 2).': نام یا مبلغ نامعتبر';

                    continue;
                }

                $item = $sku ? CostItem::firstWhere('sku', $sku) : null;
                $item ??= CostItem::firstWhere('name', $name);

                if (! $item) {
                    $report['new_items']++;
                    $item = $dryRun ? null : CostItem::create(['name' => $name, 'sku' => $sku]);
                }

                if (! $dryRun) {
                    $item->costHistory()->create([
                        'unit_cost' => $cost,
                        'landed_unit_cost' => $cost,
                        'source' => 'import',
                        'effective_at' => $this->parseDate($date),
                        'created_by' => $by,
                    ]);
                }

                $report['imported']++;
            }
        };

        $dryRun ? $run() : DB::transaction($run);

        return $report;
    }

    private function rows(string $path): \Generator
    {
        $reader = new Reader;
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $isHeader = true;
                $i = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    if ($isHeader) {
                        $isHeader = false;

                        continue;
                    }
                    yield $i++ => $row->toArray();
                }
                break; // first sheet only
            }
        } finally {
            $reader->close();
        }
    }

    private function parseDate(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return now()->toDateString();
        }
    }
}
