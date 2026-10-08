import { Eye, EyeOff } from 'lucide-react';
import { useState, type ComponentProps } from 'react';

import { Input } from '@/components/ui/input';
import { useTranslation } from '@/shared/i18n/i18n';
import { cn } from '@/shared/lib/utils';

type PasswordInputProps = Omit<ComponentProps<'input'>, 'type'>;

/** A password field with an eye at its end that shows or hides what was typed, so a person on a phone can check it before sending. Hidden again whenever the page is left. */
function PasswordInput({ className, ...props }: PasswordInputProps) {
    const { t } = useTranslation();
    const [shown, setShown] = useState(false);
    const Icon = shown ? EyeOff : Eye;

    return (
        <div className="relative">
            <Input
                autoCapitalize="none"
                autoCorrect="off"
                spellCheck={false}
                {...props}
                className={cn('pr-12', className)}
                type={shown ? 'text' : 'password'}
            />
            <button
                aria-label={t(shown ? 'ui.password.hide' : 'ui.password.show')}
                aria-pressed={shown}
                className="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-muted-foreground outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/40"
                onClick={() => setShown((v) => !v)}
                type="button"
            >
                <Icon aria-hidden="true" className="size-5" />
            </button>
        </div>
    );
}

export { PasswordInput };
