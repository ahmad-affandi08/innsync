import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DatePicker } from '@/components/ui/date-picker';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { HrShell } from '@/modules/hr/components/hr-shell';
import type { ConductKind, ConductOverview, ConductRecord } from '@/modules/hr/lib/hr';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

const TONE: Record<ConductRecord['state'], StatusTone> = { active: 'warning', expired: 'neutral', award: 'success', revoked: 'neutral' };

/** Reprimands, warning letters and awards of the staff, with the letter and the day until which a warning holds. */
export default function ConductPage({ overview }: { overview: ConductOverview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [add, setAdd] = useState<{ employeeId: string; kind: ConductKind; issuedOn: string; validUntil: string; reason: string } | null>(null);
    const [file, setFile] = useState<File | null>(null);
    const [fileKey, setFileKey] = useState(0);
    const [revoke, setRevoke] = useState<{ record: ConductRecord; reason: string } | null>(null);
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const label = (prefix: string, key: string) => t(`${prefix}.${key}` as MessageKey);
    const reload = ['overview'];

    async function save() {
        if (add === null) return;
        const body = new FormData();
        body.set('employee_id', add.employeeId);
        body.set('kind', add.kind);
        body.set('issued_on', add.issuedOn);
        if (add.validUntil !== '') body.set('valid_until', add.validUntil);
        body.set('reason', add.reason.trim());
        if (file !== null) body.set('letter', file);
        const result = await action.run('/hr/conduct', { idempotencyKey: newIdempotencyKey(), body, reload });

        if (result !== null) {
            setAdd(null);
            setFile(null);
            setFileKey((k) => k + 1);
        }
    }

    async function doRevoke() {
        if (revoke === null) return;
        const result = await action.run(`/hr/conduct/${revoke.record.id}/revoke`, { body: { reason: revoke.reason.trim(), lock_version: revoke.record.lock_version }, reload });

        if (result !== null) setRevoke(null);
    }

    const columns = (withName: boolean): DataGridColumn<ConductRecord>[] => [
        ...(withName ? [{ id: 'name', label: t('hr.col.name'), value: (r: ConductRecord) => r.employee.name, rowHeader: true, cell: (r: ConductRecord) => <span>{r.employee.name}<span className="block text-xs text-muted-foreground">{r.employee.number} · {label('hr.department', r.employee.department)}</span></span> }] : []),
        { id: 'kind', label: t('hr.conduct.kind'), value: (r) => r.kind, filter: 'select', filterLabel: (v) => label('hr.conduct.kinds', v), cell: (r) => label('hr.conduct.kinds', r.kind) },
        { id: 'issued', label: t('hr.conduct.issuedOn'), value: (r) => r.issued_on, cell: (r) => format.date(r.issued_on) },
        { id: 'until', label: t('hr.conduct.validUntil'), value: (r) => r.valid_until ?? '', cell: (r) => (r.valid_until === null ? '—' : format.date(r.valid_until)) },
        { id: 'state', label: t('hr.col.status'), value: (r) => r.state, filter: 'select', filterLabel: (v) => label('hr.conduct.state', v), cell: (r) => <StatusBadge label={label('hr.conduct.state', r.state)} tone={TONE[r.state]} /> },
        { id: 'reason', label: t('hr.att.reasonShort'), value: (r) => r.reason, cell: (r) => <span>{r.reason}{r.revoke_reason !== null ? <span className="block text-xs text-muted-foreground">{t('hr.conduct.revokedBecause', { reason: r.revoke_reason })}</span> : null}</span> },
        {
            id: 'act', label: '', value: () => '', sortable: false,
            cell: (r) => (
                <span className="flex flex-wrap gap-2">
                    {r.has_letter ? <Button asChild size="sm" variant="outline"><a href={`/hr/conduct/${r.id}/letter`} rel="noreferrer" target="_blank">{t('hr.conduct.letter')}</a></Button> : null}
                    {r.may.revoke ? <Button onClick={() => { action.clear(); setRevoke({ record: r, reason: '' }); }} size="sm" type="button" variant="outline">{t('hr.conduct.revoke')}</Button> : null}
                </span>
            ),
        },
    ];

    return (
        <HrShell actions={overview.may.manage ? <Button onClick={() => { action.clear(); setFile(null); setAdd({ employeeId: overview.employees?.[0]?.id ?? '', kind: 'verbal', issuedOn: overview.today, validUntil: '', reason: '' }); }} type="button">{t('hr.conduct.add')}</Button> : undefined} description={t('hr.conduct.description')} title={t('hr.conduct.title')}>
            {action.error !== null && add === null && revoke === null ? failure : null}
            <Tabs defaultValue={overview.may.manage ? 'all' : 'mine'}>
                <TabsList aria-label={t('hr.conduct.title')}>
                    {overview.may.manage ? <TabsTrigger value="all">{t('hr.conduct.allTab')}</TabsTrigger> : null}
                    <TabsTrigger value="mine">{t('hr.conduct.mineTab')}</TabsTrigger>
                </TabsList>
                {overview.may.manage ? (
                    <TabsContent className="flex flex-col gap-3" value="all">
                        <FormField label={t('hr.col.name')}>
                            <Select onChange={(e) => router.get('/hr/conduct', e.target.value === '' ? {} : { employee: e.target.value })} value={overview.selected ?? ''}><option value="">{t('hr.conduct.everyone')}</option>{(overview.employees ?? []).map((e) => <option key={e.id} value={e.id}>{e.name} · {e.number}</option>)}</Select>
                        </FormField>
                        <DataGrid caption={t('hr.conduct.allTab')} columns={columns(true)} empty={<EmptyState illustration="checklist" title={t('hr.conduct.none')} />} getRowId={(r) => r.id} id="hr.conduct.all" rows={overview.records ?? []} testId="hr-conduct-all" />
                    </TabsContent>
                ) : null}
                <TabsContent className="flex flex-col gap-3" value="mine">
                    <DataGrid caption={t('hr.conduct.mineTab')} columns={columns(false)} empty={<EmptyState illustration="checklist" title={t('hr.conduct.noneMine')} />} getRowId={(r) => r.id} id="hr.conduct.mine" rows={overview.mine} testId="hr-conduct-mine" />
                </TabsContent>
            </Tabs>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setAdd(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={add?.employeeId === '' || add?.reason.trim() === '' || add?.issuedOn === ''} loading={action.busy} onClick={() => void save()} type="button">{t('hr.conduct.save')}</Button></>}
                onClose={() => setAdd(null)}
                open={add !== null}
                title={t('hr.conduct.add')}
            >
                {add !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {failure !== null ? <div className="sm:col-span-2">{failure}</div> : null}
                        <div className="sm:col-span-2"><FormField error={action.fieldError('employee_id')} field="employee_id" label={t('hr.col.name')}><Select onChange={(e) => setAdd({ ...add, employeeId: e.target.value })} value={add.employeeId}>{(overview.employees ?? []).map((e) => <option key={e.id} value={e.id}>{e.name} · {e.number}</option>)}</Select></FormField></div>
                        <FormField error={action.fieldError('kind')} field="kind" label={t('hr.conduct.kind')}><Select onChange={(e) => setAdd({ ...add, kind: e.target.value as ConductKind, validUntil: '' })} value={add.kind}>{overview.kinds.map((k) => <option key={k} value={k}>{label('hr.conduct.kinds', k)}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('issued_on')} field="issued_on" label={t('hr.conduct.issuedOn')}><DatePicker max={overview.today} onChange={(e) => setAdd({ ...add, issuedOn: e.target.value })} value={add.issuedOn} /></FormField>
                        {add.kind !== 'award' ? <div className="sm:col-span-2"><FormField error={action.fieldError('valid_until')} field="valid_until" hint={t('hr.conduct.validHint', { months: overview.default_months[add.kind] ?? 0 })} label={t('hr.conduct.validUntil')}><DatePicker onChange={(e) => setAdd({ ...add, validUntil: e.target.value })} value={add.validUntil} /></FormField></div> : null}
                        <div className="sm:col-span-2"><FormField error={action.fieldError('reason')} field="reason" label={t('hr.att.reasonShort')}><Input maxLength={500} onChange={(e) => setAdd({ ...add, reason: e.target.value })} value={add.reason} /></FormField></div>
                        <div className="sm:col-span-2"><FormField error={action.fieldError('letter')} field="letter" hint={t('hr.conduct.letterHint')} label={t('hr.conduct.letter')}><Input accept="application/pdf,image/jpeg,image/png" key={fileKey} onChange={(e) => setFile(e.target.files?.[0] ?? null)} type="file" /></FormField></div>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setRevoke(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={revoke?.reason.trim() === ''} loading={action.busy} onClick={() => void doRevoke()} type="button">{t('hr.conduct.revoke')}</Button></>}
                onClose={() => setRevoke(null)}
                open={revoke !== null}
                title={t('hr.conduct.revoke')}
            >
                {revoke !== null && (
                    <div className="grid gap-3">
                        {failure !== null ? failure : null}
                        <p className="text-sm text-muted-foreground">{t('hr.conduct.revokeHint')}</p>
                        <FormField error={action.fieldError('reason')} field="reason" label={t('hr.att.reasonShort')}><Input maxLength={200} onChange={(e) => setRevoke({ ...revoke, reason: e.target.value })} value={revoke.reason} /></FormField>
                    </div>
                )}
            </Dialog>
        </HrShell>
    );
}
