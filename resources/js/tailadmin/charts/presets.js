/**
 * Chart preset registry for the Reporting Design System.
 *
 * A preset is a pure function (data, theme) -> ApexCharts options. It owns the
 * *shape* of the chart only; all cross-cutting concerns (RTL, font, Persian
 * digits, theme colours, reduced-motion, grid styling) live in `baseOptions`
 * below so every chart in the app is consistent by construction.
 *
 * Adding a chart type = adding one entry here. No new element ids, no new file
 * wired into app.js. See CLAUDE.md → "Chart architecture".
 */

// Read a design token straight off the document so charts inherit the exact
// same palette as the rest of the UI (and follow .dark overrides for free).
const token = (name, fallback) =>
    getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;

export const palette = () => [
    token('--color-brand-500', '#465fff'),
    token('--color-success-500', '#12b76a'),
    token('--color-warning-500', '#f79009'),
    token('--color-error-500', '#f04438'),
    token('--color-blue-light-500', '#0ba5ec'),
    token('--color-theme-purple-500', '#7a5af8'),
];

const faDigits = (n) => Number(n).toLocaleString('fa-IR');

const prefersReducedMotion = () =>
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/** Cross-cutting defaults every chart inherits. */
export function baseOptions(isDark) {
    const gridColor = isDark ? '#1d2939' : '#f2f4f7';
    const textColor = isDark ? '#98a2b3' : '#667085';

    return {
        chart: {
            fontFamily: 'IRANSansX, Tahoma, sans-serif',
            toolbar: { show: false },
            // RTL: Apex lays the x-axis out right-to-left when this is set.
            animations: { enabled: !prefersReducedMotion(), speed: 250 },
            background: 'transparent',
        },
        // Persian digits everywhere a number is shown to the user.
        tooltip: {
            theme: isDark ? 'dark' : 'light',
            y: { formatter: (v) => (v == null ? '—' : faDigits(v)) },
        },
        dataLabels: { enabled: false },
        grid: {
            borderColor: gridColor,
            strokeDashArray: 4,
            xaxis: { lines: { show: false } },
            yaxis: { lines: { show: true } },
        },
        xaxis: {
            labels: { style: { colors: textColor, fontSize: '12px' } },
            axisBorder: { show: false },
            axisTicks: { show: false },
        },
        yaxis: {
            labels: {
                style: { colors: textColor, fontSize: '12px' },
                formatter: (v) => (v == null ? '' : faDigits(Math.round(v))),
            },
            opposite: true, // RTL: value axis belongs on the right
        },
        legend: {
            show: true,
            position: 'top',
            horizontalAlign: 'right',
            fontFamily: 'IRANSansX, Tahoma, sans-serif',
            labels: { colors: textColor },
        },
        noData: {
            text: '—',
            style: { color: textColor, fontFamily: 'IRANSansX, Tahoma, sans-serif' },
        },
    };
}

/** Normalize `series` into Apex's [{name, data}] shape. */
const asSeries = (series, name = '') =>
    Array.isArray(series) && series.length && typeof series[0] === 'object' && 'data' in series[0]
        ? series
        : [{ name, data: series || [] }];

