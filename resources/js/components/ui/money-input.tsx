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
};

/**
 * An amount of money that is written with its thousands grouped as it is typed (1.500.000), in the language's own marks, on a phone with a number pad. The value handed back is the plain
 * amount, so every page keeps reading it exactly as before; only what the person sees changes.
 */
function MoneyInput({ decimals = 2, negative = false, onBlur, onChange, value, ...props }: MoneyInputProps) {
    const { locale } = useTranslation();
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

    return (
        <Input
            autoComplete="off"
            className="tabular-nums"
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
}

export { MoneyInput };
