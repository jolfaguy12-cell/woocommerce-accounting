<?php

namespace App\Domain\Reports\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Channels\Models\Channel;
use App\Domain\Channels\Services\ChannelCostService;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Orders\Models\OrderProfit;
use App\Domain\Receivables\Models\PayrollRun;
use App\Domain\Reports\Exceptions\ReportNotReadyException;
use App\Domain\Reports\Models\PartnerReport;
use App\Domain\Reports\Models\ReportAdjustment;
use App\Domain\Sync\Models\ReviewItem;
use App\Domain\Sync\Models\WebhookEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PartnerReportService
{
    public function __construct(
        private readonly ChannelCostService $channelCosts,
        private readonly JournalPoster $poster,
    ) {}

    /** (Re)build the draft aggregates + readiness checklist for a Jalali period. */
    public function build(string $jalaliPeriod): PartnerReport
    {
        $data = $this->aggregate($jalaliPeriod);
        $readiness = $this->readiness($jalaliPeriod);

        $report = PartnerReport::firstOrCreate(['jalali_period' => $jalaliPeriod], ['state' => 'draft']);

        if (! in_array($report->state, ['final', 'adjusted'], true)) {
            $report->update([
                'draft_data' => $data,
                'readiness' => $readiness,
                'state' => $readiness['ready'] ? 'draft' : 'needs_review',
            ]);
        }

        return $report->refresh();
    }

    /** Freeze the report: immutable snapshot + period lock. Corrections become adjustments. */
    public function finalize(PartnerReport $report, bool $acknowledgeWarnings = false, ?int $by = null): PartnerReport
    {
        return DB::transaction(function () use ($report, $acknowledgeWarnings, $by) {
            $readiness = $this->readiness($report->jalali_period);

            if (! $readiness['ready'] && ! $acknowledgeWarnings) {
                throw new ReportNotReadyException(
                    'Report has open issues: '.json_encode($readiness['issues'], JSON_UNESCAPED_UNICODE),
                );
            }

            $report->update([
                'draft_data' => $this->aggregate($report->jalali_period),
                'snapshot' => $this->aggregate($report->jalali_period)
                    + ['finalized' => ['at' => now()->toIso8601String(), 'acknowledged_warnings' => ! $readiness['ready']]],
                'readiness' => $readiness,
                'state' => 'final',
                'finalized_by' => $by,
                'finalized_at' => now(),
            ]);

            [$start] = JalaliPeriod::boundsFor($report->jalali_period);
            AccountingPeriod::forDate($start)->update([
                'status' => 'locked', 'locked_by' => $by, 'locked_at' => now(),
            ]);

            return $report->refresh();
        });
    }

    /** Post-finalize correction: entry lands in the CURRENT open period; snapshot untouched. */
    public function addAdjustment(PartnerReport $report, string $description, array $lines, ?int $by = null): ReportAdjustment
    {
        return DB::transaction(function () use ($report, $description, $lines, $by) {
            $adjustment = $report->adjustments()->create([
                'description' => $description,
                'created_by' => $by,
            ]);

            $entry = $this->poster->post([
                'entry_date' => Carbon::now(JalaliPeriod::TIMEZONE),
                'description' => "تعدیل گزارش {$report->jalali_period}: {$description}",
                'idempotency_key' => "report_adjustment:{$report->id}:{$adjustment->id}",
                'source' => $report,
                'created_by' => $by,
            ], $lines);

            $adjustment->update(['journal_entry_id' => $entry->id]);
            $report->update(['state' => 'adjusted']);

            return $adjustment->load('journalEntry');
        });
    }

    private function aggregate(string $period): array
    {
        $profits = OrderProfit::whereIn('order_profits.status', ['final', 'provisional'])
            ->whereHas('order', fn ($q) => $q->where('jalali_period', $period))
            ->get();

        $orders = [
            'count' => $profits->count(),
            'gross_sales' => (int) $profits->sum('gross_sale'),
            'discounts' => (int) $profits->sum('discounts'),
            'net_sales' => (int) $profits->sum('net_sale'),
            'product_cost' => (int) $profits->sum('product_cost'),
            'shipping_charged' => (int) $profits->sum('shipping_charged'),
            'shipping_real' => (int) $profits->sum('shipping_real'),
            'channel_fees' => (int) $profits->sum('channel_fee'),
            'gross_profit' => (int) $profits->sum('gross_profit'),
            'operational_profit' => (int) $profits->sum('operational_profit'),
            'average_order_value' => $profits->count() ? (int) round($profits->sum('net_sale') / $profits->count()) : 0,
            'provisional_count' => $profits->where('status', 'provisional')->count(),
        ];

        $expensesQuery = Expense::where('jalali_period', $period)
            ->where('affects_partner_profit', true)->where('is_capital', false);
        $expenses = [
            'total_affecting_partner' => (int) $expensesQuery->sum('amount'),
            'by_category' => Expense::where('jalali_period', $period)->where('affects_partner_profit', true)
                ->join('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
                ->groupBy('expense_categories.name')
                ->selectRaw('expense_categories.name, SUM(expenses.amount) as total')
                ->pluck('total', 'name')->map(fn ($v) => (int) $v)->all(),
        ];

        $payroll = (int) PayrollRun::where('jalali_period', $period)->where('status', 'posted')
            ->join('payroll_items', 'payroll_items.payroll_run_id', '=', 'payroll_runs.id')
            ->sum('payroll_items.gross');

        $channelCosts = [];
        $channelBreakdown = [];
        foreach (Channel::where('is_active', true)->get() as $channel) {
            $cost = $this->channelCosts->periodCost($channel, $period);
            if (in_array($channel->cost_model, ['wallet_topup', 'manual_period'], true)) {
                $channelCosts[$channel->slug] = $cost;
            }

            $channelProfits = $profits->filter(fn ($p) => $p->order->channel_id === $channel->id);
            if ($channelProfits->isNotEmpty() || $cost > 0) {
                $channelBreakdown[$channel->slug] = [
                    'name' => $channel->name,
                    'orders' => $channelProfits->count(),
                    'net_sales' => (int) $channelProfits->sum('net_sale'),
                    'operational_profit' => (int) $channelProfits->sum('operational_profit'),
                    'period_cost' => $cost,
                    'final_profitability' => (int) $channelProfits->sum('operational_profit')
                        - (in_array($channel->cost_model, ['wallet_topup', 'manual_period'], true) ? $cost : 0),
                ];
            }
        }

        $netPeriodProfit = $orders['operational_profit']
            - $expenses['total_affecting_partner']
            - $payroll
            - array_sum($channelCosts);

        return [
            'jalali_period' => $period,
            'orders' => $orders,
            'expenses' => $expenses,
            'payroll' => $payroll,
            'channel_costs' => $channelCosts,
            'channels' => $channelBreakdown,
            'net_period_profit' => $netPeriodProfit,
            'balances' => [
                'banks_and_cash' => $this->balanceByPrefix(['1000', '1100']),
                'receivables' => $this->balance('1200'),
                'cheques_receivable' => $this->balance('1250'),
                'inventory' => $this->balance('1300'),
                'payables' => -$this->balance('2000'),
                'cheques_payable' => -$this->balance('2100'),
                'loans' => -$this->balance('2200'),
                'customer_credit' => -$this->balance('2400'),
            ],
            'built_at' => now()->toIso8601String(),
        ];
    }

    private function readiness(string $period): array
    {
        $issues = array_filter([
            'missing_cost' => ReviewItem::where('type', 'missing_cost')->where('status', 'open')->count(),
            'unknown_source' => ReviewItem::where('type', 'unknown_source')->where('status', 'open')->count(),
            'missing_commission' => ReviewItem::where('type', 'missing_commission')->where('status', 'open')->count(),
            'sync_errors' => ReviewItem::where('type', 'sync_error')->where('status', 'open')->count(),
            'dead_webhooks' => WebhookEvent::where('status', 'dead')->count(),
            'blocked_orders' => OrderProfit::where('order_profits.status', 'blocked')
                ->whereHas('order', fn ($q) => $q->where('jalali_period', $period))->count(),
        ]);

        return ['ready' => $issues === [], 'issues' => $issues, 'checked_at' => now()->toIso8601String()];
    }

    private function balance(string $code): int
    {
        $account = Account::firstWhere('code', $code);

        return $account
            ? (int) $account->lines()->sum('debit') - (int) $account->lines()->sum('credit')
            : 0;
    }

    private function balanceByPrefix(array $parentCodes): int
    {
        $ids = Account::whereIn('code', $parentCodes)->pluck('id');
        $childIds = Account::whereIn('parent_id', $ids)->pluck('id')->merge($ids);

        return (int) DB::table('journal_lines')->whereIn('account_id', $childIds)->sum('debit')
            - (int) DB::table('journal_lines')->whereIn('account_id', $childIds)->sum('credit');
    }
}