export const presets = {
    line: (d) => ({
        chart: { type: 'line', height: '100%' },
        series: asSeries(d.series),
        stroke: { curve: 'smooth', width: 2 },
        xaxis: { categories: d.categories },
        markers: { size: 0, hover: { size: 5 } },
    }),

    area: (d) => ({
        chart: { type: 'area', height: '100%' },
        series: asSeries(d.series),
        stroke: { curve: 'smooth', width: 2 },
        fill: { type: 'gradient', gradient: { opacityFrom: 0.4, opacityTo: 0 } },
        xaxis: { categories: d.categories },
    }),

    bar: (d) => ({
        chart: { type: 'bar', height: '100%' },
        series: asSeries(d.series),
        plotOptions: { bar: { borderRadius: 4, columnWidth: '45%' } },
        xaxis: { categories: d.categories },
    }),

    'bar-horizontal': (d) => ({
        chart: { type: 'bar', height: '100%' },
        series: asSeries(d.series),
        plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '55%' } },
        xaxis: { categories: d.categories },
    }),

    'bar-stacked': (d) => ({
        chart: { type: 'bar', height: '100%', stacked: true },
        series: asSeries(d.series),
        plotOptions: { bar: { borderRadius: 4, columnWidth: '45%' } },
        xaxis: { categories: d.categories },
    }),

    donut: (d) => ({
        chart: { type: 'donut', height: '100%' },
        series: d.series,
        labels: d.categories,
        plotOptions: { pie: { donut: { size: '70%' } } },
        yaxis: { show: false },
        grid: { padding: { top: 0, bottom: 0 } },
    }),

    pie: (d) => ({
        chart: { type: 'pie', height: '100%' },
        series: d.series,
        labels: d.categories,
        yaxis: { show: false },
    }),

    /** Line over bars — e.g. revenue bars + margin line. */
    mixed: (d) => ({
        chart: { type: 'line', height: '100%' },
        series: d.series, // caller supplies [{name, type:'column'|'line', data}]
        stroke: { width: [0, 2], curve: 'smooth' },
        plotOptions: { bar: { borderRadius: 4, columnWidth: '45%' } },
        xaxis: { categories: d.categories },
    }),

    /** Waterfall: running profit/loss bridge, built from a plain delta series. */
    waterfall: (d) => {
        let running = 0;
        const data = (d.series || []).map((v) => {
            const from = running;
            running += Number(v);
            return { x: '', y: [from, running] };
        });
        return {
            chart: { type: 'rangeBar', height: '100%' },
            series: [{ name: '', data: data.map((p, i) => ({ ...p, x: d.categories?.[i] ?? '' })) }],
            plotOptions: { bar: { horizontal: false, borderRadius: 3, columnWidth: '45%' } },
            xaxis: { type: 'category' },
        };
    },

    heatmap: (d) => ({
        chart: { type: 'heatmap', height: '100%' },
        series: d.series, // [{name, data:[{x,y}]}]
        plotOptions: { heatmap: { radius: 4 } },
    }),

    /** Radial progress — goal completion. `series` is a single percentage. */
    radial: (d) => ({
        chart: { type: 'radialBar', height: '100%', sparkline: { enabled: true } },
        series: Array.isArray(d.series) ? d.series : [d.series],
        plotOptions: {
            radialBar: {
                startAngle: -90,
                endAngle: 90,
                hollow: { size: '70%' },
                track: { background: 'var(--color-border-subtle)' },
                dataLabels: {
                    name: { show: false },
                    value: {
                        fontSize: '28px',
                        fontFamily: 'IRANSansX, Tahoma, sans-serif',
                        formatter: (v) => `${faDigits(v)}٪`,
                    },
                },
            },
        },
        legend: { show: false },
    }),

    /** Sparkline for KPI cards — no axes, no grid, pure trend shape.
     *  Explicit pixel height: '100%' cannot resolve against an auto-height
     *  parent and Apex would fall back to its 150px default. */
    'kpi-trend': (d) => ({
        chart: { type: 'area', height: 48, sparkline: { enabled: true } },
        series: asSeries(d.series),
        stroke: { curve: 'smooth', width: 2 },
        fill: { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0 } },
        tooltip: { fixed: { enabled: false }, y: { formatter: (v) => faDigits(v) } },
        legend: { show: false },
    }),

    /** Two periods side by side — this month vs last month. */
    comparison: (d) => ({
        chart: { type: 'bar', height: '100%' },
        series: d.series, // [{name:'این ماه', data}, {name:'ماه قبل', data}]
        plotOptions: { bar: { borderRadius: 4, columnWidth: '60%' } },
        xaxis: { categories: d.categories },
    }),

    /**
     * Cumulative actual vs target — running total of what happened against the
     * plan. `series` = [{name:'محقق‌شده', data}, {name:'هدف', data}] (raw values;
     * the preset accumulates them so the caller passes plain period figures).
     */
    'cumulative-target': (d) => {
        const cumulate = (arr) => {
            let run = 0;
            return (arr || []).map((v) => (run += Number(v) || 0));
        };
        const [actual, target] = d.series || [];
        return {
            chart: { type: 'line', height: '100%' },
            series: [
                { name: actual?.name ?? 'محقق‌شده', type: 'area', data: cumulate(actual?.data ?? actual) },
                { name: target?.name ?? 'هدف', type: 'line', data: cumulate(target?.data ?? target) },
            ],
            stroke: { curve: 'smooth', width: [2, 2], dashArray: [0, 5] }, // target is dashed
            fill: { type: ['gradient', 'solid'], gradient: { opacityFrom: 0.3, opacityTo: 0 } },
            xaxis: { categories: d.categories },
        };
    },

    /**
     * Revenue (money, bars) against order count (a much smaller number, line) —
     * needs a second y-axis or the count is flattened to nothing.
     */
    'revenue-orders': (d) => {
        const [revenue, orders] = d.series || [];
        return {
            chart: { type: 'line', height: '100%' },
            series: [
                { name: revenue?.name ?? 'درآمد', type: 'column', data: revenue?.data ?? revenue ?? [] },
                { name: orders?.name ?? 'تعداد سفارش', type: 'line', data: orders?.data ?? orders ?? [] },
            ],
            stroke: { width: [0, 2], curve: 'smooth' },
            plotOptions: { bar: { borderRadius: 4, columnWidth: '45%' } },
            xaxis: { categories: d.categories },
            yaxis: [
                { seriesName: revenue?.name ?? 'درآمد', opposite: true, labels: { formatter: (v) => faDigits(Math.round(v)) } },
                { seriesName: orders?.name ?? 'تعداد سفارش', opposite: false, labels: { formatter: (v) => faDigits(Math.round(v)) } },
            ],
        };
    },

    /**
     * Profit-and-loss bridge: gross profit → net profit, one bar per adjustment.
     * `series` = signed deltas, `categories` = their labels. Positive steps are
     * profit-coloured, negative steps loss-coloured; the last bar is the total.
     */
    'pnl-bridge': (d) => {
        const deltas = (d.series || []).map(Number);
        let running = 0;
        const data = deltas.map((v, i) => {
            const from = running;
            running += v;
            return {
                x: d.categories?.[i] ?? '',
                y: [from, running],
                fillColor: v >= 0 ? token('--color-profit', '#039855') : token('--color-loss', '#d92d20'),
            };
        });

        // Closing bar: the net result, drawn from zero.
        data.push({
            x: 'خالص',
            y: [0, running],
            fillColor: token('--color-brand-500', '#465fff'),
        });

        return {
            chart: { type: 'rangeBar', height: '100%' },
            series: [{ name: '', data }],
            plotOptions: { bar: { horizontal: false, borderRadius: 3, columnWidth: '55%' } },
            xaxis: { type: 'category' },
            legend: { show: false },
            tooltip: { y: { formatter: (v) => faDigits(v) } },
        };
    },
};
