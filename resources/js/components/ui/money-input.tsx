import { usePage } from '@inertiajs/react';
import { useLayoutEffect, useRef, type InputHTMLAttributes } from 'react';

import { Input } from '@/components/ui/input';
import { useTranslation } from '@/shared/i18n/i18n';
import { caretAfter, displayFromRaw, rawFromPasted, rawFromTyped, significantBefore } from '@/shared/money/money-input';

type MoneyInputProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'value' | 'onChange' | 'inputMode'> & {
    /** The plain amount: digits, one `.` for decimals, a leading `-` when `negative`. This is what `parseMajorToMinor` reads. */
    value: string;
    /** Called like a text input's `onChange`: read `event.target.value`, which is the plain amount. */
    onChange: (event: { target: { value: string }; currentTarget: { value: string } }) => void;
    /** Decimals the currency has; none for rupiah in practice, but cents are accepted up to this many. Default 2. */
    decimals?: number;
    /** Allow a leading minus (an adjustment that takes money away). */
    negative?: boolean;
    /** The currency shown before the amount; the property's own when omitted, none when `null` (a cell in a table) or when the page has no property. */
    currency?: string | null;
};

/** What stands before an amount: the rupiah as `Rp`, any other currency by its code. */
export function currencyMark(code: string): string {
    return code === 'IDR' ? 'Rp' : code;
}

/**
 * An amount of money that is written with its thousands grouped as it is typed (1.500.000), in the language's own marks, on a phone with a number pad. The value handed back is the plain
 * amount, so every page keeps reading it exactly as before; only what the person sees changes.
 */
function MoneyInput({ currency, decimals = 2, negative = false, onBlur, onChange, value, ...props }: MoneyInputProps) {
    const { locale } = useTranslation();
    const own = (usePage().props as { shell?: { currency?: string | null } | null }).shell?.currency ?? null;
    const code = currency === undefined ? own : currency;
    const mark = code === null ? null : currencyMark(code);
    const ref = useRef<HTMLInputElement>(null);
    const caret = useRef<number | null>(null);
    const options = { decimals, negative };
    const shown = displayFromRaw(value, locale);
    const emit = (next: string) => onChange({ target: { value: next }, currentTarget: { value: next } });

    useLayoutEffect(() => {
        const field = ref.current;

        if (field !== null && caret.current !== null && document.activeElement === field) {
            field.setSelectionRange(caret.current, caret.current);
        }

        caret.current = null;
    });

    const field = (
        <Input
            autoComplete="off"
            className="tabular-nums"
            style={mark === null ? undefined : { paddingLeft: `${1.25 + mark.length * 0.6}rem` }}
            inputMode={negative ? 'text' : decimals > 0 ? 'decimal' : 'numeric'}
            {...props}
            onBlur={(event) => {
                if (value.endsWith('.')) emit(value.slice(0, -1));

                onBlur?.(event);
            }}
            onChange={(event) => {
                const text = event.target.value;
                const next = rawFromTyped(text, locale, options);

                caret.current = caretAfter(displayFromRaw(next, locale), significantBefore(text, event.target.selectionStart ?? text.length, locale), locale);
                emit(next);
            }}
            onPaste={(event) => {
                event.preventDefault();
                emit(rawFromPasted(event.clipboardData.getData('text'), locale, options));
            }}
            ref={ref}
            type="text"
            value={shown}
        />
    );

    // The mark is outside the typed text (never part of the value) and read out with the label by the field's own description.
    return mark === null ? field : (
        <div className="relative">
            {field}
            <span aria-hidden="true" className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-muted-foreground">{mark}</span>
        </div>
    );
}

export { MoneyInput };
