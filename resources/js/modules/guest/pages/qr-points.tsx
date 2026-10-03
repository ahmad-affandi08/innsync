import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { GuestStaffShell } from '@/modules/guest/components/guest-staff-shell';
import type { QrOverview, QrPoint } from '@/modules/guest/lib/guest';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

/** The QR codes of the rooms and tables: make the missing ones, print them, switch one off, or rotate one when it was copied. */
export default function QrPointsPage({ overview }: { overview: QrOverview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [rotate, setRotate] = useState<QrPoint | null>(null);
    const reload = ['overview'];
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;

    const columns: DataGridColumn<QrPoint>[] = [
        { id: 'label', label: t('guest.qr.colLabel'), value: (p) => p.label, rowHeader: true },
        { id: 'kind', label: t('guest.qr.colKind'), value: (p) => p.kind, filter: 'select', filterLabel: (v) => t(`guest.qr.kind.${v}` as 'guest.qr.kind.room'), cell: (p) => t(`guest.qr.kind.${p.kind}` as 'guest.qr.kind.room') },
        { id: 'rotated', label: t('guest.qr.colRotated'), value: (p) => p.rotated_at ?? '', cell: (p) => (p.rotated_at === null ? '—' : format.instant(p.rotated_at)) },
        { id: 'status', label: t('guest.qr.colStatus'), value: (p) => (p.is_active ? 'on' : 'off'), cell: (p) => <StatusBadge label={p.is_active ? t('guest.qr.on') : t('guest.qr.off')} tone={p.is_active ? 'success' : 'neutral'} /> },
        {
            id: 'act', label: '', value: () => '', sortable: false,
            cell: (p) => (
                <span className="flex gap-2">
                    <Button disabled={action.busy} onClick={() => void action.run(`/guest/qr/${p.id}/active`, { body: { active: !p.is_active, lock_version: p.lock_version }, reload })} size="sm" type="button" variant="outline">{p.is_active ? t('guest.qr.switchOff') : t('guest.qr.switchOn')}</Button>
                    <Button disabled={action.busy} onClick={() => { action.clear(); setRotate(p); }} size="sm" type="button" variant="outline">{t('guest.qr.rotate')}</Button>
                </span>
            ),
        },
    ];

    return (
        <GuestStaffShell
            actions={<><Button disabled={action.busy} loading={action.busy} onClick={() => void action.run('/guest/qr', { body: {}, reload })} type="button">{overview.missing > 0 ? t('guest.qr.make', { count: overview.missing }) : t('guest.qr.makeAgain')}</Button><Button asChild variant="outline"><Link href="/guest/qr/print">{t('guest.qr.print')}</Link></Button></>}
            description={t('guest.qr.description')}
            title={t('guest.qr.title')}
            wide
        >
            {action.error !== null && rotate === null ? failure : null}
            {overview.room_outlets.length === 0 ? <Alert title={t('guest.qr.noRoomOutlet')} tone="warning" /> : null}
            <DataGrid caption={t('guest.qr.title')} columns={columns} empty={<EmptyState illustration="checklist" title={t('guest.qr.none')} />} getRowId={(p) => p.id} id="guest.qr" rows={overview.points} testId="guest-qr-points" />
            <p className="text-xs text-muted-foreground">{t('guest.qr.note')}</p>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setRotate(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => { if (rotate !== null) void action.run(`/guest/qr/${rotate.id}/rotate`, { body: { lock_version: rotate.lock_version }, reload }).then((r) => { if (r !== null) setRotate(null); }); }} type="button">{t('guest.qr.rotateConfirm')}</Button></>}
                onClose={() => setRotate(null)}
                open={rotate !== null}
                title={rotate === null ? '' : t('guest.qr.rotateTitle', { label: rotate.label })}
            >
                <div className="flex flex-col gap-3">
                    <p className="text-sm text-muted-foreground">{t('guest.qr.rotateHint')}</p>
                    {failure}
                </div>
            </Dialog>
        </GuestStaffShell>
    );
}
