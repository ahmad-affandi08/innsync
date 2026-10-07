import { Children, Fragment, isValidElement, useMemo, useState, type ReactNode, type SelectHTMLAttributes } from 'react';

import { Combobox, type ComboboxOption } from '@/components/ui/combobox';

type SelectChange = { target: { value: string }; currentTarget: { value: string } };

type SelectProps = Omit<SelectHTMLAttributes<HTMLSelectElement>, 'onChange' | 'value' | 'defaultValue' | 'children'> & {
    value?: string | number;
    defaultValue?: string | number;
    /** Called like a native select's `onChange`: read `event.target.value`. */
    onChange?: (event: SelectChange) => void;
    /** The `<option>` elements, as for a native select. */
    children?: ReactNode;
    /** A search box in the list: automatic (shown when the list has more than a few rows). `false` hides it only on a short list. */
    searchable?: boolean | 'auto';
};

const textOf = (node: ReactNode): string => Children.toArray(node).map((c) => (typeof c === 'string' || typeof c === 'number' ? String(c) : isValidElement<{ children?: ReactNode }>(c) ? textOf(c.props.children) : '')).join('');

function collect(children: ReactNode, into: ComboboxOption[]) {
    Children.forEach(children, (child) => {
        if (!isValidElement<{ value?: string | number; disabled?: boolean; children?: ReactNode }>(child)) return;

        if (child.type === Fragment || child.type === 'optgroup') {
            collect(child.props.children, into);
        } else if (child.type === 'option') {
            const label = textOf(child.props.children);

            into.push({ value: String(child.props.value ?? label), label, disabled: child.props.disabled });
        }
    });
}

/**
 * The form select. It reads its `<option>` children like a native select, but
 * opens a searchable list below the field (see `Combobox`), so a long list of
 * guests, rooms or items can be searched instead of scrolled.
 */
function Select({ children, className, defaultValue, disabled, id, name, onChange, searchable = 'auto', value, ...rest }: SelectProps) {
    const options = useMemo(() => {
        const list: ComboboxOption[] = [];

        collect(children, list);

        return list;
    }, [children]);
    const [inner, setInner] = useState(String(defaultValue ?? ''));
    const current = value !== undefined ? String(value) : inner;

    return (
        <Combobox
            aria-describedby={rest['aria-describedby']}
            aria-invalid={rest['aria-invalid'] === true || rest['aria-invalid'] === 'true' ? true : undefined}
            aria-label={rest['aria-label']}
            aria-required={rest['aria-required'] === true || rest.required === true ? true : undefined}
            className={className}
            disabled={disabled}
            id={id}
            name={name}
            onValueChange={(next) => {
                setInner(next);
                onChange?.({ target: { value: next }, currentTarget: { value: next } });
            }}
            options={options}
            searchable={searchable}
            value={current}
        />
    );
}

export { Select };
