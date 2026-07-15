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

// Shared by every money field in the app (<x-form.money-input>, the purchase
// invoice form's line/shipping/payment fields): strip Persian/Arabic digits
// down to Latin — a pasted «۱۲۵۰۰۰۰» is a number, and refusing it is just
// rude — then to digits-only, and format with thousand separators for
// display. One implementation so every field agrees on what "a number" means.
window.normalizeDigits = function (value) {
    return String(value ?? '')
        .replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06f0))
        .replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660))
        .replace(/[^0-9]/g, '');
};

window.moneyDisplay = function (raw) {
    return raw ? Number(raw).toLocaleString('en-US') : '';
};

// <x-form.money-input> — separators on screen, a raw integer on the wire.
//
// Money is stored as an integer number of Toman, so the form must submit
// `1250000`, while a human entering it needs to SEE `1,250,000` or they will
// type the wrong number of zeros. Both, therefore: the visible field is
// formatted on every keystroke and carries no name; the hidden field carries
// the name and holds nothing but digits.
Alpine.data('moneyInput', (initial = '', name = '') => ({
    raw: window.normalizeDigits(initial),

    get display() {
        return window.moneyDisplay(this.raw);
    },

    onInput(event) {
        this.raw = window.normalizeDigits(event.target.value);
        event.target.value = this.display;

        // Forms that show a plain-language confirmation ("۵٬۰۰۰٬۰۰۰ تومان از حساب
        // خارج می‌شود") need the amount as it is typed. The event carries the field
        // NAME so a form with two money fields can tell them apart.
        this.$dispatch('money-input', { name, value: this.raw });
    },
}));

// One purchase-invoice LINE's state: which item it's for (found via the
// server-side search below, or typed as a brand-new item name), qty, and
// price entered either as unit price or as a line total — whichever was
// typed last drives the other, and both stay in sync with what is actually
// submitted (unit_price is the only price field the server reads; line
// total is a display-only convenience derived from it). Used by both
// makePurchaseLine() (a brand-new line, with product search) and
// makeExistingPurchaseLine() (an already-saved line on the edit form, whose
// item can't change) so the qty/price behaviour is identical either way.
function purchaseLinePriceFields(qty, unitPrice) {
    return {
        qty,
        unitPrice: window.normalizeDigits(unitPrice),
        lineTotal: String(qty * Number(window.normalizeDigits(unitPrice) || 0)),
        lastEdited: 'unit_price',

        display(raw) {
            return window.moneyDisplay(raw);
        },

        onQtyInput(event) {
            this.qty = parseInt(window.normalizeDigits(event.target.value), 10) || 0;
            event.target.value = this.qty || '';
            this.recalc();
        },

        onUnitPriceInput(event) {
            this.unitPrice = window.normalizeDigits(event.target.value);
            event.target.value = this.display(this.unitPrice);
            this.lastEdited = 'unit_price';
            this.recalc();
        },

        onLineTotalInput(event) {
            this.lineTotal = window.normalizeDigits(event.target.value);
            event.target.value = this.display(this.lineTotal);
            this.lastEdited = 'line_total';
            this.recalc();
        },

        // Whichever of unit price / line total was typed last is the source of
        // truth; the other is derived from it and re-displayed so the two never
        // silently drift apart. unit_price is always rounded to the nearest
        // Toman — the only value the server accepts — so a line total the user
        // typed as 100,000 over qty 3 becomes unit_price 33,333, and the line
        // total shown afterwards is recomputed from THAT (99,999), not the
        // original 100,000, matching exactly what will be posted.
        recalc() {
            const qty = this.qty || 0;

            if (this.lastEdited === 'line_total') {
                const total = Number(this.lineTotal || 0);
                this.unitPrice = qty > 0 ? String(Math.round(total / qty)) : '';
            }

            this.lineTotal = String(qty * Number(this.unitPrice || 0));
        },

        get lineTotalValue() {
            return (this.qty || 0) * Number(this.unitPrice || 0);
        },
    };
}

