import './bootstrap';
import Alpine from 'alpinejs';
import ApexCharts from 'apexcharts';
// <x-common.table-dropdown> calls createPopper() as a global to position its
// row-action menu; without this it was an undefined global (silently broken).
import { createPopper } from '@popperjs/core';

// flatpickr
import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.min.css';
// FullCalendar
import { Calendar } from '@fullcalendar/core';
// Jalali date picker (vanilla JS, no framework) — used by <x-form.jalali-date-range>.
// This package has no ES export; it attaches `window.jalaliDatepicker` itself.
import '@majidh1/jalalidatepicker/dist/jalalidatepicker.min.js';
import '@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css';

window.Alpine = Alpine;
window.ApexCharts = ApexCharts;
window.flatpickr = flatpickr;
window.FullCalendar = Calendar;
window.createPopper = createPopper;

window.jalaliDatepicker.startWatch({
    minWidth: false,
    autoHide: true,
    autoShow: true,
    targetValueInput: 'attr',
    targetValueType: 'attr',
    // The library only sets its popup's z-index inline (authoritative, beats
    // any CSS) when this option is a number — needed so it renders above
    // <x-ui.modal>'s z-99999 overlay when a date field sits inside a modal.
    zIndex: 100000,
});

// Toman/money inputs: show thousands separators while typing, keep the raw
// digit value in a paired hidden field so the server still gets a plain
// integer. Wire with oninput="formatTomanInput(this, '#hidden-id')".
window.formatTomanInput = function (el, hiddenSelector) {
    const digits = el.value.replace(/[^0-9]/g, '');
    el.value = digits ? Number(digits).toLocaleString('en-US') : '';
    const hidden = document.querySelector(hiddenSelector);
    if (hidden) hidden.value = digits;
};

// <x-form.money-input> — separators on screen, a raw integer on the wire.
//
// Money is stored as an integer number of Toman, so the form must submit
// `1250000`, while a human entering it needs to SEE `1,250,000` or they will
// type the wrong number of zeros. Both, therefore: the visible field is
// formatted on every keystroke and carries no name; the hidden field carries
// the name and holds nothing but digits.
Alpine.data('moneyInput', (initial = '', name = '') => ({
    raw: String(initial ?? '').replace(/[^0-9]/g, ''),

    get display() {
        return this.raw ? Number(this.raw).toLocaleString('en-US') : '';
    },

    onInput(event) {
        // Strip Persian/Arabic digits down to Latin before anything else — a
        // pasted «۱۲۵۰۰۰۰» is a number, and refusing it is just rude.
        const latin = event.target.value
            .replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06f0))
            .replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660));

        this.raw = latin.replace(/[^0-9]/g, '');
        event.target.value = this.display;

        // Forms that show a plain-language confirmation ("۵٬۰۰۰٬۰۰۰ تومان از حساب
        // خارج می‌شود") need the amount as it is typed. The event carries the field
        // NAME so a form with two money fields can tell them apart.
        this.$dispatch('money-input', { name, value: this.raw });
    },
}));

// <x-form.party-select> — server-side search over every party, not a dropdown
// of the first few hundred.
Alpine.data('partySelect', ({ endpoint, role = null, selected = null }) => ({
    term: '',
    results: [],
    page: 1,
    hasMore: false,
    loading: false,
    isOpen: false,
    selectedId: selected?.id ?? '',
    selectedName: selected?.name ?? '',

    open() {
        this.isOpen = true;
        if (this.results.length === 0) this.search();
    },

    close() {
        this.isOpen = false;
    },

    clear() {
        this.selectedId = '';
        this.selectedName = '';
        this.term = '';
        this.results = [];
        this.announce(null);
    },

    choose(party) {
        this.selectedId = party.id;
        this.selectedName = party.name;
        this.term = '';
        this.close();
        this.announce(party);
    },

    // A form that has to react to the chosen party (the credit-order list on the
    // customer-payment form, say) listens for this instead of reaching into the
    // picker's DOM — which would not be reactive, and would break the moment the
    // markup changed.
    announce(party) {
        this.$dispatch('party-selected', { id: party?.id ?? null, name: party?.name ?? null });
    },

    search() {
        this.page = 1;
        this.results = [];
        this.fetchPage();
    },

    more() {
        this.page += 1;
        this.fetchPage();
    },

    async fetchPage() {
        this.loading = true;
        this.isOpen = true;

        const params = new URLSearchParams({ q: this.term, page: this.page });
        if (role) params.set('role', role);

        try {
            const response = await fetch(`${endpoint}?${params}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();

            this.results = this.page === 1 ? data.results : [...this.results, ...data.results];
            this.hasMore = data.has_more;
        } catch {
            // A picker that cannot reach the server shows nothing rather than a
            // stale list — silently offering the previous query's parties is how
            // the wrong party gets picked.
            this.results = [];
            this.hasMore = false;
        } finally {
            this.loading = false;
        }
    },
}));

Alpine.start();

// Initialize components on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    // Map imports
    if (document.querySelector('#mapOne')) {
        import('./components/map').then(module => module.initMap());
    }

    // Charts: one initializer for every chart on the page. Each <x-charts.chart>
    // carries its own preset + data, so there are no hard-coded element ids and a
    // preset can be reused any number of times. See charts/presets.js.
    if (document.querySelector('[data-chart]')) {
        import('./charts').then(({ initCharts, watchTheme }) => {
            initCharts();
            watchTheme(); // re-render charts when the theme flips
        });
    }

    // Calendar init
    if (document.querySelector('#calendar')) {
        import('./components/calendar-init').then(module => module.calendarInit());
    }
});
