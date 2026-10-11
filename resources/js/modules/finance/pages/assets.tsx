import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DatePicker } from '@/components/ui/date-picker';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { MoneyInput } from '@/components/ui/money-input';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';

type Asset = { id: string; code: string; name: string; category: string; acquired_on: string; cost_minor: number; salvage_minor: number; life_months: number; monthly_minor: number; accumulated_minor: number; book_minor: number; depreciated_through: string | null; pending_months: number; status: string; disposed_on: string | null; disposal_kind: string | null; disposal_proceeds_minor: number | null; lock_version: number; notes: string | null };
type Overview = { currency: string; today: string; through: string; categories: string[]; baseline_life: Record<string, number>; kinds: string[]; methods: string[]; assets: Asset[]; pending_months: number; totals: { cost_minor: number; book_minor: number }; may_manage: boolean };

/** The register of fixed assets with their straight-line depreciation, the monthly run, and what happens when an asset leaves. */
export default function AssetsPage({ overview }: { overview: Overview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const blank = { code: '', name: '', category: 'furniture', acquired: overview.today, cost: '', salvage: '0', life: String(overview.baseline_life.furniture ?? 48), notes: '' };
    const [add, setAdd] = useState<typeof blank | null>(null);
    const [dispose, setDispose] = useState<{ asset: Asset; kind: string; on: string; proceeds: string; method: string; reason: string } | null>(null);
    const [ran, setRan] = useState<{ months: number; amount_minor: number } | null>(null);
    const reload = ['overview'];
    const money = (minor: number) => format.money(minor, overview.currency);

    async function save() {
        if (add === null) return;
        const done = await action.run('/finance/assets', { body: { code: add.code, name: add.name, category: add.category, acquired_on: add.acquired, cost_minor: parseMajorToMinor(add.cost, overview.currency) ?? 0, salvage_minor: parseMajorToMinor(add.salvage, overview.currency) ?? 0, life_months: Number(add.life) || 0, notes: add.notes || null }, reload });
        if (done !== null) setAdd(null);
    }

    async function run() {
        const done = await action.run<{ run: { months: number; amount_minor: number } }>('/finance/assets/depreciation', { body: { through: overview.through }, reload });
        if (done !== null) setRan(done.run);
    }

    async function leave() {
        if (dispose === null) return;
        const proceeds = parseMajorToMinor(dispose.proceeds, overview.currency) ?? 0;
        const done = await action.run(`/finance/assets/${dispose.asset.id}/dispose`, { body: { kind: dispose.kind, disposed_on: dispose.on, proceeds_minor: dispose.kind === 'sale' ? proceeds : 0, method: dispose.kind === 'sale' && proceeds > 0 ? dispose.method : null, reason: dispose.reason.trim(), lock_version: dispose.asset.lock_version }, reload });
        if (done !== null) setDispose(null);
    }

    const columns: DataGridColumn<Asset>[] = [
        { id: 'asset', label: t('fin.as.col.asset'), value: (a) => a.code, rowHeader: true, cell: (a) => <span>{a.code} · {a.name}<span className="block text-xs text-muted-foreground">{t(`fin.as.cat.${a.category}` as 'fin.as.cat.other')} · {format.date(a.acquired_on)} · {t('fin.as.lifeMonths', { n: a.life_months })}</span></span> },
        { id: 'cost', label: t('fin.as.col.cost'), align: 'right', value: (a) => a.cost_minor, cell: (a) => money(a.cost_minor) },
        { id: 'monthly', label: t('fin.as.col.monthly'), align: 'right', value: (a) => a.monthly_minor, cell: (a) => money(a.monthly_minor) },
        { id: 'accumulated', label: t('fin.as.col.accumulated'), align: 'right', value: (a) => a.accumulated_minor, cell: (a) => <span>{money(a.accumulated_minor)}<span className="block text-xs text-muted-foreground">{a.depreciated_through ?? '—'}</span></span> },
        { id: 'book', label: t('fin.as.col.book'), align: 'right', value: (a) => a.book_minor, cell: (a) => money(a.book_minor) },
        { id: 'status', label: t('fin.as.col.status'), value: (a) => a.status, filter: 'select', filterLabel: (v) => t(`fin.as.status.${v}` as 'fin.as.status.active'), cell: (a) => (a.status === 'active' ? <span className="flex items-center gap-2"><StatusBadge label={t('fin.as.status.active')} tone="success" />{a.pending_months > 0 ? <span className="text-xs text-warning">{t('fin.as.pending', { n: a.pending_months })}</span> : null}</span> : <StatusBadge label={`${t(`fin.as.kind.${a.disposal_kind}` as 'fin.as.kind.sale')} ${a.disposed_on === null ? '' : format.date(a.disposed_on)}`} tone="neutral" />) },
        { id: 'actions', label: '', value: () => '', sortable: false, cell: (a) => (overview.may_manage && a.status === 'active' ? <Button onClick={() => { action.clear(); setDispose({ asset: a, kind: 'sale', on: overview.today, proceeds: '0', method: 'transfer', reason: '' }); }} size="sm" type="button" variant="outline">{t('fin.as.leaves')}</Button> : null) },
    ];

    return (
        <FinanceShell description={t('fin.as.description')} title={t('fin.as.title')} wide>
            {action.error !== null && add === null && dispose === null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            {ran !== null ? <Alert title={t('fin.as.ran', { months: ran.months, amount: money(ran.amount_minor) })} tone="success" /> : null}
            <div className="flex flex-wrap items-center gap-3 print:hidden">
                {overview.may_manage ? <Button onClick={() => { action.clear(); setAdd({ ...blank }); }} type="button">{t('fin.as.add')}</Button> : null}
                {overview.may_manage ? <Button disabled={overview.pending_months === 0 || action.busy} onClick={() => void run()} type="button" variant="outline">{t('fin.as.run', { through: overview.through })}</Button> : null}
                <span className="text-sm text-muted-foreground">{t('fin.as.totals', { cost: money(overview.totals.cost_minor), book: money(overview.totals.book_minor) })}</span>
            </div>
            <DataGrid caption={t('fin.as.title')} columns={columns} empty={<EmptyState title={t('fin.as.none')} />} getRowId={(a) => a.id} id="fin.assets" rows={overview.assets} testId="fixed-assets" />

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setAdd(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void save()} type="button">{t('fin.as.add')}</Button></>}
                onClose={() => setAdd(null)}
                open={add !== null}
                title={t('fin.as.add')}
            >
                {add !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <FormField error={action.fieldError('code')} field="code" label={t('fin.acc.code')}><Input maxLength={20} onChange={(e) => setAdd({ ...add, code: e.target.value })} value={add.code} /></FormField>
                        <FormField error={action.fieldError('name')} field="name" label={t('fin.acc.name')}><Input maxLength={120} onChange={(e) => setAdd({ ...add, name: e.target.value })} value={add.name} /></FormField>
                        <FormField error={action.fieldError('category')} field="category" label={t('fin.acc.category')}><Select onChange={(e) => setAdd({ ...add, category: e.target.value, life: String(overview.baseline_life[e.target.value] ?? add.life) })} value={add.category}>{overview.categories.map((c) => <option key={c} value={c}>{t(`fin.as.cat.${c}` as 'fin.as.cat.other')}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('acquired_on')} field="acquired_on" label={t('fin.as.acquired')}><DatePicker max={overview.today} onChange={(e) => setAdd({ ...add, acquired: e.target.value })} value={add.acquired} /></FormField>
                        <FormField error={action.fieldError('cost_minor')} field="cost_minor" label={t('fin.as.col.cost')}><MoneyInput onChange={(e) => setAdd({ ...add, cost: e.target.value })} value={add.cost} /></FormField>
                        <FormField error={action.fieldError('salvage_minor')} field="salvage_minor" hint={t('fin.as.salvageHint')} label={t('fin.as.salvage')}><MoneyInput onChange={(e) => setAdd({ ...add, salvage: e.target.value })} value={add.salvage} /></FormField>
                        <FormField error={action.fieldError('life_months')} field="life_months" hint={t('fin.as.lifeHint')} label={t('fin.as.life')}><Input inputMode="numeric" maxLength={3} onChange={(e) => setAdd({ ...add, life: e.target.value })} value={add.life} /></FormField>
                        <FormField error={action.fieldError('notes')} field="notes" label={t('fin.as.notes')}><Input maxLength={300} onChange={(e) => setAdd({ ...add, notes: e.target.value })} value={add.notes} /></FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setDispose(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={dispose?.reason.trim() === ''} loading={action.busy} onClick={() => void leave()} type="button">{t('fin.as.leaves')}</Button></>}
                onClose={() => setDispose(null)}
                open={dispose !== null}
                title={t('fin.as.leavesTitle')}
            >
                {dispose !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <p className="text-sm sm:col-span-2">{t('fin.as.leavesBody', { asset: dispose.asset.name, book: money(dispose.asset.book_minor) })}</p>
                        <FormField error={action.fieldError('kind')} field="kind" label={t('fin.as.how')}><Select onChange={(e) => setDispose({ ...dispose, kind: e.target.value })} value={dispose.kind}>{overview.kinds.map((k) => <option key={k} value={k}>{t(`fin.as.kind.${k}` as 'fin.as.kind.sale')}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('disposed_on')} field="disposed_on" label={t('fin.as.on')}><DatePicker max={overview.today} min={dispose.asset.acquired_on} onChange={(e) => setDispose({ ...dispose, on: e.target.value })} value={dispose.on} /></FormField>
                        {dispose.kind === 'sale' ? (
                            <>
                                <FormField error={action.fieldError('proceeds_minor')} field="proceeds_minor" label={t('fin.as.proceeds')}><MoneyInput onChange={(e) => setDispose({ ...dispose, proceeds: e.target.value })} value={dispose.proceeds} /></FormField>
                                <FormField error={action.fieldError('method')} field="method" label={t('fin.as.paidBy')}><Select onChange={(e) => setDispose({ ...dispose, method: e.target.value })} value={dispose.method}>{overview.methods.map((m) => <option key={m} value={m}>{t(`fo.com.method.${m}` as 'fo.com.method.cash')}</option>)}</Select></FormField>
                            </>
                        ) : null}
                        <div className="sm:col-span-2"><FormField error={action.fieldError('reason')} field="reason" label={t('fo.lock.reason')}><Input maxLength={200} onChange={(e) => setDispose({ ...dispose, reason: e.target.value })} value={dispose.reason} /></FormField></div>
                    </div>
                )}
            </Dialog>
        </FinanceShell>
    );
}
