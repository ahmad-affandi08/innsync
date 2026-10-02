import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Night = {
    date: string; total: number; blocked: number; held: number; sold: number; available: number; allowance: number; oversold: boolean;
    restrictions: { stop_sell: boolean; closed_to_arrival: boolean; closed_to_departure: boolean; min_stay: number | null; has_price: boolean } | null;
};
type TypeRow = { id: string; code: string; name: string; nights: Night[] };
type Calendar = { from: string; days: number; types: TypeRow[] };

/** State is text and shape as well as color (NFR-27): the number, a word on small screens' labels, and a marker. */
function stateOf(n: Night): 'oversold' | 'soldOut' | 'open' {
    if (n.available < 0) return 'oversold';
    if (n.available === 0) return 'soldOut';

    return 'open';
}

const cellStyle = { oversold: 'bg-danger/15 text-danger font-semibold', soldOut: 'bg-warning/15 text-warning font-semibold', open: 'text-foreground' } as const;

export default function AvailabilityPage({ calendar, plans, selected_plan }: { calendar: Calendar; plans: { id: string; code: string; name: string }[]; selected_plan: string | null }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const [form, setForm] = useState({ from: calendar.from, days: String(calendar.days), plan: selected_plan ?? '' });

    function show() {
        router.get('/front-office/availability', { from: form.from, days: form.days, plan: form.plan || undefined }, { preserveState: true });
    }

    return (
        <FrontOfficeShell description={t('fo.availability.description')} title={t('fo.availability.title')} wide>
            <form className="grid gap-3 sm:grid-cols-5" onSubmit={(e) => { e.preventDefault(); show(); }}>
                <FormField label={t('fo.availability.from')}><Input onChange={(e) => setForm({ ...form, from: e.target.value })} type="date" value={form.from} /></FormField>
                <FormField label={t('fo.availability.days')}><Input inputMode="numeric" onChange={(e) => setForm({ ...form, days: e.target.value })} value={form.days} /></FormField>
                <FormField label={t('fo.availability.plan')}>
                    <Select onChange={(e) => setForm({ ...form, plan: e.target.value })} value={form.plan}>
                        <option value="">{t('fo.availability.noPlan')}</option>
                        {plans.map((p) => <option key={p.id} value={p.id}>{p.code} · {p.name}</option>)}
                    </Select>
                </FormField>
                <div className="flex items-end"><Button type="submit">{t('fo.availability.show')}</Button></div>
            </form>

            <p className="flex flex-wrap gap-3 text-xs text-muted-foreground" role="note">
                <span className="font-medium">{t('fo.availability.legend')}:</span>
                <span>{t('fo.availability.state.open')}</span>
                <span className="text-warning">▲ {t('fo.availability.state.soldOut')}</span>
                <span className="text-danger">▲▲ {t('fo.availability.state.oversold')}</span>
            </p>

            {calendar.types.length === 0 ? <EmptyState title={t('fo.availability.empty')} /> : calendar.types.map((type) => (
                <section aria-labelledby={`t-${type.id}`} className="flex flex-col gap-2" key={type.id}>
                    <h2 className="text-lg font-semibold" id={`t-${type.id}`}>{type.code} · {type.name}</h2>
                    {/* The region is the scroller (keyboard focusable), so the table's own container must not scroll. */}
                    <div className="overflow-x-auto [&_[data-slot=table-container]]:overflow-visible" role="region" tabIndex={0} aria-label={`${type.code} ${t('fo.availability.title')}`}>
                        <Table className="w-full min-w-max border-collapse text-center">
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">{type.nights.map((n) => (
                                    <TableHead className="border border-border px-2 py-1 text-center" key={n.date} scope="col">{format.date(n.date, 'short')}</TableHead>
                                ))}</TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow className="hover:bg-transparent">{type.nights.map((n) => {
                                    const state = stateOf(n);
                                    const r = n.restrictions;

                                    return (
                                        <TableCell
                                            aria-label={t('fo.availability.cellLabel', { type: type.code, date: format.date(n.date), available: n.available, total: n.total, sold: n.sold, blocked: n.blocked, held: n.held, allowance: n.allowance })}
                                            className={`border border-border px-2 py-2 align-top ${cellStyle[state]}`}
                                            key={n.date}
                                        >
                                            <div>{state === 'oversold' ? '▲▲ ' : state === 'soldOut' ? '▲ ' : ''}{t('fo.availability.cell', { available: n.available, total: n.total })}</div>
                                            {r !== null && (
                                                <div className="mt-1 flex flex-wrap justify-center gap-1 text-[10px] font-normal text-muted-foreground">
                                                    {r.stop_sell && <span>{t('fo.availability.marker.stop')}</span>}
                                                    {r.closed_to_arrival && <span>{t('fo.availability.marker.cta')}</span>}
                                                    {r.closed_to_departure && <span>{t('fo.availability.marker.ctd')}</span>}
                                                    {r.min_stay !== null && <span>{t('fo.availability.marker.min', { n: r.min_stay })}</span>}
                                                    {!r.has_price && <span>{t('fo.availability.marker.noPrice')}</span>}
                                                </div>
                                            )}
                                        </TableCell>
                                    );
                                })}</TableRow>
                            </TableBody>
                        </Table>
                    </div>
                </section>
            ))}
        </FrontOfficeShell>
    );
}
