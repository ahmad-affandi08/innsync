import { useState, type FormEvent, type ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import type { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

export type DamageReport = { id: string; number: string; title: string; category: string; state: 'open' | 'in_progress' | 'done' | 'cancelled'; room: string | null; area: string | null; reported_at: string };
type ServerAction = ReturnType<typeof useServerAction>;

export type DamageOverview = { reports: DamageReport[]; rooms: { id: string; number: string }[]; categories: string[] };

const TONE: Record<DamageReport['state'], StatusTone> = { open: 'warning', in_progress: 'info', done: 'success', cancelled: 'neutral' };

/**
 * A fault found by another department (housekeeping rooms, kitchen equipment): where, what, how urgent and a photo. It becomes a work order of Maintenance at once, and the list follows the
 * work orders this person reported.
 */
export type DamageForm = { roomId: string; area: string; category: string; title: string; detail: string; urgent: boolean };

export function DamageReportPanel({ action, onSend, overview, roomField }: { action: ServerAction; onSend: (form: DamageForm, photo: File | null) => Promise<{ report: { number: string } } | null>; overview: DamageOverview; roomField?: (value: string, onChange: (value: string) => void) => ReactNode }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const [form, setForm] = useState({ roomId: '', area: '', category: 'other', title: '', detail: '', urgent: false });
    const [file, setFile] = useState<File | null>(null);
    const [pickerKey, setPickerKey] = useState(0);
    const [open, setOpen] = useState(false);
    const [done, setDone] = useState<string | null>(null);
    const withRooms = roomField !== undefined;

    async function submit(event: FormEvent) {
        event.preventDefault();
        const result = await onSend(form, file);

        if (result !== null) {
            setDone(result.report.number);
            setForm({ ...form, title: '', detail: '', urgent: false });
            setFile(null);
            setPickerKey((k) => k + 1);
            setOpen(false);
        }
    }

    const columns: DataGridColumn<DamageReport>[] = [
        { id: 'number', label: t('mtc.damage.number'), value: (r) => r.number, rowHeader: true },
        { id: 'title', label: t('mtc.damage.what'), value: (r) => r.title, cell: (r) => <span>{r.title}<span className="block text-xs text-muted-foreground">{t(`mtc.category.${r.category}` as MessageKey)}</span></span> },
        { id: 'where', label: t('mtc.damage.where'), value: (r) => r.room ?? r.area ?? '', cell: (r) => (r.room !== null ? t('mtc.damage.room', { number: r.room }) : r.area) },
        { id: 'state', label: t('mtc.damage.state'), value: (r) => r.state, filter: 'select', filterLabel: (v) => t(`mtc.damage.state.${v}` as MessageKey), cell: (r) => <StatusBadge label={t(`mtc.damage.state.${r.state}` as MessageKey)} tone={TONE[r.state]} /> },
        { id: 'at', label: t('mtc.damage.at'), value: (r) => r.reported_at, cell: (r) => format.instant(r.reported_at) },
    ];

    return (
        <div className="flex flex-col gap-4">
            {action.error !== null && !open ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="font-semibold">{t('mtc.damage.mine')}</h2>
                <Button onClick={() => { action.clear(); setDone(null); setOpen(true); }} size="sm" type="button">{t('mtc.damage.new')}</Button>
            </div>
            {done !== null ? <p aria-live="polite" className="text-sm text-success" data-testid="damage-done">{t('mtc.damage.sent', { number: done })}</p> : null}
            <DataGrid caption={t('mtc.damage.mine')} columns={columns} empty={<EmptyState illustration="checklist" title={t('mtc.damage.none')} />} getRowId={(r) => r.id} id="mtc.damage" rows={overview.reports} testId="damage-reports" />
            <Dialog className="w-[min(42rem,calc(100vw-2rem))]" footer={<><Button disabled={action.busy} onClick={() => setOpen(false)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={form.title.trim() === '' || (form.area.trim() === '' && form.roomId === '')} form="damage-form" loading={action.busy} type="submit">{t('mtc.damage.send')}</Button></>} onClose={() => setOpen(false)} open={open} title={t('mtc.damage.new')}>
                <div className="flex flex-col gap-3" data-testid="damage-form">
                    {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                    <form className="grid gap-3 sm:grid-cols-2" id="damage-form" onSubmit={(e) => void submit(e)}>
                        {roomField !== undefined ? roomField(form.roomId, (roomId) => setForm({ ...form, roomId })) : null}
                        <FormField error={action.fieldError('area')} field="area" hint={withRooms ? t('mtc.damage.areaHint') : undefined} label={t('mtc.damage.areaField')}><Input maxLength={80} onChange={(e) => setForm({ ...form, area: e.target.value })} value={form.area} /></FormField>
                        <FormField error={action.fieldError('category')} field="category" label={t('mtc.damage.category')}><Select onChange={(e) => setForm({ ...form, category: e.target.value })} value={form.category}>{overview.categories.map((c) => <option key={c} value={c}>{t(`mtc.category.${c}` as MessageKey)}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('title')} field="title" label={t('mtc.damage.what')}><Input maxLength={80} onChange={(e) => setForm({ ...form, title: e.target.value })} value={form.title} /></FormField>
                        <div className="sm:col-span-2"><FormField error={action.fieldError('detail')} field="detail" label={t('mtc.damage.detail')}><Input maxLength={500} onChange={(e) => setForm({ ...form, detail: e.target.value })} value={form.detail} /></FormField></div>
                        <FormField error={action.fieldError('photo')} field="photo" label={t('mtc.damage.photo')}><Input accept="image/jpeg,image/png" capture="environment" key={pickerKey} onChange={(e) => setFile(e.target.files?.[0] ?? null)} type="file" /></FormField>
                        <label className="flex items-center gap-2 self-end text-sm"><input checked={form.urgent} onChange={(e) => setForm({ ...form, urgent: e.target.checked })} type="checkbox" />{t('mtc.damage.urgent')}</label>
                    </form>
                </div>
            </Dialog>
        </div>
    );
}
