import { Select } from '@/components/ui/select';
import { useMonthName } from '@/modules/finance/lib/finance';
import { useTranslation } from '@/shared/i18n/i18n';

type Props = {
    /** `YYYY-MM`. */
    value: string;
    onChange: (month: string) => void;
    /** The years offered; the year of `value` is added when it is missing. */
    years: readonly number[];
    id?: string;
    disabled?: boolean;
    'aria-describedby'?: string;
    'aria-invalid'?: boolean;
    'aria-required'?: boolean;
};

/** A month and a year, picked from two short lists; the value is a `YYYY-MM` string. */
export function MonthPicker({ disabled, id, onChange, value, years, ...aria }: Props) {
    const { t } = useTranslation();
    const monthName = useMonthName();
    const year = Number(value.slice(0, 4));
    const month = Number(value.slice(5, 7));
    const offered = years.includes(year) ? [...years] : [...years, year].sort((a, b) => a - b);
    const join = (y: number, m: number) => `${String(y).padStart(4, '0')}-${String(m).padStart(2, '0')}`;

    return (
        <div className="flex gap-2">
            <Select {...aria} aria-label={t('fin.rec.pickMonth')} className="min-w-0 flex-[3]" disabled={disabled} id={id} onChange={(e) => onChange(join(year, Number(e.target.value)))} searchable={false} value={String(month)}>
                {Array.from({ length: 12 }, (_, i) => i + 1).map((m) => <option key={m} value={m}>{monthName(m)}</option>)}
            </Select>
            <Select aria-label={t('fin.rec.pickYear')} className="min-w-0 flex-[2]" disabled={disabled} onChange={(e) => onChange(join(Number(e.target.value), month))} searchable={false} value={String(year)}>
                {offered.map((y) => <option key={y} value={y}>{y}</option>)}
            </Select>
        </div>
    );
}