window.makePurchaseLine = function () {
    return {
        product_mirror_id: '',
        product_name: '',
        new_item_name: '',
        showNew: false,
        results: [],
        loading: false,
        open: false,
        showNote: false,
        note: '',
        ...purchaseLinePriceFields(1, ''),

        searchTimer: null,

        search(endpoint, query) {
            this.product_name = query;
            this.product_mirror_id = '';
            clearTimeout(this.searchTimer);

            if (query.length < 2) {
                this.results = [];
                this.open = false;

                return;
            }

            this.open = true;
            this.searchTimer = setTimeout(() => {
                this.loading = true;
                fetch(`${endpoint}?q=${encodeURIComponent(query)}`, { headers: { Accept: 'application/json' } })
                    .then((r) => r.json())
                    .then((data) => { this.results = data; })
                    .catch(() => { this.results = []; })
                    .finally(() => { this.loading = false; });
            }, 250);
        },

        pick(item) {
            this.product_mirror_id = item.id;
            this.product_name = item.name + (item.sku ? ` (${item.sku})` : '');
            this.results = [];
            this.open = false;
        },
    };
};

// An already-saved line on the edit form: qty/price work exactly like a new
// line's, but the item itself is fixed (no search) and it carries whether it
// can still be removed (never once anything has been received against it —
// PurchaseInvoiceService::update() enforces this server-side too).
window.makeExistingPurchaseLine = function (qty, unitPrice, note = '') {
    return {
        showNote: Boolean(note),
        note,
        removed: false,
        ...purchaseLinePriceFields(qty, unitPrice),
    };
};

// The repeatable initial/pending-payment rows: shared between the
// purchase-create form's Alpine component below and the purchase-edit page's
// inline root scope (edit.blade.php), so a draft's saved-but-unposted
// pending_payments restore through the exact same row shape and behaviour
// new rows on the create form get.
window.purchaseInvoicePaymentFields = function (initialPayments = []) {
    return {
        payments: (initialPayments || []).map((p) => ({
            bank_account_id: p.bank_account_id ?? '',
            amountRaw: window.normalizeDigits(p.amount ?? ''),
            method: p.method ?? '',
            reference: p.reference ?? '',
            note: p.note ?? '',
        })),

        display(raw) {
            return window.moneyDisplay(raw);
        },

        addPayment() {
            this.payments.push({ bank_account_id: '', amountRaw: '', method: '', reference: '', note: '' });
        },

        removePayment(idx) {
            this.payments.splice(idx, 1);
        },

        onPaymentAmountInput(payment, event) {
            payment.amountRaw = window.normalizeDigits(event.target.value);
            event.target.value = this.display(payment.amountRaw);
        },

        get paidAmount() {
            return this.payments.reduce((sum, p) => sum + Number(p.amountRaw || 0), 0);
        },
    };
};

// <x-form.product-line-picker>'s host: the purchase-create form as a whole —
// every line, the live subtotal/shipping/final-total summary, and the
// repeatable initial-payment rows, all in one scope so the summary can read
// straight off the lines/payments without an event bus.
Alpine.data('purchaseInvoiceForm', ({ searchEndpoint, shippingCost = 0 } = {}) => ({
    searchEndpoint,
    lines: [],
    shippingCostRaw: window.normalizeDigits(shippingCost),
    ...window.purchaseInvoicePaymentFields(),

    init() {
        if (this.lines.length === 0) this.addLine();
    },

    // Formats a live-computed total the same way <x-tables.num type="toman">
    // would (thousand separators + the unit suffix) — that component itself
    // can't be used here since it renders once, server-side, and this panel
    // recomputes on every keystroke.
    fmtToman(n) {
        return `${this.display(String(Math.round(n)))} تومان`;
    },

    onShippingInput(event) {
        this.shippingCostRaw = window.normalizeDigits(event.target.value);
        event.target.value = this.display(this.shippingCostRaw);
    },

    addLine() {
        this.lines.push(window.makePurchaseLine());
    },

    removeLine(idx) {
        if (this.lines.length > 1) this.lines.splice(idx, 1);
    },

    get subtotal() {
        return this.lines.reduce((sum, line) => sum + line.lineTotalValue, 0);
    },

    get finalTotal() {
        return this.subtotal + Number(this.shippingCostRaw || 0);
    },

    get remaining() {
        return this.finalTotal - this.paidAmount;
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
