import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { blankRequest, RequestFields, requestLinesBody, type RequestFormState } from '@/modules/inventory-purchasing/components/request-form';
import { InventoryShell } from '@/modules/inventory-purchasing/components/inventory-shell';
import { REQUEST_STATUSES, REQUEST_TONE, URGENCY_TONE, type ItemChoice } from '@/modules/inventory-purchasing/lib/purchasing';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

type Request = {
    id: string; number: string; department: string; urgency: string; reason: string; needed_by: string; status: string; total_minor: number;
    requested_by_name: string | null; line_count: number; ordered_count: number;
};
type Overview = { currency: string; requests: Request[]; departments: string[]; urgencies: string[]; items: ItemChoice[]; may: { create: boolean } };

export default function RequestsPage({ overview, status }: { overview: Overview; status: string }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<RequestFormState | null>(null);
    const [badCosts, setBadCosts] = useState<string[]>([]);
    const label = (s: string) => t(`inv.req.status.${s}` as MessageKey);
    const money = (minor: number) => format.money(minor, overview.currency);

    function openNew() {
        action.clear();
        setBadCosts([]);
        setForm(blankRequest(overview.departments));
    }

    async function create() {
        if (form === null) return;
        const { lines, bad } = requestLinesBody(form, overview.currency);

        setBadCosts(bad);
        if (bad.length > 0) return;
        const done = await action.run<{ request: { id: string } }>('/inventory/requests', {
            body: { department: form.department, urgency: form.urgency, reason: form.reason, needed_by: form.needed_by, lines },
        });
        if (done !== null) router.visit(`/inventory/requests/${done.request.id}`);
    }

    const columns: DataGridColumn<Request>[] = [
        { id: 'number', label: t('inv.col.number'), value: (r) => r.number, rowHeader: true },
        { id: 'department', label: t('inv.col.department'), value: (r) => r.department, filter: 'select', filterLabel: (v) => t(`inv.dept.${v}` as MessageKey), cell: (r) => t(`inv.dept.${r.department}` as MessageKey) },
        {
            id: 'urgency', label: t('inv.req.urgency'), value: (r) => r.urgency, filter: 'select', filterLabel: (v) => t(`inv.req.urgency.${v}` as MessageKey),
            cell: (r) => <StatusBadge label={t(`inv.req.urgency.${r.urgency}` as MessageKey)} tone={URGENCY_TONE[r.urgency] ?? 'neutral'} />,
        },
        { id: 'reason', label: t('inv.req.reason'), value: (r) => r.reason, hidden: true },
        { id: 'neededBy', label: t('inv.req.neededBy'), value: (r) => r.needed_by, cell: (r) => format.date(r.needed_by) },
        { id: 'total', label: t('inv.req.total'), align: 'right', value: (r) => r.total_minor, cell: (r) => money(r.total_minor) },
        { id: 'requestedBy', label: t('inv.req.requestedBy'), value: (r) => r.requested_by_name ?? '', cell: (r) => r.requested_by_name ?? '—' },
        { id: 'lines', label: t('inv.col.lines'), align: 'right', value: (r) => r.line_count, cell: (r) => (r.ordered_count > 0 ? t('inv.req.linesOrdered', { ordered: r.ordered_count, count: r.line_count }) : String(r.line_count)) },
        { id: 'state', label: t('inv.col.status'), value: (r) => r.status, filter: 'select', filterLabel: label, cell: (r) => <StatusBadge label={label(r.status)} tone={REQUEST_TONE[r.status] ?? 'neutral'} /> },
        { id: 'actions', label: t('inv.col.actions'), cell: (r) => <Button onClick={() => router.visit(`/inventory/requests/${r.id}`)} size="sm" type="button" variant="outline">{t('inv.req.open')}</Button> },
    ];

    return (
        <InventoryShell actions={overview.may.create ? <Button onClick={openNew} type="button">{t('inv.req.new')}</Button> : undefined} description={t('inv.req.description')} title={t('inv.req.title')} wide>
            <div className="max-w-xs">
                <Select aria-label={t('inv.col.status')} onChange={(e) => router.get('/inventory/requests', e.target.value ? { status: e.target.value } : {}, { preserveScroll: true })} searchable={false} value={status}>
                    <option value="">{t('inv.req.allStatuses')}</option>
                    {REQUEST_STATUSES.map((s) => <option key={s} value={s}>{label(s)}</option>)}
                </Select>
            </div>

            <DataGrid caption={t('inv.req.title')} columns={columns} empty={<EmptyState title={t('inv.req.empty')} />} getRowId={(r) => r.id} id="inv.requests" rows={overview.requests} testId="requests" />

            <Dialog
                className="w-[min(64rem,calc(100vw-2rem))]"
                footer={<>
                    <Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void create()} type="button">{t('inv.req.save')}</Button>
                </>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={t('inv.req.new')}
            >
                {form !== null && (
                    <div className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">{t('inv.req.hint')}</p>
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <RequestFields badCosts={badCosts} currency={overview.currency} departments={overview.departments} fieldError={action.fieldError} form={form} items={overview.items} onChange={setForm} urgencies={overview.urgencies} />
                    </div>
                )}
            </Dialog>
        </InventoryShell>
    );
}
