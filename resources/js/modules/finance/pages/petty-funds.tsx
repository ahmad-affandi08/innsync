import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { MoneyInput } from '@/components/ui/money-input';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import type { PettyFund } from '@/modules/finance/lib/finance';
import { minorToInput } from '@/modules/inventory-purchasing/lib/amounts';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';
import type { MessageKey } from '@/locales/en/index';

type Overview = { funds: PettyFund[]; default_max_voucher_minor: number; custodians: { id: string; name: string }[]; may: { manage: boolean } };
type Form = { code: string; name: string; custodian_id: string; imprest: string; max: string };

const FALLBACK_CURRENCY = 'IDR';

/** The petty cash funds: a fixed amount handed to a custodian, with what is left of it and what still has to be accounted for. */
export default function PettyFundsPage({ overview }: { overview: Overview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<Form | null>(null);
    const [badImprest, setBadImprest] = useState(false);
    const [badMax, setBadMax] = useState(false);
    const currency = overview.funds[0]?.currency ?? FALLBACK_CURRENCY;
    const fundState = (f: PettyFund) => (!f.active ? 'closed' : f.pending_settlement_id !== null ? 'waiting' : 'active');
    const stateLabel = (s: string) => t(`fin.petty.fundState.${s}` as MessageKey);
    const reload = ['overview'];

    function openNew() {
        action.clear();
        setBadImprest(false);
        setBadMax(false);
        setForm({ code: '', name: '', custodian_id: '', imprest: '', max: minorToInput(overview.default_max_voucher_minor, currency) });
    }

    async function save() {
        if (form === null) return;
        const imprest = parseMajorToMinor(form.imprest, currency);
        const max = form.max.trim() === '' ? null : parseMajorToMinor(form.max, currency);
        const invalidMax = form.max.trim() !== '' && max === null;

        setBadImprest(imprest === null);
        setBadMax(invalidMax);
        if (imprest === null || invalidMax) return;
        const done = await action.run('/finance/petty', {
            body: { code: form.code.trim(), name: form.name.trim(), custodian_id: form.custodian_id, imprest_minor: imprest, max_voucher_minor: max },
            reload,
        });
        if (done !== null) setForm(null);
    }

    const columns: DataGridColumn<PettyFund>[] = [
        { id: 'code', label: t('fin.petty.code'), value: (f) => f.code, rowHeader: true },
        { id: 'name', label: t('fin.petty.name'), value: (f) => f.name },
        { id: 'custodian', label: t('fin.petty.custodian'), value: (f) => f.custodian_name ?? '', cell: (f) => f.custodian_name ?? '—' },
        { id: 'imprest', label: t('fin.petty.imprest'), align: 'right', value: (f) => f.imprest_minor, cell: (f) => format.money(f.imprest_minor, f.currency) },
        {
            id: 'balance', label: t('fin.petty.balance'), align: 'right', value: (f) => f.balance_minor,
            cell: (f) => <span className={f.balance_minor < f.imprest_minor ? 'font-medium' : undefined}>{format.money(f.balance_minor, f.currency)}</span>,
        },
        {
            id: 'unsettled', label: t('fin.petty.unsettled'), align: 'right', value: (f) => f.unsettled_minor,
            cell: (f) => (f.unsettled_count === 0 ? '—' : `${format.number(f.unsettled_count)} · ${format.money(f.unsettled_minor, f.currency)}`),
        },
        { id: 'limit', label: t('fin.petty.limit'), align: 'right', value: (f) => f.max_voucher_minor ?? -1, cell: (f) => (f.max_voucher_minor === null ? t('fin.petty.noLimit') : format.money(f.max_voucher_minor, f.currency)), hidden: true },
        {
            id: 'state', label: t('inv.col.status'), value: fundState, filter: 'select', filterLabel: stateLabel,
            cell: (f) => (
                <StatusBadge label={stateLabel(fundState(f))} tone={!f.active ? 'neutral' : f.pending_settlement_id !== null ? 'pending' : 'success'} />
            ),
        },
        { id: 'actions', label: t('inv.col.actions'), cell: (f) => <Button asChild size="sm" variant="outline"><Link href={`/finance/petty/${f.id}`}>{t('fin.petty.open')}</Link></Button> },
    ];

    return (
        <FinanceShell actions={overview.may.manage ? <Button onClick={openNew} type="button">{t('fin.petty.new')}</Button> : undefined} description={t('fin.petty.description')} title={t('fin.petty.title')} wide>
            <DataGrid caption={t('fin.petty.title')} columns={columns} empty={<EmptyState title={t('fin.petty.empty')} />} getRowId={(f) => f.id} id="fin.petty.funds" rows={overview.funds} testId="petty-funds" />

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void save()} type="button">{t('fin.petty.create')}</Button>
                </>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={t('fin.petty.new')}
            >
                {form !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <p className="text-sm text-muted-foreground sm:col-span-2">{t('fin.petty.newHint')}</p>
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <FormField error={action.fieldError('code')} field="code" hint={t('fin.petty.codeHint')} label={t('fin.petty.code')}>
                            <Input maxLength={12} onChange={(e) => setForm({ ...form, code: e.target.value })} value={form.code} />
                        </FormField>
                        <FormField error={action.fieldError('name')} field="name" label={t('fin.petty.name')}>
                            <Input maxLength={80} onChange={(e) => setForm({ ...form, name: e.target.value })} value={form.name} />
                        </FormField>
                        <div className="sm:col-span-2">
                            <FormField error={action.fieldError('custodian_id')} field="custodian_id" hint={overview.custodians.length === 0 ? t('fin.petty.noCustodians') : t('fin.petty.custodianHint')} label={t('fin.petty.custodian')}>
                                <Select onChange={(e) => setForm({ ...form, custodian_id: e.target.value })} value={form.custodian_id}>
                                    <option value="">{t('fin.petty.chooseCustodian')}</option>
                                    {overview.custodians.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                                </Select>
                            </FormField>
                        </div>
                        <FormField error={badImprest ? t('fin.petty.badAmount') : action.fieldError('imprest_minor')} field="imprest_minor" hint={t('fin.petty.imprestHint')} label={t('fin.petty.imprestField', { currency })}>
                            <MoneyInput onChange={(e) => { setBadImprest(false); setForm({ ...form, imprest: e.target.value }); }} value={form.imprest} />
                        </FormField>
                        <FormField error={badMax ? t('fin.petty.badAmount') : action.fieldError('max_voucher_minor')} field="max_voucher_minor" hint={t('fin.petty.maxHint')} label={t('fin.petty.maxField', { currency })}>
                            <MoneyInput onChange={(e) => { setBadMax(false); setForm({ ...form, max: e.target.value }); }} value={form.max} />
                        </FormField>
                    </div>
                )}
            </Dialog>
        </FinanceShell>
    );
}
