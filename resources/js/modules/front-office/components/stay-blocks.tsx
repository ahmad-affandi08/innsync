import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { FormField } from '@/components/ui/form-field';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { addDays, isIsoDate, nightsBetween } from '@/shared/time/time';

export type NightPrice = { date: string; total_minor: number };

const PRESETS = [1, 2, 3, 5, 7] as const;

/**
 * Choosing the length of a stay (FR-FO-012): the number of nights next to the arrival date, and one block per night with its date
 * and its price once the price is known, so a front desk agent sees at a glance which nights are being sold and at what rate.
 */
export function StayBlocks({ arrival, currency, departure, nights, onDeparture }: { arrival: string; currency: string; departure: string; nights: NightPrice[]; onDeparture: (departure: string) => void }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const valid = isIsoDate(arrival) && isIsoDate(departure) && nightsBetween(arrival, departure) >= 1;
    const count = valid ? nightsBetween(arrival, departure) : 0;
    const price = (date: string) => nights.find((n) => n.date === date)?.total_minor;
    const set = (n: number) => isIsoDate(arrival) && n >= 1 && n <= 90 && onDeparture(addDays(arrival, n));

    return (
        <div className="flex flex-col gap-2 sm:col-span-2" data-testid="stay-blocks">
            <div className="flex flex-wrap items-end gap-3">
                <FormField label={t('fo.res.nightsLabel')}>
                    <Input aria-label={t('fo.res.nightsLabel')} className="w-24" inputMode="numeric" max={90} min={1} onChange={(e) => set(Number(e.target.value))} type="number" value={count === 0 ? '' : count} />
                </FormField>
                <div className="flex flex-wrap gap-1" role="group" aria-label={t('fo.res.nightsQuick')}>
                    {PRESETS.map((n) => <Button aria-pressed={count === n} key={n} onClick={() => set(n)} size="sm" type="button" variant={count === n ? 'default' : 'outline'}>{t('fo.res.nightsPreset', { n })}</Button>)}
                </div>
            </div>
            {valid && count <= 31 ? (
                <ol className="flex flex-wrap gap-2" aria-label={t('fo.res.nightBlocks')}>
                    {Array.from({ length: count }, (_, i) => addDays(arrival, i)).map((date) => (
                        <li className="flex min-w-24 flex-col items-center border border-border bg-surface-muted px-2 py-1 text-center text-xs" data-testid={`night-${date}`} key={date}>
                            <span className="text-muted-foreground">{format.weekday(date)}</span>
                            <span className="font-medium">{format.date(date, 'short')}</span>
                            <span>{price(date) === undefined ? '—' : format.money(price(date) as number, currency)}</span>
                        </li>
                    ))}
                </ol>
            ) : null}
        </div>
    );
}
