import { ChevronLeft, ChevronRight } from 'lucide-react';
import { DayPicker, type DayPickerProps } from 'react-day-picker';
import { enUS, id as idLocale } from 'react-day-picker/locale';

import { useTranslation } from '@/shared/i18n/i18n';
import { cn } from '@/shared/lib/utils';

/**
 * The shadcn/ui Calendar (react-day-picker), flat: no radius, no shadow.
 * Weeks start on Monday, the Indonesian convention; month and year can be
 * picked from a list so a far date is not forty clicks away.
 */
function Calendar({ className, classNames, ...props }: DayPickerProps) {
    const { locale, t } = useTranslation();
    const year = new Date().getFullYear();

    return (
        <DayPicker
            captionLayout="dropdown"
            classNames={{
                root: cn('p-3 text-sm', className),
                months: 'relative flex flex-col gap-4 sm:flex-row',
                month: 'flex flex-col gap-3',
                month_caption: 'flex h-9 items-center justify-center px-10',
                dropdowns: 'flex items-center gap-1 text-sm font-medium',
                dropdown_root: 'relative border border-transparent hover:bg-surface-muted has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring',
                dropdown: 'absolute inset-0 cursor-pointer opacity-0',
                caption_label: 'flex items-center gap-1 px-2 py-1 text-sm font-medium',
                nav: 'absolute inset-x-0 top-0 flex items-center justify-between',
                button_previous: 'inline-flex size-9 items-center justify-center border border-input bg-surface text-foreground hover:bg-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-40',
                button_next: 'inline-flex size-9 items-center justify-center border border-input bg-surface text-foreground hover:bg-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-40',
                month_grid: 'w-full border-collapse',
                weekdays: 'flex',
                weekday: 'w-9 py-1 text-center text-xs font-medium text-muted-foreground',
                week: 'mt-1 flex',
                day: 'size-9 p-0 text-center',
                day_button: 'inline-flex size-9 items-center justify-center hover:bg-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                today: '[&>button]:font-bold [&>button]:text-accent',
                selected: '[&>button]:bg-primary [&>button]:text-primary-foreground [&>button:hover]:bg-primary',
                range_middle: '[&>button]:bg-surface-muted! [&>button]:text-foreground!',
                outside: 'text-muted-foreground opacity-50',
                disabled: 'opacity-35',
                hidden: 'invisible',
                ...classNames,
            }}
            components={{
                Chevron: ({ orientation }) => (orientation === 'left' ? <ChevronLeft aria-hidden="true" className="size-4" /> : orientation === 'right' ? <ChevronRight aria-hidden="true" className="size-4" /> : <span aria-hidden="true" className="text-[0.6rem]">▾</span>),
            }}
            endMonth={new Date(year + 10, 11)}
            labels={{
                labelPrevious: () => t('ui.date.prevMonth'),
                labelNext: () => t('ui.date.nextMonth'),
            }}
            locale={locale === 'id' ? idLocale : enUS}
            startMonth={new Date(year - 100, 0)}
            weekStartsOn={1}
            {...props}
        />
    );
}

export { Calendar };
