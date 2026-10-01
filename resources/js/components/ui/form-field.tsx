import {
    cloneElement,
    isValidElement,
    useId,
    type ReactElement,
    type ReactNode,
} from 'react';

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
    className?: string;
};

/**
 * Associates label, hint and error with a single control through
 * `htmlFor`/`aria-describedby`/`aria-invalid`, so validation shown next to the
 * field is also announced by screen readers (NFR-27).
 */
function FormField({
    children,
    className,
    error,
    hint,
    label,
    requiredLabel,
}: FormFieldProps) {
    const generatedId = useId();
    const controlId = children.props.id ?? generatedId;
    const hintId = hint ? `${controlId}-hint` : undefined;
    const errorId = error ? `${controlId}-error` : undefined;
    const describedBy =
        [children.props['aria-describedby'], hintId, errorId]
            .filter(Boolean)
            .join(' ') || undefined;

    return (
        <div className={cn('flex flex-col gap-1.5', className)}>
            <Label htmlFor={controlId}>
                {label}
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
                      'aria-required': requiredLabel ? true : undefined,
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
