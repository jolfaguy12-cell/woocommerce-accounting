<?php

namespace App\Domain\Receivables\Services;

use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Receivables\Models\Employee;
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
     *
     * The payable and advance lines are per EMPLOYEE and carry their party_id.
     * They used to be single aggregate lines with no party at all, which balanced
     * perfectly and told you nothing: the company's total salary debt was right,
     * while every individual employee's «مانده حقوق» read zero, because not one
     * journal line said whose salary it was. An unattributable balance is not a
     * balance — you cannot pay it, dispute it, or reconcile it.
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
            $payableLines = [];
            $advanceLines = [];

            foreach ($items as $item) {
                $advance = (int) ($item['advances_deducted'] ?? 0);
                $net = (int) $item['gross'] - $advance;

                $created = $run->items()->create($item + ['net' => $net]);
                $partyId = Employee::whereKey($created->employee_id)->value('party_id');

                $gross += (int) $item['gross'];

                if ($advance > 0) {
                    $advanceLines[] = [
                        'account' => self::EMPLOYEE_ADVANCES,
                        'credit' => $advance,
                        'party_id' => $partyId,
                    ];
                }

                if ($net > 0) {
                    $payableLines[] = [
                        'account' => self::SALARIES_PAYABLE,
                        'credit' => $net,
                        'party_id' => $partyId,
                    ];
                }
            }

            $lines = array_merge(
                [['account' => self::SALARY_EXPENSE, 'debit' => $gross]],
                $advanceLines,
                $payableLines,
            );

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
