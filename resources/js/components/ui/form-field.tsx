import { usePage } from '@inertiajs/react';
import {
    cloneElement,
    isValidElement,
    useId,
    type ReactElement,
    type ReactNode,
} from 'react';

import requirements from '@/generated/form-requirements.json';
import { Label } from '@/components/ui/label';
import { cn } from '@/shared/lib/utils';

type ControlProps = {
    id?: string;
    'aria-describedby'?: string;
    'aria-invalid'?: boolean;
    'aria-required'?: boolean;
};

type FormFieldProps = {
    /** Always visible; a placeholder is never a label. */
    label: ReactNode;
    children: ReactElement<ControlProps>;
    /** Server or client validation message for this field. */
    error?: string;
    hint?: ReactNode;
    /** Mark required fields explicitly; the text is supplied by the screen (i18n). */
    requiredLabel?: string;
    /**
     * The request field this control sends (the key the server validates). When the server requires it on
     * this screen, a red star shows by itself; the list is generated from the backend rules
     * (`php tools/export-form-rules.php`, `npm run forms`).
     */
    field?: string;
    /** Force the star for a field that is only required in some cases (the server decides the rest). */
    required?: boolean;
    className?: string;
};

const requiredByScreen = requirements as Record<string, string[]>;

/**
 * Associates label, hint and error with a single control through
 * `htmlFor`/`aria-describedby`/`aria-invalid`, so validation shown next to the
 * field is also announced by screen readers (NFR-27).
 */
function FormField({
    children,
    className,
    error,
    field,
    hint,
    label,
    required: requiredHere,
    requiredLabel,
}: FormFieldProps) {
    const generatedId = useId();
    const screen = usePage().component;
    const required = requiredHere === true || requiredLabel !== undefined || (field !== undefined && (requiredByScreen[screen] ?? []).includes(field));
    const controlId = children.props.id ?? generatedId;
    const hintId = hint ? `${controlId}-hint` : undefined;
    const errorId = error ? `${controlId}-error` : undefined;
    const describedBy =
        [children.props['aria-describedby'], hintId, errorId]
            .filter(Boolean)
            .join(' ') || undefined;

    return (
        <div className={cn('flex min-w-0 flex-col gap-1.5', className)}>
            <Label htmlFor={controlId}>
                {label}
                {required ? (
                    <span aria-hidden="true" className="ml-0.5 font-semibold text-danger" data-required="true">
                        *
                    </span>
                ) : null}
                {requiredLabel ? (
                    <span className="ml-1 font-normal text-muted-foreground">
                        ({requiredLabel})
                    </span>
                ) : null}
            </Label>
            {isValidElement(children)
                ? cloneElement(children, {
                      id: controlId,
                      'aria-describedby': describedBy,
                      'aria-invalid': error ? true : undefined,
                      'aria-required': required ? true : undefined,
                  })
                : children}
            {hint ? (
                <p className="text-xs text-muted-foreground" id={hintId}>
                    {hint}
                </p>
            ) : null}
            {error ? (
                <p className="text-sm text-danger" id={errorId}>
                    {error}
                </p>
            ) : null}
        </div>
    );
}

export { FormField };
