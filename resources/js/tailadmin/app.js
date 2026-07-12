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
