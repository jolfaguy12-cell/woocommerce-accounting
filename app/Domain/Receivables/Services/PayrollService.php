<?php

namespace App\Domain\Receivables\Services;

use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Receivables\Models\PayrollRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PayrollService
{
    private const SALARY_EXPENSE = '6100';

    private const EMPLOYEE_ADVANCES = '1400';

    private const SALARIES_PAYABLE = '2300';

    public function __construct(private readonly JournalPoster $poster) {}

    /**
     * Post one period's payroll: Dr salary expense (gross), Cr recovered
     * advances, Cr net salaries payable. Payment happens separately.
     */
    public function post(string $jalaliPeriod, array $items, ?int $by = null): PayrollRun
    {
        return DB::transaction(function () use ($jalaliPeriod, $items, $by) {
            $run = PayrollRun::create([
                'uuid' => (string) Str::uuid(),
                'jalali_period' => $jalaliPeriod,
                'run_date' => Carbon::now(JalaliPeriod::TIMEZONE)->toDateString(),
                'status' => 'draft',
                'created_by' => $by,
            ]);

            $gross = 0;
            $advances = 0;

            foreach ($items as $item) {
                $net = $item['gross'] - ($item['advances_deducted'] ?? 0);
                $run->items()->create($item + ['net' => $net]);
                $gross += $item['gross'];
                $advances += $item['advances_deducted'] ?? 0;
            }

            $lines = [['account' => self::SALARY_EXPENSE, 'debit' => $gross]];
            if ($advances > 0) {
                $lines[] = ['account' => self::EMPLOYEE_ADVANCES, 'credit' => $advances];
            }
            $lines[] = ['account' => self::SALARIES_PAYABLE, 'credit' => $gross - $advances];

            $entry = $this->poster->post([
                'entry_date' => Carbon::now(JalaliPeriod::TIMEZONE),
                'description' => "حقوق دوره {$jalaliPeriod}",
                'idempotency_key' => "payroll:{$run->uuid}",
                'source' => $run,
                'created_by' => $by,
            ], $lines);

            $run->update(['status' => 'posted', 'journal_entry_id' => $entry->id]);

            return $run->load('journalEntry.lines', 'items');
        });
    }
}
