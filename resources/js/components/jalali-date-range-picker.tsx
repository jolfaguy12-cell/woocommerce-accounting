import DatePicker, { type DateObject } from 'react-multi-date-picker';
import persian from 'react-date-object/calendars/persian';
import persian_fa from 'react-date-object/locales/persian_fa';

export type DateRange = { from?: string; to?: string };

const toIsoDate = (d: DateObject) => d.toDate().toISOString().slice(0, 10);

/**
 * Shared Persian/Jalali range picker. Values in/out are plain Gregorian
 * ISO date strings (YYYY-MM-DD) so callers never touch Jalali directly —
 * only the display is Jalali. Reusable anywhere a date-range filter is needed.
 */
export function JalaliDateRangePicker({
    value,
    onChange,
    placeholder = 'انتخاب بازه تاریخ',
}: {
    value?: DateRange;
    onChange: (range: DateRange) => void;
    placeholder?: string;
}) {
    const selected = [value?.from, value?.to].filter(Boolean).map((d) => new Date(d as string));

    return (
        <DatePicker
            range
            rangeHover
            calendar={persian}
            locale={persian_fa}
            value={selected.length ? selected : []}
            onChange={(dates) => {
                const list = Array.isArray(dates) ? (dates as DateObject[]) : [];
                onChange({ from: list[0] ? toIsoDate(list[0]) : undefined, to: list[1] ? toIsoDate(list[1]) : undefined });
            }}
            editable={false}
            placeholder={placeholder}
            calendarPosition="bottom-right"
            inputClass="h-9 w-56 rounded-md border border-input bg-background px-3 text-sm text-foreground placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
            containerClassName="rmdp-container-rtl"
        />
    );
}
