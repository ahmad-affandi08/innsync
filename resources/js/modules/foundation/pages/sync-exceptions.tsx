import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { PropertyShell } from '@/modules/property/components/property-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Row = {
    id: string; operation_type: string; kind: 'conflict' | 'rejected'; reason_code: string; conflict_action: string | null; device: string; actor: string | null; actor_id: string; received_at: string;
    status: 'open' | 'resolved'; resolved_at: string | null; resolved_by: string | null; resolution_note: string | null; lock_version: number;
};
type Overview = { rows: Row[]; open: number; may: { reconcile: boolean } };

/** What a phone or a register recorded offline and the server could not apply: a manager looks at each, does what the business needs in the module, and marks it reconciled with a note. */
export default function SyncExceptionsPage({ overview, status }: { overview: Overview; status: string }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [target, setTarget] = useState<Row | null>(null);
    const [note, setNote] = useState('');
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;

    async function resolve() {
        if (target === null) return;
        const done = await action.run(`/sync/exceptions/${target.id}/resolve`, { body: { lock_version: target.lock_version, note: note.trim() }, reload: ['overview'] });
        if (done !== null) setTarget(null);
    }

    const columns: DataGridColumn<Row>[] = [
        { id: 'when', label: t('sync.col.when'), value: (r) => r.received_at, cell: (r) => format.instant(r.received_at.replace(' ', 'T').slice(0, 19) + 'Z') },
        { id: 'what', label: t('sync.col.what'), value: (r) => r.operation_type, rowHeader: true, cell: (r) => <span>{r.operation_type}<span className="block text-xs text-muted-foreground">{t('sync.device', { device: r.device })}</span></span> },
        { id: 'who', label: t('sync.col.who'), value: (r) => r.actor ?? '', cell: (r) => r.actor ?? '—' },
        { id: 'why', label: t('sync.col.why'), value: (r) => r.reason_code, cell: (r) => <span><StatusBadge label={t(r.kind === 'conflict' ? 'sync.kind.conflict' : 'sync.kind.rejected')} tone={r.kind === 'conflict' ? 'pending' : 'danger'} /><span className="block text-xs text-muted-foreground">{r.reason_code}</span></span> },
        { id: 'state', label: t('sync.col.state'), value: (r) => r.status, cell: (r) => (r.status === 'open' ? <StatusBadge label={t('sync.open')} tone="pending" /> : <span><StatusBadge label={t('sync.resolved')} tone="success" /><span className="block text-xs text-muted-foreground">{r.resolved_by ?? ''}{r.resolution_note !== null ? ` · ${r.resolution_note}` : ''}</span></span>) },
        { id: 'act', label: '', value: () => '', sortable: false, cell: (r) => (r.status === 'open' ? <Button onClick={() => { action.clear(); setNote(''); setTarget(r); }} size="sm" type="button" variant="outline">{t('sync.resolve')}</Button> : null) },
    ];

    return (
        <PropertyShell description={t('sync.description')} title={t('sync.title')}>
            {action.error !== null && target === null ? failure : null}
            {overview.open > 0 ? <Alert title={t('sync.openCount', { count: overview.open })} tone="warning">{t('sync.hint')}</Alert> : null}
            <div className="flex flex-wrap gap-2">
                {(['open', 'resolved', 'all'] as const).map((s) => (
                    <Button asChild key={s} size="sm" variant={status === s ? 'default' : 'outline'}><Link href={`/sync/exceptions?status=${s}`}>{t(`sync.filter.${s}` as 'sync.filter.open')}</Link></Button>
                ))}
            </div>
            <DataGrid caption={t('sync.title')} columns={columns} empty={<EmptyState illustration="checklist" title={t('sync.none')} />} getRowId={(r) => r.id} id="sync.exceptions" rows={overview.rows} testId="sync-exceptions" />

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setTarget(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={note.trim() === ''} loading={action.busy} onClick={() => void resolve()} type="button">{t('sync.resolve')}</Button></>}
                onClose={() => setTarget(null)}
                open={target !== null}
                title={target === null ? '' : t('sync.resolveTitle', { what: target.operation_type })}
            >
                <div className="flex flex-col gap-3">
                    {failure}
                    <p className="text-sm text-muted-foreground">{t('sync.resolveHint')}</p>
                    <FormField error={action.fieldError('note')} label={t('sync.note')}><Input maxLength={500} onChange={(e) => setNote(e.target.value)} value={note} /></FormField>
                </div>
            </Dialog>
        </PropertyShell>
    );
}
