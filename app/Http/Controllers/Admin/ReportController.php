<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Reports\Exceptions\ReportNotReadyException;
use App\Domain\Reports\Models\PartnerReport;
use App\Domain\Reports\Services\PartnerReportService;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportController extends Controller
{
    public function index(PartnerReportService $service): View
    {
        $current = JalaliPeriod::fromDate(Carbon::now(JalaliPeriod::TIMEZONE));
        $service->build($current); // keep the running month fresh

        return view('pages.reports.index', [
            'current_period' => $current,
            'reports' => PartnerReport::orderByDesc('jalali_period')->get()
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'jalali_period' => $r->jalali_period,
                    'state' => $r->state,
                    'ready' => $r->readiness['ready'] ?? false,
                    'net_period_profit' => ($r->snapshot ?? $r->draft_data)['net_period_profit'] ?? null,
                    'finalized_at' => $r->finalized_at,
                ]),
        ]);
    }

    public function show(string $period, PartnerReportService $service, Request $request): View
    {
        $report = PartnerReport::firstWhere('jalali_period', $period);

        // Finalized snapshots are served verbatim; drafts are rebuilt live.
        if (! $report || ! in_array($report->state, ['final', 'adjusted'], true)) {
            $report = $service->build($period);
        }

        $isFinal = in_array($report->state, ['final', 'adjusted'], true);

        return view('pages.reports.show', [
            'report' => [
                'jalali_period' => $report->jalali_period,
                'state' => $report->state,
                'readiness' => $report->readiness,
                'data' => $isFinal ? $report->snapshot : $report->draftData(),
                'is_snapshot' => $isFinal,
                'finalized_at' => $report->finalized_at,
                'adjustments' => $report->adjustments()->with('journalEntry:id,uuid,jalali_period,description')->get(),
            ],
            'can_finalize' => $request->user()->hasRole('admin'),
        ]);
    }

    public function finalize(Request $request, string $period, PartnerReportService $service): RedirectResponse
    {
        $report = PartnerReport::where('jalali_period', $period)->firstOrFail();

        try {
            $service->finalize($report, (bool) $request->boolean('acknowledge'), $request->user()->id);
        } catch (ReportNotReadyException) {
            return back()->withErrors(['finalize' => 'گزارش آماده نیست؛ موارد باز را برطرف کنید یا با تأیید صریح نهایی کنید.']);
        }

        return back()->with('success', "گزارش دوره {$period} نهایی و دوره قفل شد.");
    }
}
