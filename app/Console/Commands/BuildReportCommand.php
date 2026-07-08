<?php

namespace App\Console\Commands;

use App\Domain\Reports\Services\PartnerReportService;
use Illuminate\Console\Command;

class BuildReportCommand extends Command
{
    protected $signature = 'acc:report:build {jalali_period : e.g. 1405-04} {--json : Machine-readable output}';

    protected $description = 'Build/refresh the draft partner report for a Jalali period';

    public function handle(PartnerReportService $service): int
    {
        $report = $service->build($this->argument('jalali_period'));

        if ($this->option('json')) {
            $this->line(json_encode([
                'state' => $report->state,
                'readiness' => $report->readiness,
                'data' => $report->draftData(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $data = $report->draftData();
        $this->info("گزارش دوره {$report->jalali_period} — وضعیت: {$report->state}");
        $this->line('سفارش‌ها: '.$data['orders']['count'].' | فروش خالص: '.number_format($data['orders']['net_sales']).' تومان');
        $this->line('سود عملیاتی: '.number_format($data['orders']['operational_profit']).' | سود خالص دوره: '.number_format($data['net_period_profit']));

        if (! $report->readiness['ready']) {
            $this->warn('موارد باز: '.json_encode($report->readiness['issues'], JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
