import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DatePicker } from '@/components/ui/date-picker';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { Metric } from '@/components/ui/metric';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { KitchenShell } from '@/modules/kitchen/components/kitchen-shell';
import type { MenuClass, MenuReport, MenuReportRow } from '@/modules/kitchen/lib/kitchen';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import type { MessageKey } from '@/locales/en/index';

const TONE = { star: 'success', plowhorse: 'info', puzzle: 'warning', dog: 'neutral' } as const;
const percent = (bp: number | null) => (bp === null ? '—' : `${(bp / 100).toFixed(1)}%`);

/** What each dish sold and cost in a period, the food cost ratio, and the menu engineering class of every dish that can be classed. */
export default function MenuReportPage({ report }: { report: MenuReport }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const [from, setFrom] = useState(report.from);
    const [to, setTo] = useState(report.to);
    const [outlet, setOutlet] = useState(report.outlet_id ?? '');
    const money = (minor: number | null) => (minor === null ? '—' : format.money(minor, report.currency));
    const classes: MenuClass[] = ['star', 'plowhorse', 'puzzle', 'dog'];

    function apply() {
        router.get('/kitchen/menu-report', { from, to, ...(outlet === '' ? {} : { outlet }) }, { preserveScroll: true });
    }

    const columns: DataGridColumn<MenuReportRow>[] = [
        { id: 'name', label: t('kitchen.rep.dish'), value: (r) => r.name, rowHeader: true, cell: (r) => <span>{r.name}<span className="block text-xs text-muted-foreground">{r.category} · {r.outlet}</span></span> },
        { id: 'portions', label: t('kitchen.rep.portions'), align: 'right', value: (r) => r.portions, cell: (r) => r.portions },
        { id: 'net', label: t('kitchen.rep.net'), align: 'right', value: (r) => r.net_minor, cell: (r) => money(r.net_minor) },
        { id: 'avg', label: t('kitchen.rep.avg'), align: 'right', value: (r) => r.avg_price_minor, cell: (r) => money(r.avg_price_minor) },
        { id: 'cost', label: t('kitchen.rep.cost'), align: 'right', value: (r) => r.cost_minor ?? -1, cell: (r) => (r.cost_state === 'none' ? <span className="text-muted-foreground">{r.has_recipe ? t('kitchen.rep.noConsumption') : t('kitchen.rep.noRecipe')}</span> : <span>{money(r.cost_minor)}{r.cost_state === 'partial' ? <span className="block text-xs text-muted-foreground">{t('kitchen.rep.partial')}</span> : null}</span>) },
        { id: 'ratio', label: t('kitchen.rep.ratio'), align: 'right', value: (r) => r.cost_bp ?? -1, cell: (r) => percent(r.cost_bp) },
        { id: 'margin', label: t('kitchen.rep.margin'), align: 'right', value: (r) => r.margin_per_portion_minor ?? -1, cell: (r) => money(r.margin_per_portion_minor) },
        { id: 'mix', label: t('kitchen.rep.mix'), align: 'right', value: (r) => r.mix_bp ?? -1, cell: (r) => percent(r.mix_bp) },
        { id: 'class', label: t('kitchen.rep.class'), value: (r) => r.class ?? '', cell: (r) => (r.class === null ? '—' : <StatusBadge label={t(`kitchen.rep.class.${r.class}` as MessageKey)} tone={TONE[r.class]} />) },
    ];

    return (
        <KitchenShell description={t('kitchen.rep.description')} title={t('kitchen.rep.title')}>
            <form className="flex flex-wrap items-end gap-3" onSubmit={(e) => { e.preventDefault(); apply(); }}>
                <FormField label={t('kitchen.rep.from')}><DatePicker onChange={(e) => setFrom(e.target.value)} value={from} /></FormField>
                <FormField label={t('kitchen.rep.to')}><DatePicker onChange={(e) => setTo(e.target.value)} value={to} /></FormField>
                <FormField label={t('fnb.px.outlet')}>
                    <Select onChange={(e) => setOutlet(e.target.value)} value={outlet}>
                        <option value="">{t('kitchen.rep.allOutlets')}</option>
                        {report.outlets.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                    </Select>
                </FormField>
                <Button type="submit">{t('kitchen.rep.show')}</Button>
            </form>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Metric label={t('kitchen.rep.portions')} value={report.totals.portions} />
                <Metric detail={report.totals.discount_minor > 0 ? t('kitchen.rep.discounts', { amount: money(report.totals.discount_minor) }) : undefined} label={t('kitchen.rep.net')} value={money(report.totals.net_minor)} />
                <Metric detail={t('kitchen.rep.costedOf', { costed: report.totals.costed_dishes, dishes: report.totals.dishes })} label={t('kitchen.rep.ratio')} value={percent(report.totals.cost_bp)} />
                <Metric label={t('kitchen.rep.cost')} value={money(report.totals.cost_minor)} />
            </div>
            {report.totals.dishes > 0 && report.totals.costed_dishes < report.totals.dishes ? <Alert title={t('kitchen.rep.someUncosted')} tone="info" /> : null}
            <DataGrid caption={t('kitchen.rep.title')} columns={columns} empty={<EmptyState illustration="checklist" title={t('kitchen.rep.empty')} />} getRowId={(r) => r.item_id} id="kitchen.menu-report" rows={report.rows} testId="kitchen-menu-report" />
            <section aria-labelledby="kit-class-h" className="flex flex-col gap-1 text-sm">
                <h2 className="font-semibold" id="kit-class-h">{t('kitchen.rep.classes')}</h2>
                <ul className="flex flex-col gap-1 text-muted-foreground">
                    {classes.map((c) => <li key={c}><strong className="text-foreground">{t(`kitchen.rep.class.${c}` as MessageKey)}</strong>: {t(`kitchen.rep.classHint.${c}` as MessageKey)}</li>)}
                </ul>
                <p className="text-xs text-muted-foreground">{t('kitchen.rep.method')}</p>
            </section>
        </KitchenShell>
    );
}
