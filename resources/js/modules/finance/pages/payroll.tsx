import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import { DEFAULT_CURRENCY } from '@/modules/finance/lib/finance';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';
import type { MessageKey } from '@/locales/en/index';

type Row = {
    id: string; number: string; period: string; employees: number; net_minor: number; tax_minor: number; employee_social_minor: number; employer_social_minor: number; status: 'awaiting' | 'verified' | 'paid' | 'withdrawn';
    method: string | null; reference: string | null; paid_at: string | null; lock_version: number; may: { verify: boolean; pay: boolean };
};

const TONE: Record<Row['status'], StatusTone> = { awaiting: 'pending', verified: 'info', paid: 'success', withdrawn: 'neutral' };

/** The payroll runs that Human Resource approved: one person checks the net pay, another pays it. The tax and the social security that are owed on top are shown beside it. */
export default function PayrollPage({ overview }: { overview: { rows: Row[] } }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [verify, setVerify] = useState<{ row: Row; amount: string } | null>(null);
    const [pay, setPay] = useState<{ row: Row; method: string; reference: string } | null>(null);
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const money = (minor: number) => format.money(minor, DEFAULT_CURRENCY);
    const reload = ['overview'];

    async function doVerify() {
        if (verify === null) return;
        const result = await action.run(`/finance/payroll/${verify.row.id}/verify`, { body: { confirmed_net_minor: parseMajorToMinor(verify.amount, DEFAULT_CURRENCY) ?? 0, lock_version: verify.row.lock_version }, reload });

        if (result !== null) setVerify(null);
    }

    async function doPay() {
        if (pay === null) return;
        const result = await action.run(`/finance/payroll/${pay.row.id}/pay`, { body: { method: pay.method, reference: pay.reference.trim(), lock_version: pay.row.lock_version }, reload });

        if (result !== null) setPay(null);
    }

    const columns: DataGridColumn<Row>[] = [
        { id: 'period', label: t('fin.payroll.run'), value: (r) => r.period, rowHeader: true, cell: (r) => <span>{r.number}<span className="block text-xs text-muted-foreground">{t('fin.payroll.people', { n: r.employees })}</span></span> },
        { id: 'net', label: t('fin.payroll.net'), align: 'right', value: (r) => r.net_minor, cell: (r) => <strong>{money(r.net_minor)}</strong> },
        { id: 'tax', label: t('fin.payroll.tax'), align: 'right', value: (r) => r.tax_minor, cell: (r) => money(r.tax_minor) },
        { id: 'social', label: t('fin.payroll.social'), align: 'right', value: (r) => r.employee_social_minor + r.employer_social_minor, cell: (r) => <span>{money(r.employee_social_minor + r.employer_social_minor)}<span className="block text-xs text-muted-foreground">{t('fin.payroll.socialSplit', { people: money(r.employee_social_minor), employer: money(r.employer_social_minor) })}</span></span> },
        { id: 'status', label: t('hr.col.status'), value: (r) => r.status, filter: 'select', filterLabel: (v) => t(`fin.payroll.status.${v}` as MessageKey), cell: (r) => <span><StatusBadge label={t(`fin.payroll.status.${r.status}` as MessageKey)} tone={TONE[r.status]} />{r.reference !== null ? <span className="block text-xs text-muted-foreground">{r.method} · {r.reference}</span> : null}</span> },
        {
            id: 'act', label: '', value: () => '', sortable: false,
            cell: (r) => (
                <span className="flex gap-2">
                    {r.may.verify ? <Button onClick={() => { action.clear(); setVerify({ row: r, amount: '' }); }} size="sm" type="button">{t('fin.payroll.verify')}</Button> : null}
                    {r.may.pay ? <Button onClick={() => { action.clear(); setPay({ row: r, method: 'transfer', reference: '' }); }} size="sm" type="button">{t('fin.payroll.pay')}</Button> : null}
                </span>
            ),
        },
    ];

    return (
        <FinanceShell description={t('fin.payroll.description')} title={t('fin.payroll.title')} wide>
            {action.error !== null && verify === null && pay === null ? failure : null}
            <DataGrid caption={t('fin.payroll.title')} columns={columns} empty={<EmptyState illustration="checklist" title={t('fin.payroll.empty')} />} getRowId={(r) => r.id} id="fin.payroll" rows={overview.rows} testId="fin-payroll" />

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setVerify(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={verify === null || parseMajorToMinor(verify.amount, DEFAULT_CURRENCY) === null} loading={action.busy} onClick={() => void doVerify()} type="button">{t('fin.payroll.verify')}</Button></>}
                onClose={() => setVerify(null)}
                open={verify !== null}
                title={t('fin.payroll.verify')}
            >
                {verify !== null && (
                    <div className="grid gap-3">
                        {failure !== null ? failure : null}
                        <p className="text-sm text-muted-foreground">{t('fin.payroll.verifyHint', { run: verify.row.number, people: verify.row.employees })}</p>
                        <FormField error={action.fieldError('confirmed_net_minor')} field="confirmed_net_minor" hint={t('fin.payroll.verifyAmountHint')} label={t('fin.payroll.confirmNet')}><Input inputMode="decimal" onChange={(e) => setVerify({ ...verify, amount: e.target.value })} value={verify.amount} /></FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setPay(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={pay === null || pay.reference.trim() === ''} loading={action.busy} onClick={() => void doPay()} type="button">{t('fin.payroll.pay')}</Button></>}
                onClose={() => setPay(null)}
                open={pay !== null}
                title={t('fin.payroll.pay')}
            >
                {pay !== null && (
                    <div className="grid gap-3">
                        {failure !== null ? failure : null}
                        <p className="text-sm">{t('fin.payroll.payHint', { run: pay.row.number, net: money(pay.row.net_minor) })}</p>
                        <FormField error={action.fieldError('method')} field="method" label={t('fin.payroll.method')}><Select onChange={(e) => setPay({ ...pay, method: e.target.value })} value={pay.method}><option value="transfer">{t('fin.payroll.method.transfer')}</option><option value="cash">{t('fin.payroll.method.cash')}</option></Select></FormField>
                        <FormField error={action.fieldError('reference')} field="reference" hint={t('fin.payroll.referenceHint')} label={t('fin.payroll.reference')}><Input maxLength={80} onChange={(e) => setPay({ ...pay, reference: e.target.value })} value={pay.reference} /></FormField>
                    </div>
                )}
            </Dialog>
        </FinanceShell>
    );
}
