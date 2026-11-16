import { router } from '@inertiajs/react';
import { Undo2 } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DatePicker } from '@/components/ui/date-picker';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { RowAction } from '@/components/ui/row-action';
import { Select } from '@/components/ui/select';
import { HrShell } from '@/modules/hr/components/hr-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';

type CommissionType = { id: string; code: string; name: string; is_active: boolean; lock_version: number };
type Entry = { id: string; employee_id: string; employee_number: string | null; employee_name: string | null; type_code: string | null; type_name: string | null; earned_on: string; amount_minor: number; note: string | null; reverses_id: string | null };
type Overview = {
    currency: string; today: string; types: CommissionType[]; employees: { id: string; number: string; name: string }[]; employee_id: string | null;
    entries: Entry[]; totals: { type_id: string; code: string; name: string; total_minor: number }[];
};

/** The kinds of commission, what each person earned, and the reversal of a mistake. */
export default function CommissionsPage({ overview }: { overview: Overview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [type, setType] = useState({ code: '', name: '' });
    const [entry, setEntry] = useState({ employeeId: overview.employee_id ?? overview.employees[0]?.id ?? '', typeId: overview.types.find((x) => x.is_active)?.id ?? '', earnedOn: overview.today, amount: '', note: '' });
    const [reverse, setReverse] = useState<{ entry: Entry; reason: string } | null>(null);
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const money = (minor: number) => format.money(minor, overview.currency);
    const reload = ['overview'];
    const minor = parseMajorToMinor(entry.amount, overview.currency);
    const reversed = new Set(overview.entries.map((e) => e.reverses_id).filter((v): v is string => v !== null));

    async function addType() {
        const done = await action.run('/hr/commissions/types', { body: { code: type.code, name: type.name }, reload });
        if (done !== null) setType({ code: '', name: '' });
    }

    async function record() {
        if (minor === null) return;
        const done = await action.run('/hr/commissions', { body: { employee_id: entry.employeeId, type_id: entry.typeId, earned_on: entry.earnedOn, amount_minor: minor, note: entry.note || null }, reload });
        if (done !== null) setEntry({ ...entry, amount: '', note: '' });
    }

    async function doReverse() {
        if (reverse === null) return;
        const done = await action.run(`/hr/commissions/${reverse.entry.id}/reverse`, { body: { reason: reverse.reason.trim() }, reload });
        if (done !== null) setReverse(null);
    }

    const columns: DataGridColumn<Entry>[] = [
        { id: 'day', label: t('hr.com.col.day'), value: (e) => e.earned_on, rowHeader: true, cell: (e) => format.date(e.earned_on) },
        { id: 'employee', label: t('hr.com.col.employee'), value: (e) => e.employee_name ?? '', filter: 'select', cell: (e) => <span>{e.employee_name}<span className="block text-xs text-muted-foreground">{e.employee_number}</span></span> },
        { id: 'type', label: t('hr.com.col.type'), value: (e) => e.type_name ?? '', filter: 'select' },
        { id: 'amount', label: t('hr.com.col.amount'), value: (e) => e.amount_minor, align: 'right', cell: (e) => money(e.amount_minor) },
        { id: 'note', label: t('hr.com.col.note'), value: (e) => e.note ?? '', cell: (e) => <span>{e.note ?? ''}{e.reverses_id !== null ? <span className="block text-xs text-muted-foreground">{t('hr.com.reversal')}</span> : null}</span> },
        { id: 'act', label: '', value: () => '', sortable: false, cell: (e) => (e.reverses_id === null && !reversed.has(e.id) ? <RowAction disabled={action.busy} icon={Undo2} label={t('hr.com.reverse')} onClick={() => { action.clear(); setReverse({ entry: e, reason: '' }); }} tone="danger" /> : null) },
    ];

    return (
        <HrShell description={t('hr.com.description')} title={t('hr.com.title')}>
            {reverse === null ? failure : null}

            <section aria-labelledby="com-types-h" className="flex flex-col gap-3">
                <h2 className="text-base font-semibold" id="com-types-h">{t('hr.com.types')}</h2>
                <ul className="flex flex-col gap-2">
                    {overview.types.map((x) => (
                        <li className="flex flex-wrap items-center gap-3 text-sm" key={x.id}>
                            <span className="font-medium">{x.name}</span><span className="text-muted-foreground">{x.code}</span>
                            <Button disabled={action.busy} onClick={() => void action.run(`/hr/commissions/types/${x.id}`, { body: { name: x.name, is_active: !x.is_active, lock_version: x.lock_version }, reload })} size="sm" type="button" variant="outline">{t(x.is_active ? 'hr.com.stop' : 'hr.com.resume')}</Button>
                        </li>
                    ))}
                </ul>
                <div className="flex flex-wrap items-end gap-3">
                    <FormField error={action.fieldError('code')} field="code" label={t('hr.com.code')}><Input className="w-32" maxLength={20} onChange={(e) => setType({ ...type, code: e.target.value })} value={type.code} /></FormField>
                    <FormField error={action.fieldError('name')} field="name" label={t('hr.com.name')}><Input maxLength={80} onChange={(e) => setType({ ...type, name: e.target.value })} value={type.name} /></FormField>
                    <Button disabled={type.code.trim() === '' || type.name.trim() === ''} loading={action.busy} onClick={() => void addType()} type="button">{t('hr.com.addType')}</Button>
                </div>
            </section>

            <section aria-labelledby="com-record-h" className="flex flex-col gap-3">
                <h2 className="text-base font-semibold" id="com-record-h">{t('hr.com.record')}</h2>
                <div className="grid gap-3 sm:grid-cols-2">
                    <FormField error={action.fieldError('employee_id')} field="employee_id" label={t('hr.com.employee')}>
                        <Select onChange={(e) => setEntry({ ...entry, employeeId: e.target.value })} value={entry.employeeId}>{overview.employees.map((e) => <option key={e.id} value={e.id}>{e.number} · {e.name}</option>)}</Select>
                    </FormField>
                    <FormField error={action.fieldError('type_id')} field="type_id" label={t('hr.com.type')}>
                        <Select onChange={(e) => setEntry({ ...entry, typeId: e.target.value })} value={entry.typeId}>{overview.types.filter((x) => x.is_active).map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</Select>
                    </FormField>
                    <FormField error={action.fieldError('earned_on')} field="earned_on" label={t('hr.com.earnedOn')}><DatePicker max={overview.today} onChange={(e) => setEntry({ ...entry, earnedOn: e.target.value })} value={entry.earnedOn} /></FormField>
                    <FormField error={action.fieldError('amount_minor')} field="amount_minor" label={t('hr.com.amount')}><Input inputMode="decimal" onChange={(e) => setEntry({ ...entry, amount: e.target.value })} value={entry.amount} /></FormField>
                    <div className="sm:col-span-2"><FormField error={action.fieldError('note')} field="note" label={t('hr.com.note')}><Input maxLength={200} onChange={(e) => setEntry({ ...entry, note: e.target.value })} value={entry.note} /></FormField></div>
                </div>
                <div><Button disabled={minor === null || minor < 1 || entry.employeeId === '' || entry.typeId === ''} loading={action.busy} onClick={() => void record()} type="button">{t('hr.com.save')}</Button></div>
            </section>

            <section aria-labelledby="com-entries-h" className="flex flex-col gap-3">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-base font-semibold" id="com-entries-h">{t('hr.com.entries')}</h2>
                    <Select aria-label={t('hr.com.employee')} onChange={(e) => router.get('/hr/commissions', e.target.value === '' ? {} : { employee: e.target.value }, { preserveScroll: true })} value={overview.employee_id ?? ''}>
                        <option value="">{t('hr.com.all')}</option>
                        {overview.employees.map((e) => <option key={e.id} value={e.id}>{e.number} · {e.name}</option>)}
                    </Select>
                </div>
                {overview.employee_id !== null && overview.totals.length > 0 ? (
                    <p className="text-sm text-muted-foreground">{t('hr.com.totalsOf')}: {overview.totals.map((x) => `${x.name} ${money(x.total_minor)}`).join(' · ')}</p>
                ) : null}
                <DataGrid caption={t('hr.com.entries')} columns={columns} empty={<EmptyState illustration="bell" title={t('hr.com.empty')} />} getRowId={(e) => e.id} id="hr.commissions" rows={overview.entries} testId="hr-commissions" />
            </section>

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setReverse(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button disabled={reverse === null || reverse.reason.trim() === ''} loading={action.busy} onClick={() => void doReverse()} type="button">{t('hr.com.reverseConfirm')}</Button>
                </>}
                onClose={() => setReverse(null)}
                open={reverse !== null}
                title={t('hr.com.reverse')}
            >
                {reverse !== null && (
                    <div className="flex flex-col gap-3">
                        {failure}
                        <FormField error={action.fieldError('reason')} field="reason" label={t('hr.com.reverseReason')}><Input maxLength={200} onChange={(e) => setReverse({ ...reverse, reason: e.target.value })} value={reverse.reason} /></FormField>
                    </div>
                )}
            </Dialog>
        </HrShell>
    );
}
