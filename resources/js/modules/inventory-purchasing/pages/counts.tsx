import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

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
import { InventoryShell } from '@/modules/inventory-purchasing/components/inventory-shell';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

type Count = {
    id: string; number: string; kind: string; status: string; location: { id: string; code: string; name: string }; business_date: string; scheduled_for: string | null;
    started_by_name: string | null; line_count: number; counted_count: number | null; variance_count: number | null;
};
type Overview = { counts: Count[]; locations: { id: string; code: string; name: string }[]; categories: { id: string; code: string; name: string }[]; kinds: string[]; may: { manage: boolean; approve: boolean } };

const tone: Record<string, StatusTone> = { counting: 'info', submitted: 'pending', approved: 'success', cancelled: 'neutral' };

export default function CountsPage({ overview, status }: { overview: Overview; status: string }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<{ location_id: string; kind: string; category_id: string; scheduled_for: string; note: string } | null>(null);
    const label = (s: string) => t(`inv.cnt.status.${s}` as MessageKey);
    const intent = useMemo(() => newIdempotencyKey(), [JSON.stringify(form)]);

    function openNew() {
        action.clear();
        setForm({ location_id: overview.locations[0]?.id ?? '', kind: 'spot', category_id: '', scheduled_for: '', note: '' });
    }

    async function start() {
        if (form === null) return;
        const done = await action.run<{ count: { id: string } }>('/inventory/counts', {
            body: { location_id: form.location_id, kind: form.kind, category_id: form.category_id || null, scheduled_for: form.kind === 'scheduled' && form.scheduled_for ? form.scheduled_for : null, note: form.note || null },
            idempotencyKey: intent,
        });
        if (done !== null) router.visit(`/inventory/counts/${done.count.id}`);
    }

    const columns: DataGridColumn<Count>[] = [
        { id: 'number', label: t('inv.col.number'), value: (c) => c.number, rowHeader: true },
        { id: 'location', label: t('inv.col.location'), value: (c) => c.location.code, searchText: (c) => `${c.location.code} ${c.location.name}`, filter: 'select', cell: (c) => c.location.name },
        { id: 'kind', label: t('inv.cnt.kind'), value: (c) => c.kind, filter: 'select', filterLabel: (v) => t(`inv.cnt.kind.${v}` as MessageKey), cell: (c) => t(`inv.cnt.kind.${c.kind}` as MessageKey) },
        { id: 'date', label: t('inv.col.date'), value: (c) => c.scheduled_for ?? c.business_date, cell: (c) => format.date(c.scheduled_for ?? c.business_date) },
        { id: 'progress', label: t('inv.col.progress'), align: 'right', value: (c) => c.counted_count ?? 0, cell: (c) => `${c.counted_count ?? 0} / ${c.line_count}` },
        { id: 'differences', label: t('inv.col.differences'), align: 'right', value: (c) => c.variance_count ?? 0, cell: (c) => (c.status === 'counting' ? '—' : String(c.variance_count ?? 0)) },
        { id: 'startedBy', label: t('inv.col.startedBy'), value: (c) => c.started_by_name ?? '', hidden: true },
        { id: 'state', label: t('inv.col.status'), value: (c) => c.status, filter: 'select', filterLabel: label, cell: (c) => <StatusBadge label={label(c.status)} tone={tone[c.status] ?? 'neutral'} /> },
        { id: 'actions', label: t('inv.col.actions'), cell: (c) => <Button onClick={() => router.visit(`/inventory/counts/${c.id}`)} size="sm" type="button" variant="outline">{t('inv.cnt.open')}</Button> },
    ];

    return (
        <InventoryShell actions={overview.may.manage ? <Button onClick={openNew} type="button">{t('inv.cnt.new')}</Button> : undefined} description={t('inv.cnt.description')} title={t('inv.cnt.title')} wide>
            <div className="max-w-xs">
                <Select aria-label={t('inv.col.status')} onChange={(e) => router.get('/inventory/counts', e.target.value ? { status: e.target.value } : {}, { preserveScroll: true })} searchable={false} value={status}>
                    <option value="">{t('inv.cnt.allStatuses')}</option>
                    {['counting', 'submitted', 'approved', 'cancelled'].map((s) => <option key={s} value={s}>{label(s)}</option>)}
                </Select>
            </div>

            <DataGrid caption={t('inv.cnt.title')} columns={columns} empty={<EmptyState title={t('inv.cnt.empty')} />} getRowId={(c) => c.id} id="inv.counts" rows={overview.counts} testId="counts" />

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void start()} type="button">{t('inv.cnt.new')}</Button>
                </>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={t('inv.cnt.new')}
            >
                {form !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <p className="text-sm text-muted-foreground sm:col-span-2">{t('inv.cnt.hint')}</p>
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <FormField error={action.fieldError('location_id')} field="location_id" label={t('inv.col.location')}>
                            <Select onChange={(e) => setForm({ ...form, location_id: e.target.value })} value={form.location_id}>
                                {overview.locations.map((l) => <option key={l.id} value={l.id}>{l.code} · {l.name}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={action.fieldError('kind')} field="kind" label={t('inv.cnt.kind')}>
                            <Select onChange={(e) => setForm({ ...form, kind: e.target.value })} searchable={false} value={form.kind}>
                                {overview.kinds.map((k) => <option key={k} value={k}>{t(`inv.cnt.kind.${k}` as MessageKey)}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={action.fieldError('category_id')} field="category_id" label={t('inv.cnt.category')}>
                            <Select onChange={(e) => setForm({ ...form, category_id: e.target.value })} value={form.category_id}>
                                <option value="">{t('inv.cnt.allCategories')}</option>
                                {overview.categories.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}
                            </Select>
                        </FormField>
                        {form.kind === 'scheduled' ? (
                            <FormField error={action.fieldError('scheduled_for')} field="scheduled_for" label={t('inv.cnt.scheduledFor')}>
                                <DatePicker onChange={(e) => setForm({ ...form, scheduled_for: e.target.value })} value={form.scheduled_for} />
                            </FormField>
                        ) : null}
                        <div className="sm:col-span-2">
                            <FormField error={action.fieldError('note')} field="note" label={t('inv.opening.note')}>
                                <Input maxLength={200} onChange={(e) => setForm({ ...form, note: e.target.value })} value={form.note} />
                            </FormField>
                        </div>
                    </div>
                )}
            </Dialog>
        </InventoryShell>
    );
}
