import ApexCharts from 'apexcharts';
import { baseOptions, palette, presets } from './presets';

/**
 * Chart initializer for the Reporting Design System.
 *
 * Scans for [data-chart] elements (rendered by <x-charts.chart>), reads the
 * preset + data off the element, and renders it. Because the element id is
 * generated per instance, the same preset can appear any number of times on a
 * page — the old #chartOne/#chartTwo scheme could not.
 *
 * Also re-renders every chart when the theme flips: ApexCharts bakes colours in
 * at render time and does not follow the .dark class, so this is the one place
 * dark-mode charts are handled for the whole app.
 */

const instances = new Map(); // element -> ApexCharts

const isDark = () => document.documentElement.classList.contains('dark');

const deepMerge = (a, b) => {
    const out = { ...a };
    for (const [k, v] of Object.entries(b || {})) {
        out[k] = v && typeof v === 'object' && !Array.isArray(v) && typeof a[k] === 'object' && !Array.isArray(a[k])
            ? deepMerge(a[k], v)
            : v;
    }
    return out;
};

function buildOptions(config) {
    const preset = presets[config.preset];

    if (!preset) {
        console.warn(`[charts] unknown preset "${config.preset}"`);
        return null;
    }

    let options = deepMerge(baseOptions(isDark()), preset(config));
    options.colors = config.colors?.length ? config.colors : palette();

    // Per-instance escape hatch for one-off tweaks.
    if (config.options && Object.keys(config.options).length) {
        options = deepMerge(options, config.options);
    }

    return options;
}

function render(el) {
    let config;
    try {
        config = JSON.parse(el.dataset.chart);
    } catch {
        console.warn('[charts] invalid data-chart JSON on', el);
        return;
    }

    // Empty state: never show a bare axis frame for a chart with no data.
    const flat = (config.series || []).flatMap((s) => (s && typeof s === 'object' && 'data' in s ? s.data : [s]));
    if (!flat.length) {
        el.innerHTML = `<div class="flex h-full min-h-[inherit] items-center justify-center text-sm text-gray-400">${config.emptyMessage ?? '—'}</div>`;
        return;
    }

    const options = buildOptions(config);
    if (!options) return;

    instances.get(el)?.destroy();
    el.innerHTML = '';

    const chart = new ApexCharts(el, options);
    chart.render();
    instances.set(el, chart);
}

export function initCharts(root = document) {
    root.querySelectorAll('[data-chart]').forEach(render);
}

/** Re-render everything on theme change so charts match the rest of the UI. */
export function watchTheme() {
    const observer = new MutationObserver((mutations) => {
        if (mutations.some((m) => m.attributeName === 'class')) {
            instances.forEach((_, el) => render(el));
        }
    });

    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
}
