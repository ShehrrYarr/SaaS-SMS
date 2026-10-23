/**
 * ApexCharts for <x-chart>. The library is loaded on first use (its own
 * chunk), so pages without charts don't pay for it.
 *
 * Charts take their colours from the Herozi CSS tokens and are redrawn when
 * the light/dark theme flips (layout.js sets data-bs-theme on <html>).
 */

const PALETTE = ['--bs-primary', '--bs-success', '--bs-info', '--bs-warning', '--bs-danger', '--bs-secondary'];

let library;

function loadApexCharts() {
    library ??= import('apexcharts').then((module) => module.default);

    return library;
}

function token(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

function themed(options, height) {
    const dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
    const text = token('--bs-secondary-color');
    const border = token('--bs-border-color');

    return {
        ...options,
        // Colours can be CSS tokens ('--bs-success') or plain values.
        colors: (options.colors ?? PALETTE).map((color) => (color.startsWith('--') ? token(color) : color)).filter(Boolean),
        chart: {
            height,
            fontFamily: 'inherit',
            foreColor: text,
            background: 'transparent',
            toolbar: { show: false },
            zoom: { enabled: false },
            animations: { enabled: !window.matchMedia('(prefers-reduced-motion: reduce)').matches },
            ...(options.chart ?? {}),
        },
        theme: { mode: dark ? 'dark' : 'light' },
        grid: { borderColor: border, strokeDashArray: 4, ...(options.grid ?? {}) },
        dataLabels: { enabled: false, ...(options.dataLabels ?? {}) },
        legend: { position: 'bottom', ...(options.legend ?? {}) },
        tooltip: { theme: dark ? 'dark' : 'light', ...(options.tooltip ?? {}) },
        stroke: { width: options.chart?.type === 'line' || options.chart?.type === 'area' ? 3 : 0, curve: 'smooth', ...(options.stroke ?? {}) },
    };
}

function apexChart(options, height = 300) {
    return {
        chart: null,
        observer: null,

        async init() {
            const ApexCharts = await loadApexCharts();

            this.chart = new ApexCharts(this.$refs.canvas, themed(options, height));
            await this.chart.render();

            this.observer = new MutationObserver(() => this.chart?.updateOptions(themed(options, height), false, false));
            this.observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });
        },

        destroy() {
            this.observer?.disconnect();
            this.chart?.destroy();
            this.chart = null;
        },
    };
}

export function bootCharts() {
    const register = () => window.Alpine.data('apexChart', apexChart);

    // Livewire starts Alpine after DOMContentLoaded; this module runs before that.
    if (window.Alpine) {
        register();
    } else {
        document.addEventListener('alpine:init', register, { once: true });
    }
}
