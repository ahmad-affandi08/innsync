import { CalendarDays, X } from 'lucide-react';
import { useState } from 'react';
import type { DateRange } from 'react-day-picker';

import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { fieldClass } from '@/components/ui/combobox';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { cn } from '@/shared/lib/utils';
import { useMediaQuery } from '@/shared/lib/use-media-query';
import { parseCalendarDate, toCalendarDate } from '@/shared/time/calendar-date';

type Common = {
    id?: string;
    name?: string;
    disabled?: boolean;
    required?: boolean;
    placeholder?: string;
    className?: string;
    /** Earliest and latest choosable day, `YYYY-MM-DD`. */
    min?: string;
    max?: string;
    'aria-describedby'?: string;
    'aria-invalid'?: boolean;
    'aria-required'?: boolean;
};

type DatePickerProps = Common & {
    /** `YYYY-MM-DD`, or an empty string for no date. */
    value: string;
    /** Called like an input's `onChange`: read `event.target.value`. */
    onChange?: (event: { target: { value: string }; currentTarget: { value: string } }) => void;
};

function bounds(min?: string, max?: string) {
    const before = parseCalendarDate(min);
    const after = parseCalendarDate(max);

    return [...(before ? [{ before }] : []), ...(after ? [{ after }] : [])];
}

/** One calendar day. The calendar opens below the field and never covers it. */
function DatePicker({ className, disabled, id, max, min, name, onChange, placeholder, required, value, ...aria }: DatePickerProps) {
    const { t } = useTranslation();
    const format = useFormatters();
    const [open, setOpen] = useState(false);
    const selected = parseCalendarDate(value);

    function pick(next: string) {
        onChange?.({ target: { value: next }, currentTarget: { value: next } });
        setOpen(false);
    }

    return (
        <Popover onOpenChange={setOpen} open={open}>
            <PopoverTrigger asChild>
                <button {...aria} aria-expanded={open} aria-haspopup="dialog" aria-required={required || aria['aria-required'] ? true : undefined} className={cn(fieldClass, className)} data-slot="date-picker-trigger" disabled={disabled} id={id} type="button">
                    <span className={cn('min-w-0 flex-1 truncate', selected === undefined && 'text-muted-foreground')}>{selected ? format.date(value, 'medium') : (placeholder ?? t('ui.date.pick'))}</span>
                    <CalendarDays aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
                </button>
            </PopoverTrigger>
            {name !== undefined ? <input name={name} type="hidden" value={value} /> : null}
            <PopoverContent align="start" className="w-auto p-0" collisionPadding={8} side="bottom" sideOffset={4}>
                <Calendar defaultMonth={selected} disabled={bounds(min, max)} mode="single" onSelect={(day) => day && pick(toCalendarDate(day))} selected={selected} />
                {!required && selected ? (
                    <div className="flex justify-end border-t border-border p-2">
                        <Button onClick={() => pick('')} size="sm" type="button" variant="ghost"><X aria-hidden="true" className="size-4" />{t('ui.date.clear')}</Button>
                    </div>
                ) : null}
            </PopoverContent>
        </Popover>
    );
}

type DateRangePickerProps = Common & {
    value: { from: string; to: string };
    onChange?: (range: { from: string; to: string }) => void;
};

/** A period of days: two clicks, first day then last. Two months side by side on a wide screen, one on a phone. */
function DateRangePicker({ className, disabled, id, max, min, onChange, placeholder, value, ...aria }: DateRangePickerProps) {
    const { t } = useTranslation();
    const format = useFormatters();
    const wide = useMediaQuery('(min-width: 640px)');
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState<DateRange | undefined>(undefined);
    const from = parseCalendarDate(value.from);
    const to = parseCalendarDate(value.to);
    const label = from && to ? `${format.date(value.from, 'medium')} – ${format.date(value.to, 'medium')}` : null;

    function openChange(next: boolean) {
        setOpen(next);

        if (next) setDraft(undefined);
    }

    // The first click starts a period, the second ends it (either order); a third starts a new one.
    function pick(day: Date) {
        if (draft?.from && !draft.to) {
            const [start, end] = day < draft.from ? [day, draft.from] : [draft.from, day];

            onChange?.({ from: toCalendarDate(start), to: toCalendarDate(end) });
            setDraft(undefined);
            setOpen(false);

            return;
        }

        setDraft({ from: day, to: undefined });
    }

    return (
        <Popover onOpenChange={openChange} open={open}>
            <PopoverTrigger asChild>
                <button {...aria} aria-expanded={open} aria-haspopup="dialog" className={cn(fieldClass, 'sm:w-72', className)} data-slot="date-range-trigger" disabled={disabled} id={id} type="button">
                    <span className={cn('min-w-0 flex-1 truncate', label === null && 'text-muted-foreground')}>{label ?? placeholder ?? t('ui.date.pickRange')}</span>
                    <CalendarDays aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
                </button>
            </PopoverTrigger>
            <PopoverContent align="start" className="w-auto p-0" collisionPadding={8} side="bottom" sideOffset={4}>
                <Calendar defaultMonth={from} disabled={bounds(min, max)} mode="range" numberOfMonths={wide ? 2 : 1} onSelect={(_range, day) => pick(day)} selected={draft ?? (from && to ? { from, to } : undefined)} />
                <p className="border-t border-border px-3 py-2 text-xs text-muted-foreground">{t('ui.date.rangeHint')}</p>
            </PopoverContent>
        </Popover>
    );
}

export { DatePicker, DateRangePicker };
