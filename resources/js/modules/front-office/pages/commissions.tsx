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
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Partner = { id: string; kind: string; key: string; name: string; rate_bp: number; is_active: boolean; lock_version: number; owed_minor: number };
type Accrual = { id: string; partner_id: string; partner_name: string; reservation_number: string; stay_out_on: string; base_minor: number; rate_bp: number; commission_minor: number; status: string; void_reason: string | null };
type Overview = { currency: string; today: string; methods: string[]; partners: Partner[]; companies: { id: string; code: string; name: string; kind: string }[]; accruals: Accrual[]; filter: { partner: string | null; status: string | null }; may_manage: boolean };

const TONE: Record<string, StatusTone> = { accrued: 'pending', paid: 'success', void: 'neutral' };
const percent = (bp: number) => String(bp / 100);

/** Commissions owed to travel agents and booking channels: the rates, what was accrued at check-out, the payments. */
export default function CommissionsPage({ overview }: { overview: Overview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [add, setAdd] = useState<{ kind: string; key: string; rate: string } | null>(null);
    const [edit, setEdit] = useState<{ partner: Partner; rate: string; active: boolean } | null>(null);
    const [pay, setPay] = useState<{ partner: Partner; method: string; reference: string; paidOn: string; key: string } | null>(null);
    const [void_, setVoid] = useState<{ accrual: Accrual; reason: string } | null>(null);
    const reload = ['overview'];
    const money = (minor: number) => format.money(minor, overview.currency);
    const toBp = (text: string) => Math.round(Number(text.replace(',', '.')) * 100);
    const query = new URLSearchParams({ ...(overview.filter.partner ? { partner: overview.filter.partner } : {}), ...(overview.filter.status ? { status: overview.filter.status } : {}) }).toString();

    async function savePartner() {
        if (add === null) return;
        const done = await action.run('/front-office/commissions/partners', { body: { kind: add.kind, key: add.key.trim(), rate_bp: toBp(add.rate) }, reload });
        if (done !== null) setAdd(null);
    }

    async function changePartner() {
        if (edit === null) return;
        const done = await action.run(`/front-office/commissions/partners/${edit.partner.id}`, { body: { rate_bp: toBp(edit.rate), is_active: edit.active, lock_version: edit.partner.lock_version }, reload });
        if (done !== null) setEdit(null);
    }

    async function payNow() {
        if (pay === null) return;
        const done = await action.run(`/front-office/commissions/partners/${pay.partner.id}/pay`, { idempotencyKey: pay.key, body: { method: pay.method, reference: pay.reference.trim() === '' ? null : pay.reference.trim(), paid_on: pay.paidOn }, reload });
        if (done !== null) setPay(null);
    }

    async function doVoid() {
        if (void_ === null) return;
        const done = await action.run(`/front-office/commissions/accruals/${void_.accrual.id}/void`, { body: { reason: void_.reason.trim() }, reload });
        if (done !== null) setVoid(null);
    }

    const partnerColumns: DataGridColumn<Partner>[] = [
        { id: 'name', label: t('fo.com.col.partner'), value: (p) => p.name, rowHeader: true, cell: (p) => <span>{p.name}<span className="block text-xs text-muted-foreground">{t(`fo.com.kind.${p.kind}` as 'fo.com.kind.company')}{p.kind === 'channel' ? ` · ${p.key}` : ''}</span></span> },
        { id: 'rate', label: t('fo.com.col.rate'), align: 'right', value: (p) => p.rate_bp, cell: (p) => `${percent(p.rate_bp)}%` },
        { id: 'owed', label: t('fo.com.col.owed'), align: 'right', value: (p) => p.owed_minor, cell: (p) => money(p.owed_minor) },
        { id: 'state', label: t('fo.com.col.state'), value: (p) => (p.is_active ? 'on' : 'off'), cell: (p) => <StatusBadge label={t(p.is_active ? 'fo.com.on' : 'fo.com.off')} tone={p.is_active ? 'success' : 'neutral'} /> },
        {
            id: 'actions', label: '', value: () => '', sortable: false,
            cell: (p) => (overview.may_manage
                ? <span className="flex flex-wrap gap-2">
                    <Button disabled={p.owed_minor === 0} onClick={() => { action.clear(); setPay({ partner: p, method: 'transfer', reference: '', paidOn: overview.today, key: newIdempotencyKey() }); }} size="sm" type="button">{t('fo.com.payAll')}</Button>
                    <Button onClick={() => { action.clear(); setEdit({ partner: p, rate: percent(p.rate_bp), active: p.is_active }); }} size="sm" type="button" variant="outline">{t('fo.gc.edit')}</Button>
                </span>
                : null),
        },
    ];

    const accrualColumns: DataGridColumn<Accrual>[] = [
        { id: 'out', label: t('fo.com.col.checkout'), value: (a) => a.stay_out_on, cell: (a) => format.date(a.stay_out_on) },
        { id: 'partner', label: t('fo.com.col.partner'), value: (a) => a.partner_name, filter: 'select', filterLabel: (v) => v },
        { id: 'reservation', label: t('fo.lock.col.reservation'), value: (a) => a.reservation_number, rowHeader: true },
        { id: 'base', label: t('fo.com.col.base'), align: 'right', value: (a) => a.base_minor, cell: (a) => money(a.base_minor) },
        { id: 'rate', label: t('fo.com.col.rate'), align: 'right', value: (a) => a.rate_bp, cell: (a) => `${percent(a.rate_bp)}%` },
        { id: 'commission', label: t('fo.com.col.commission'), align: 'right', value: (a) => a.commission_minor, cell: (a) => money(a.commission_minor) },
        { id: 'status', label: t('fo.wl.col.status'), value: (a) => a.status, filter: 'select', filterLabel: (v) => t(`fo.com.status.${v}` as 'fo.com.status.accrued'), cell: (a) => <StatusBadge label={t(`fo.com.status.${a.status}` as 'fo.com.status.accrued')} tone={TONE[a.status] ?? 'neutral'} /> },
        { id: 'actions', label: '', value: () => '', sortable: false, cell: (a) => (overview.may_manage && a.status === 'accrued' ? <Button onClick={() => { action.clear(); setVoid({ accrual: a, reason: '' }); }} size="sm" type="button" variant="outline">{t('fo.com.void')}</Button> : (a.void_reason ?? '')) },
    ];

    return (
        <FrontOfficeShell description={t('fo.com.description')} title={t('fo.com.title')} wide>
            {action.error !== null && add === null && edit === null && pay === null && void_ === null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            <div className="flex flex-wrap items-end gap-3 print:hidden">
                {overview.may_manage ? <Button onClick={() => { action.clear(); setAdd({ kind: 'company', key: overview.companies[0]?.id ?? '', rate: '10' }); }} type="button">{t('fo.com.addPartner')}</Button> : null}
                <FormField label={t('fo.com.filterPartner')}>
                    <Select onChange={(e) => router.get('/front-office/commissions', { ...(e.target.value ? { partner: e.target.value } : {}), ...(overview.filter.status ? { status: overview.filter.status } : {}) }, { preserveScroll: true })} value={overview.filter.partner ?? ''}>
                        <option value="">{t('fo.com.all')}</option>
                        {overview.partners.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                    </Select>
                </FormField>
                <FormField label={t('fo.wl.col.status')}>
                    <Select onChange={(e) => router.get('/front-office/commissions', { ...(overview.filter.partner ? { partner: overview.filter.partner } : {}), ...(e.target.value ? { status: e.target.value } : {}) }, { preserveScroll: true })} value={overview.filter.status ?? ''}>
                        <option value="">{t('fo.com.all')}</option>
                        {(['accrued', 'paid', 'void'] as const).map((s) => <option key={s} value={s}>{t(`fo.com.status.${s}` as 'fo.com.status.accrued')}</option>)}
                    </Select>
                </FormField>
                <Button asChild variant="outline"><a href={`/front-office/commissions/export${query === '' ? '' : `?${query}`}`}>{t('fo.com.export')}</a></Button>
            </div>

            <h2 className="text-lg font-semibold">{t('fo.com.partners')}</h2>
            <DataGrid caption={t('fo.com.partners')} columns={partnerColumns} empty={<EmptyState title={t('fo.com.noPartners')} />} getRowId={(p) => p.id} id="fo.commission-partners" rows={overview.partners} testId="commission-partners" />
            <h2 className="text-lg font-semibold">{t('fo.com.accruals')}</h2>
            <DataGrid caption={t('fo.com.accruals')} columns={accrualColumns} empty={<EmptyState title={t('fo.com.none')} />} getRowId={(a) => a.id} id="fo.commission-accruals" rows={overview.accruals} testId="commission-accruals" />

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setAdd(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void savePartner()} type="button">{t('fo.com.addPartner')}</Button></>}
                onClose={() => setAdd(null)}
                open={add !== null}
                title={t('fo.com.addPartner')}
            >
                {add !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <FormField error={action.fieldError('kind')} field="kind" label={t('fo.com.kind')}>
                            <Select onChange={(e) => setAdd({ ...add, kind: e.target.value, key: e.target.value === 'company' ? (overview.companies[0]?.id ?? '') : '' })} value={add.kind}><option value="company">{t('fo.com.kind.company')}</option><option value="channel">{t('fo.com.kind.channel')}</option></Select>
                        </FormField>
                        {add.kind === 'company' ? (
                            <FormField error={action.fieldError('key')} field="key" label={t('fo.com.kind.company')}><Select onChange={(e) => setAdd({ ...add, key: e.target.value })} value={add.key}>{overview.companies.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}</Select></FormField>
                        ) : (
                            <FormField error={action.fieldError('key')} field="key" hint={t('fo.com.channelHint')} label={t('fo.com.kind.channel')}><Input maxLength={30} onChange={(e) => setAdd({ ...add, key: e.target.value })} value={add.key} /></FormField>
                        )}
                        <FormField error={action.fieldError('rate_bp')} field="rate_bp" hint={t('fo.com.rateHint')} label={t('fo.com.ratePercent')}><Input inputMode="decimal" maxLength={5} onChange={(e) => setAdd({ ...add, rate: e.target.value })} value={add.rate} /></FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setEdit(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void changePartner()} type="button">{t('fo.lock.save')}</Button></>}
                onClose={() => setEdit(null)}
                open={edit !== null}
                title={edit?.partner.name ?? ''}
            >
                {edit !== null && (
                    <div className="grid gap-3">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <FormField error={action.fieldError('rate_bp')} field="rate_bp" hint={t('fo.com.newRateHint')} label={t('fo.com.ratePercent')}><Input inputMode="decimal" maxLength={5} onChange={(e) => setEdit({ ...edit, rate: e.target.value })} value={edit.rate} /></FormField>
                        <label className="flex items-center gap-2 text-sm"><input checked={edit.active} onChange={(e) => setEdit({ ...edit, active: e.target.checked })} type="checkbox" />{t('fo.com.activeField')}</label>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setPay(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void payNow()} type="button">{t('fo.com.payAll')}</Button></>}
                onClose={() => setPay(null)}
                open={pay !== null}
                title={t('fo.com.payTitle')}
            >
                {pay !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <p className="text-sm sm:col-span-2">{t('fo.com.payBody', { partner: pay.partner.name, amount: money(pay.partner.owed_minor) })}</p>
                        <FormField error={action.fieldError('method')} field="method" label={t('fo.gc.paidBy')}><Select onChange={(e) => setPay({ ...pay, method: e.target.value })} value={pay.method}>{overview.methods.map((m) => <option key={m} value={m}>{t(`fo.com.method.${m}` as 'fo.com.method.cash')}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('paid_on')} field="paid_on" label={t('fo.com.paidOn')}><DatePicker max={overview.today} onChange={(e) => setPay({ ...pay, paidOn: e.target.value })} value={pay.paidOn} /></FormField>
                        {pay.method === 'transfer' ? <div className="sm:col-span-2"><FormField error={action.fieldError('reference')} field="reference" label={t('fo.folio.reference')}><Input maxLength={60} onChange={(e) => setPay({ ...pay, reference: e.target.value })} value={pay.reference} /></FormField></div> : null}
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setVoid(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={void_?.reason.trim() === ''} loading={action.busy} onClick={() => void doVoid()} type="button">{t('fo.com.void')}</Button></>}
                onClose={() => setVoid(null)}
                open={void_ !== null}
                title={t('fo.com.voidTitle')}
            >
                {void_ !== null && (
                    <div className="grid gap-3">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <p className="text-sm">{t('fo.com.voidBody', { reservation: void_.accrual.reservation_number, amount: money(void_.accrual.commission_minor) })}</p>
                        <FormField error={action.fieldError('reason')} field="reason" label={t('fo.lock.reason')}><Input maxLength={200} onChange={(e) => setVoid({ ...void_, reason: e.target.value })} value={void_.reason} /></FormField>
                    </div>
                )}
            </Dialog>
        </FrontOfficeShell>
    );
}
