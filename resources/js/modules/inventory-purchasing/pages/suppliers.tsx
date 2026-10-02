import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { InventoryShell } from '@/modules/inventory-purchasing/components/inventory-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Supplier = {
    id: string; code: string; name: string; contact_name: string | null; phone: string | null; email: string | null; address: string | null; tax_id: string | null;
    payment_terms_days: number; note: string | null; is_active: boolean; lock_version: number; rating_avg: number | null; rating_count: number;
};
type Overview = { suppliers: Supplier[]; max_terms_days: number; may: { manage: boolean; rate: boolean } };
type Form = { code: string; name: string; contact_name: string; phone: string; email: string; address: string; tax_id: string; payment_terms_days: string; note: string };

const BLANK: Form = { code: '', name: '', contact_name: '', phone: '', email: '', address: '', tax_id: '', payment_terms_days: '30', note: '' };

export default function SuppliersPage({ overview }: { overview: Overview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<Form | null>(null);

    function openNew() {
        action.clear();
        setForm({ ...BLANK });
    }

    async function create() {
        if (form === null) return;
        const done = await action.run<{ supplier: { id: string } }>('/inventory/suppliers', {
            body: {
                code: form.code, name: form.name, contact_name: form.contact_name || null, phone: form.phone || null, email: form.email || null, address: form.address || null,
                tax_id: form.tax_id || null, payment_terms_days: form.payment_terms_days, note: form.note || null,
            },
        });
        if (done !== null) router.visit(`/inventory/suppliers/${done.supplier.id}`);
    }

    const columns: DataGridColumn<Supplier>[] = [
        { id: 'code', label: t('inv.col.code'), value: (s) => s.code, rowHeader: true },
        { id: 'name', label: t('inv.col.name'), value: (s) => s.name },
        { id: 'contact', label: t('inv.sup.col.contact'), value: (s) => s.contact_name ?? '', cell: (s) => s.contact_name ?? '—' },
        { id: 'phone', label: t('inv.sup.col.phone'), value: (s) => s.phone ?? '', cell: (s) => s.phone ?? '—', hidden: true },
        { id: 'terms', label: t('inv.sup.col.terms'), align: 'right', value: (s) => s.payment_terms_days },
        { id: 'taxId', label: t('inv.sup.col.taxId'), value: (s) => s.tax_id ?? '', cell: (s) => s.tax_id ?? '—', hidden: true },
        {
            id: 'rating', label: t('inv.sup.col.rating'), align: 'right', value: (s) => s.rating_avg,
            cell: (s) => (s.rating_avg === null || s.rating_count === 0 ? '—' : `${format.number(s.rating_avg)} / 5 (${format.number(s.rating_count)})`),
        },
        {
            id: 'state', label: t('inv.col.status'), value: (s) => (s.is_active ? 'active' : 'inactive'), filter: 'select', filterLabel: (v) => t(v === 'active' ? 'inv.status.active' : 'inv.status.inactive'),
            cell: (s) => <StatusBadge label={t(s.is_active ? 'inv.status.active' : 'inv.status.inactive')} tone={s.is_active ? 'success' : 'neutral'} />,
        },
        { id: 'actions', label: t('inv.col.actions'), cell: (s) => <Button onClick={() => router.visit(`/inventory/suppliers/${s.id}`)} size="sm" type="button" variant="outline">{t('inv.sup.open')}</Button> },
    ];

    return (
        <InventoryShell actions={overview.may.manage ? <Button onClick={openNew} type="button">{t('inv.sup.new')}</Button> : undefined} description={t('inv.sup.description')} title={t('inv.sup.title')} wide>
            <DataGrid caption={t('inv.sup.title')} columns={columns} empty={<EmptyState title={t('inv.sup.empty')} />} getRowId={(s) => s.id} id="inv.suppliers" rows={overview.suppliers} testId="suppliers" />

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void create()} type="button">{t('inv.sup.new')}</Button>
                </>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={t('inv.sup.new')}
            >
                {form !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <FormField error={action.fieldError('code')} field="code" hint={t('inv.sup.codeHint')} label={t('inv.col.code')}>
                            <Input maxLength={12} onChange={(e) => setForm({ ...form, code: e.target.value })} value={form.code} />
                        </FormField>
                        <FormField error={action.fieldError('name')} field="name" label={t('inv.col.name')}>
                            <Input maxLength={120} onChange={(e) => setForm({ ...form, name: e.target.value })} value={form.name} />
                        </FormField>
                        <FormField error={action.fieldError('contact_name')} field="contact_name" label={t('inv.sup.f.contactName')}>
                            <Input maxLength={80} onChange={(e) => setForm({ ...form, contact_name: e.target.value })} value={form.contact_name} />
                        </FormField>
                        <FormField error={action.fieldError('phone')} field="phone" label={t('inv.sup.col.phone')}>
                            <Input inputMode="tel" maxLength={30} onChange={(e) => setForm({ ...form, phone: e.target.value })} value={form.phone} />
                        </FormField>
                        <FormField error={action.fieldError('email')} field="email" label={t('inv.sup.f.email')}>
                            <Input inputMode="email" maxLength={120} onChange={(e) => setForm({ ...form, email: e.target.value })} value={form.email} />
                        </FormField>
                        <FormField error={action.fieldError('payment_terms_days')} field="payment_terms_days" required hint={t('inv.sup.termsHint', { max: overview.max_terms_days })} label={t('inv.sup.f.terms')}>
                            <Input inputMode="numeric" onChange={(e) => setForm({ ...form, payment_terms_days: e.target.value })} value={form.payment_terms_days} />
                        </FormField>
                        <FormField error={action.fieldError('tax_id')} field="tax_id" hint={t('inv.sup.taxHint')} label={t('inv.sup.col.taxId')}>
                            <Input inputMode="numeric" maxLength={24} onChange={(e) => setForm({ ...form, tax_id: e.target.value })} value={form.tax_id} />
                        </FormField>
                        <FormField error={action.fieldError('address')} field="address" label={t('inv.sup.f.address')}>
                            <Input maxLength={200} onChange={(e) => setForm({ ...form, address: e.target.value })} value={form.address} />
                        </FormField>
                        <div className="sm:col-span-2">
                            <FormField error={action.fieldError('note')} field="note" label={t('inv.sup.f.note')}>
                                <Input maxLength={200} onChange={(e) => setForm({ ...form, note: e.target.value })} value={form.note} />
                            </FormField>
                        </div>
                    </div>
                )}
            </Dialog>
        </InventoryShell>
    );
}
