import { useState, type InputHTMLAttributes } from 'react';

import { Input } from '@/components/ui/input';
import { completeTime, formatTypedTime } from '@/shared/lib/time-input';

type TimeInputProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'value' | 'onChange' | 'inputMode' | 'maxLength'> & {
    /** HH:MM, or what has been typed so far. */
    value: string;
    /** Called like a text input's `onChange`: read `event.target.value`. */
    onChange: (event: { target: { value: string }; currentTarget: { value: string } }) => void;
};

/**
 * A time of day typed as HH:MM. It is the same field as every other, in height, border and type size, on a phone as on a desktop: the browser's own clock
 * control (`type="time"`) is taller, differs from one browser to the next and has pieces that are hard to touch. A number pad opens on a phone, the colon
 * is put in as the person types, and when the field is left `9` becomes 09:00 and `930` becomes 09:30.
 */
function TimeInput({ onBlur, onChange, value, ...props }: TimeInputProps) {
    const [invalid, setInvalid] = useState(false);
    const emit = (next: string) => onChange({ target: { value: next }, currentTarget: { value: next } });

    return (
        <Input
            autoComplete="off"
            inputMode="numeric"
            maxLength={5}
            placeholder="HH:MM"
            {...props}
            aria-invalid={invalid ? true : props['aria-invalid']}
            onBlur={(event) => {
                const done = completeTime(value);

                setInvalid(done === null);

                if (done !== null && done !== value) emit(done);

                onBlur?.(event);
            }}
            onChange={(event) => {
                setInvalid(false);
                emit(formatTypedTime(event.target.value));
            }}
            pattern="([01][0-9]|2[0-3]):[0-5][0-9]"
            type="text"
            value={value}
        />
    );
}

export { TimeInput };
