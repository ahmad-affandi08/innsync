import { Command as CommandPrimitive } from 'cmdk';
import { Check, ChevronDown, Search } from 'lucide-react';
import { useState, type KeyboardEvent } from 'react';

import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useTranslation } from '@/shared/i18n/i18n';
import { cn } from '@/shared/lib/utils';

export type ComboboxOption = { value: string; label: string; disabled?: boolean; /** Extra words the search also matches. */ keywords?: string };

export type ComboboxProps = {
    options: readonly ComboboxOption[];
    value: string;
    onValueChange: (value: string) => void;
    placeholder?: string;
    searchPlaceholder?: string;
    emptyLabel?: string;
    /** A search box above the list. Turn it off only for a short fixed list such as a page size. */
    searchable?: boolean;
    id?: string;
    name?: string;
    disabled?: boolean;
    className?: string;
    'aria-describedby'?: string;
    'aria-invalid'?: boolean;
    'aria-required'?: boolean;
    'aria-label'?: string;
};

export const fieldClass =
    'flex h-10 min-h-10 w-full cursor-pointer items-center justify-between gap-2 border border-input bg-surface px-3 py-2 text-left text-sm text-foreground outline-none transition focus-visible:border-brand focus-visible:ring-2 focus-visible:ring-ring/40 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-danger';

/**
 * A select with a search box. The list opens below the field (or above it when
 * there is no room) and is never drawn over the field itself, so the chosen
 * value stays readable while choosing.
 */
function Combobox({ className, disabled, emptyLabel, id, name, onValueChange, options, placeholder, searchPlaceholder, searchable = true, value, ...aria }: ComboboxProps) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const selected = options.find((o) => o.value === value);
    const listId = `${id ?? 'combobox'}-list`;

    function openOnArrow(event: KeyboardEvent<HTMLButtonElement>) {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            setOpen(true);
        }
    }

    return (
        <Popover onOpenChange={setOpen} open={open}>
            <PopoverTrigger asChild>
                <button
                    {...aria}
                    aria-controls={open ? listId : undefined}
                    aria-expanded={open}
                    aria-haspopup="listbox"
                    className={cn(fieldClass, className)}
                    data-slot="combobox-trigger"
                    disabled={disabled}
                    id={id}
                    onKeyDown={openOnArrow}
                    role="combobox"
                    type="button"
                >
                    <span className={cn('min-w-0 flex-1 truncate', selected === undefined && 'text-muted-foreground')}>{selected?.label ?? placeholder ?? t('ui.picker.select')}</span>
                    <ChevronDown aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
                </button>
            </PopoverTrigger>
            {name !== undefined ? <input name={name} type="hidden" value={value} /> : null}
            <PopoverContent align="start" className="w-[var(--radix-popover-trigger-width)] min-w-48 p-0" collisionPadding={8} side="bottom" sideOffset={4}>
                <CommandPrimitive
                    className="flex flex-col"
                    filter={(_value, search, keywords) => ((keywords ?? []).join(' ').toLocaleLowerCase().includes(search.trim().toLocaleLowerCase()) ? 1 : 0)}
                >
                    {searchable ? (
                        <div className="flex items-center gap-2 border-b border-border px-3">
                            <Search aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
                            <CommandPrimitive.Input
                                aria-label={searchPlaceholder ?? t('ui.picker.search')}
                                className="h-10 w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                                placeholder={searchPlaceholder ?? t('ui.picker.search')}
                            />
                        </div>
                    ) : null}
                    <CommandPrimitive.List className="max-h-[min(16rem,calc(var(--radix-popover-content-available-height)-3.5rem))] overflow-y-auto p-1" id={listId}>
                        <CommandPrimitive.Empty className="px-3 py-6 text-center text-sm text-muted-foreground">{emptyLabel ?? t('ui.picker.empty')}</CommandPrimitive.Empty>
                        {options.map((option) => (
                            <CommandPrimitive.Item
                                className="relative flex cursor-pointer select-none items-center gap-2 py-2 pl-8 pr-3 text-sm outline-none data-[disabled=true]:pointer-events-none data-[disabled=true]:opacity-50 data-[selected=true]:bg-surface-muted"
                                disabled={option.disabled}
                                key={option.value}
                                keywords={[option.label, option.keywords ?? '']}
                                onSelect={() => {
                                    onValueChange(option.value);
                                    setOpen(false);
                                }}
                                value={option.value}
                            >
                                <span className="absolute left-2.5 flex size-4 items-center justify-center">{option.value === value ? <Check aria-hidden="true" className="size-4" /> : null}</span>
                                <span className="min-w-0 flex-1 truncate">{option.label}</span>
                            </CommandPrimitive.Item>
                        ))}
                    </CommandPrimitive.List>
                </CommandPrimitive>
            </PopoverContent>
        </Popover>
    );
}

export { Combobox };
