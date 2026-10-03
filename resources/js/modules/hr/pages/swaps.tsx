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
import { Select } from '@/components/ui/select';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { HrShell } from '@/modules/hr/components/hr-shell';
import type { ShiftSwap, SwapOverview, SwapStatus } from '@/modules/hr/lib/hr';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

const TONE: Record<SwapStatus, StatusTone> = { awaiting_partner: 'pending', awaiting_supervisor: 'pending', approved: 'success', rejected: 'danger', declined: 'danger', cancelled: 'neutral' };

/** Exchanging the shift of a day with a colleague: the colleague agrees, then a supervisor decides. */
export default function SwapsPage({ overview }: { overview: SwapOverview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [ask, setAsk] = useState<{ partnerId: string; date: string; reason: string } | null>(null);
    const [reject, setReject] = useState<{ swap: ShiftSwap; note: string } | null>(null);
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const reload = ['overview'];

    async function send() {
        if (ask === null) return;
        const result = await action.run('/hr/swaps', { idempotencyKey: newIdempotencyKey(), body: { partner_id: ask.partnerId, work_date: ask.date, reason: ask.reason.trim() }, reload });

        if (result !== null) setAsk(null);
    }

    async function doReject() {
        if (reject === null) return;
        const result = await action.run(`/hr/swaps/${reject.swap.id}/reject`, { body: { note: reject.note.trim() }, reload });

        if (result !== null) setReject(null);
    }

    const columns = (decide: boolean): DataGridColumn<ShiftSwap>[] => [
        { id: 'date', label: t('hr.swap.date'), value: (s) => s.date, rowHeader: true, cell: (s) => format.date(s.date) },
        { id: 'who', label: t('hr.swap.who'), value: (s) => s.requester.name, cell: (s) => <span>{s.requester.name} ({s.requester.shift}) ⇄ {s.partner.name} ({s.partner.shift})</span> },
        { id: 'reason', label: t('hr.att.reasonShort'), value: (s) => s.reason, cell: (s) => <span>{s.reason}{s.decision_note !== null ? <span className="block text-xs text-muted-foreground">{s.decision_note}</span> : null}</span> },
        { id: 'status', label: t('hr.col.status'), value: (s) => s.status, filter: 'select', filterLabel: (v) => t(`hr.swap.status.${v}` as MessageKey), cell: (s) => <StatusBadge label={t(`hr.swap.status.${s.status}` as MessageKey)} tone={TONE[s.status]} /> },
        {
            id: 'act', label: '', value: () => '', sortable: false,
            cell: (s) => (
                <span className="flex flex-wrap gap-2">
                    {s.may.respond ? <Button disabled={action.busy} onClick={() => void action.run(`/hr/swaps/${s.id}/accept`, { body: {}, reload })} size="sm" type="button">{t('hr.swap.accept')}</Button> : null}
                    {s.may.respond ? <Button disabled={action.busy} onClick={() => void action.run(`/hr/swaps/${s.id}/decline`, { body: {}, reload })} size="sm" type="button" variant="outline">{t('hr.swap.decline')}</Button> : null}
                    {decide && s.may.decide ? <Button disabled={action.busy} onClick={() => void action.run(`/hr/swaps/${s.id}/approve`, { body: {}, reload })} size="sm" type="button">{t('hr.swap.approve')}</Button> : null}
                    {decide && s.may.decide ? <Button onClick={() => { action.clear(); setReject({ swap: s, note: '' }); }} size="sm" type="button" variant="outline">{t('hr.swap.reject')}</Button> : null}
                    {s.may.cancel ? <Button disabled={action.busy} onClick={() => void action.run(`/hr/swaps/${s.id}/cancel`, { body: {}, reload })} size="sm" type="button" variant="outline">{t('hr.att.cancelRequest')}</Button> : null}
                </span>
            ),
        },
    ];

    return (
        <HrShell actions={overview.linked ? <Button onClick={() => { action.clear(); setAsk({ partnerId: overview.colleagues[0]?.id ?? '', date: '', reason: '' }); }} type="button">{t('hr.swap.ask')}</Button> : undefined} description={t('hr.swap.description')} title={t('hr.swap.title')}>
            {action.error !== null && ask === null && reject === null ? failure : null}
            {!overview.linked && overview.to_decide.length === 0 ? <Alert title={t('hr.att.noEmployee')} tone="warning" /> : null}
            <Tabs defaultValue={overview.to_decide.length > 0 && !overview.linked ? 'decide' : 'mine'}>
                <TabsList aria-label={t('hr.swap.title')}>
                    <TabsTrigger value="mine">{t('hr.swap.mineTab')}</TabsTrigger>
                    <TabsTrigger value="decide">{t('hr.swap.decideTab', { n: overview.to_decide.length })}</TabsTrigger>
                </TabsList>
                <TabsContent value="mine">
                    <DataGrid caption={t('hr.swap.mineTab')} columns={columns(false)} empty={<EmptyState illustration="checklist" title={t('hr.swap.none')} />} getRowId={(s) => s.id} id="hr.swaps.mine" rows={overview.mine} testId="hr-swaps-mine" />
                </TabsContent>
                <TabsContent value="decide">
                    <DataGrid caption={t('hr.swap.decideTab', { n: overview.to_decide.length })} columns={columns(true)} empty={<EmptyState illustration="checklist" title={t('hr.swap.noneToDecide')} />} getRowId={(s) => s.id} id="hr.swaps.decide" rows={overview.to_decide} testId="hr-swaps-decide" />
                </TabsContent>
            </Tabs>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setAsk(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={ask?.partnerId === '' || ask?.date === '' || ask?.reason.trim() === ''} loading={action.busy} onClick={() => void send()} type="button">{t('hr.swap.askDo')}</Button></>}
                onClose={() => setAsk(null)}
                open={ask !== null}
                title={t('hr.swap.ask')}
            >
                {ask !== null && (
                    <div className="grid gap-3">
                        {failure !== null ? failure : null}
                        <FormField error={action.fieldError('partner_id')} field="partner_id" hint={t('hr.swap.partnerHint')} label={t('hr.swap.partner')}><Select onChange={(e) => setAsk({ ...ask, partnerId: e.target.value })} value={ask.partnerId}>{overview.colleagues.map((c) => <option key={c.id} value={c.id}>{c.name} · {c.number}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('work_date')} field="work_date" hint={t('hr.swap.dateHint')} label={t('hr.swap.date')}><DatePicker min={overview.today} onChange={(e) => setAsk({ ...ask, date: e.target.value })} value={ask.date} /></FormField>
                        <FormField error={action.fieldError('reason')} field="reason" label={t('hr.att.reasonShort')}><Input maxLength={200} onChange={(e) => setAsk({ ...ask, reason: e.target.value })} value={ask.reason} /></FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setReject(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={reject?.note.trim() === ''} loading={action.busy} onClick={() => void doReject()} type="button">{t('hr.swap.reject')}</Button></>}
                onClose={() => setReject(null)}
                open={reject !== null}
                title={t('hr.swap.reject')}
            >
                {reject !== null && (
                    <div className="grid gap-3">
                        {failure !== null ? failure : null}
                        <FormField error={action.fieldError('note')} field="note" label={t('hr.swap.rejectNote')}><Input maxLength={200} onChange={(e) => setReject({ ...reject, note: e.target.value })} value={reject.note} /></FormField>
                    </div>
                )}
            </Dialog>
        </HrShell>
    );
}
