<?php

use App\Domain\Costing\Models\CostHistory;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Services\ExcelCostImporter;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

function makeCostXlsx(array $rows): string
{
    $path = sys_get_temp_dir().'/costs-'.uniqid().'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['name', 'sku', 'unit_cost', 'effective_date']));
    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    $writer->close();

    return $path;
}

it('previews an import in dry-run mode without writing anything', function () {
    $path = makeCostXlsx([
        ['اسپری رکسونا', 'RX-1', 480000, '2026-06-15'],
        ['رژ لب Von Gee', '', 200000, '2026-06-20'],
    ]);

    $report = app(ExcelCostImporter::class)->import($path, dryRun: true);

    expect($report['rows'])->toBe(2)
        ->and($report['new_items'])->toBe(2)
        ->and($report['dry_run'])->toBeTrue()
        ->and(CostItem::count())->toBe(0)
        ->and(CostHistory::count())->toBe(0);
});

it('imports historical costs, reusing existing items by sku or name', function () {
    CostItem::create(['name' => 'اسپری رکسونا', 'sku' => 'RX-1']);

    $path = makeCostXlsx([
        ['اسپری رکسونا', 'RX-1', 480000, '2026-06-15'],
        ['رژ لب Von Gee', '', 200000, '2026-06-20'],
    ]);

    $report = app(ExcelCostImporter::class)->import($path, dryRun: false);

    expect($report['imported'])->toBe(2)
        ->and(CostItem::count())->toBe(2)
        ->and(CostHistory::count())->toBe(2)
        ->and(CostHistory::first()->source)->toBe('import');
});

it('rejects rows with missing or non-positive cost', function () {
    $path = makeCostXlsx([
        ['اسپری رکسونا', '', 0, '2026-06-15'],
        ['', '', 100000, '2026-06-15'],
        ['رژ لب', '', 200000, '2026-06-20'],
    ]);

    $report = app(ExcelCostImporter::class)->import($path, dryRun: false);

    expect($report['imported'])->toBe(1)
        ->and($report['skipped'])->toBe(2)
        ->and(CostHistory::count())->toBe(1);
});
