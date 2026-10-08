import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { MoneyInput } from '@/components/ui/money-input';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';

type Company = {
    id: string; code: string; name: string; kind: 'company' | 'agent'; contact_name: string | null; contact_phone: string | null; contact_email: string | null; tax_id: string | null; billing_instruction: string | null;
    credit_limit_minor: number | null; route_rooms: boolean; route_extras: boolean; is_active: boolean; lock_version: number; used_minor: number; over_limit: boolean;
};
type Folio = { folio_id: string; folio_number: string; reservation_id: string; reservation_number: string; guest: string; balance_minor: number; since: string };
type Account = Company & { folios: Folio[] };
type Props = { accounts: { companies: Account[]; total_minor: number }; currency: string; overview: { companies: Company[]; may: { manage: boolean } } };
type Form = { id: string | null; code: string; name: string; kind: string; contact_name: string; contact_phone: string; contact_email: string; tax_id: string; billing_instruction: string; limit: string; route_rooms: boolean; route_extras: boolean; active: boolean; lock: number; reason: string };
const blank = (): Form => ({ id: null, code: '', name: '', kind: 'company', contact_name: '', contact_phone: '', contact_email: '', tax_id: '', billing_instruction: '', limit: '', route_rooms: true, route_extras: false, active: true, lock: 0, reason: '' });

/** Companies and agents the hotel bills, the charges that go to them and what they owe (FR-FO-035). */
export default function CompaniesPage({ accounts, currency, overview }: Props) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<Form | null>(null);
    const [invalid, setInvalid] = useState(false);
    const money = (v: number) => format.money(v, currency);

    function edit(c: Company) {
        action.clear();
        setForm({ id: c.id, code: c.code, name: c.name, kind: c.kind, contact_name: c.contact_name ?? '', contact_phone: c.contact_phone ?? '', contact_email: c.contact_email ?? '', tax_id: c.tax_id ?? '', billing_instruction: c.billing_instruction ?? '', limit: c.credit_limit_minor === null ? '' : String(c.credit_limit_minor / 100), route_rooms: c.route_rooms, route_extras: c.route_extras, active: c.is_active, lock: c.lock_version, reason: '' });
    }

    async function save() {
        if (form === null) return;
        const limit = form.limit.trim() === '' ? null : parseMajorToMinor(form.limit, currency);
        setInvalid(form.limit.trim() !== '' && limit === null);
        if (form.limit.trim() !== '' && limit === null) return;
        const body = {
            code: form.code, name: form.name, kind: form.kind, contact_name: form.contact_name || null, contact_phone: form.contact_phone || null, contact_email: form.contact_email || null, tax_id: form.tax_id || null,
            billing_instruction: form.billing_instruction || null, credit_limit_minor: limit, route_rooms: form.route_rooms, route_extras: form.route_extras,
            ...(form.id === null ? {} : { is_active: form.active, lock_version: form.lock, reason: form.reason.trim() }),
        };
        const done = await action.run(form.id === null ? '/front-office/companies' : `/front-office/companies/${form.id}`, { body, reload: ['overview', 'accounts'] });
        if (done !== null) setForm(null);
    }

    return (
        <FrontOfficeShell description={t('fo.company.description')} title={t('fo.company.title')} wide>
            {action.error !== null && form === null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}

            <section aria-labelledby="owed-h" className="flex flex-col gap-2">
                <h2 className="text-lg font-semibold" id="owed-h">{t('fo.company.owed', { amount: money(accounts.total_minor) })}</h2>
                {accounts.companies.length === 0 ? <p className="text-sm text-muted-foreground">{t('fo.company.nothingOwed')}</p> : (
                    <ul className="flex flex-col gap-3" data-testid="company-accounts">
                        {accounts.companies.map((c) => (
                            <li className="flex flex-col gap-1 border border-border p-3 text-sm" data-testid={`account-${c.code}`} key={c.id}>
                                <p className="font-medium">{c.name} · {money(c.used_minor)}{c.credit_limit_minor !== null ? ` / ${money(c.credit_limit_minor)}` : ''} {c.over_limit ? <StatusBadge label={t('fo.company.overLimit')} tone="danger" /> : null}</p>
                                <ul className="text-xs text-muted-foreground">{c.folios.map((f) => <li key={f.folio_id}><Link className="underline" href={`/front-office/folios/${f.folio_id}`}>{f.folio_number}</Link> · {f.guest} · {f.reservation_number} · {money(f.balance_minor)} · {t('fo.company.since', { date: format.date(f.since) })}</li>)}</ul>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section aria-labelledby="profiles-h" className="flex flex-col gap-2">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-lg font-semibold" id="profiles-h">{t('fo.company.profiles')}</h2>
                    {overview.may.manage ? <Button onClick={() => { action.clear(); setForm(blank()); }} size="sm" type="button">{t('fo.company.add')}</Button> : null}
                </div>
                {overview.companies.length === 0 ? <EmptyState title={t('fo.company.empty')} /> : (
                    <ul className="divide-y divide-border border-y border-border text-sm" data-testid="companies">
                        {overview.companies.map((c) => (
                            <li className="flex flex-wrap items-center justify-between gap-2 py-2" key={c.id}>
                                <span><span className="font-medium">{c.code}</span> · {c.name} · {t(`fo.company.kind.${c.kind}` as 'fo.company.kind.company')}{c.credit_limit_minor !== null ? ` · ${t('fo.company.limit', { amount: money(c.credit_limit_minor) })}` : ''}</span>
                                <span className="flex items-center gap-2">
                                    {c.over_limit ? <StatusBadge label={t('fo.company.overLimit')} tone="danger" /> : null}
                                    {!c.is_active ? <StatusBadge label={t('fo.company.inactive')} tone="neutral" /> : null}
                                    {overview.may.manage ? <Button onClick={() => edit(c)} size="sm" type="button" variant="outline">{t('property.action.edit')}</Button> : null}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {form !== null && (
                <section aria-labelledby="co-form-h" className="flex max-w-2xl flex-col gap-3 border border-border p-4" data-testid="company-form">
                    <h2 className="text-lg font-semibold" id="co-form-h">{form.id === null ? t('fo.company.add') : t('property.action.edit')}</h2>
                    {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                    <div className="grid gap-3 sm:grid-cols-2">
                        {form.id === null ? <FormField field="code" error={action.fieldError('code')} label={t('fo.company.code')}><Input maxLength={20} onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })} value={form.code} /></FormField> : null}
                        <FormField field="name" error={action.fieldError('name')} label={t('fo.company.name')}><Input maxLength={120} onChange={(e) => setForm({ ...form, name: e.target.value })} value={form.name} /></FormField>
                        <FormField field="kind" error={action.fieldError('kind')} label={t('fo.company.kindLabel')}><Select onChange={(e) => setForm({ ...form, kind: e.target.value })} value={form.kind}><option value="company">{t('fo.company.kind.company')}</option><option value="agent">{t('fo.company.kind.agent')}</option></Select></FormField>
                        <FormField field="tax_id" error={action.fieldError('tax_id')} label={t('fo.company.taxId')}><Input maxLength={30} onChange={(e) => setForm({ ...form, tax_id: e.target.value })} value={form.tax_id} /></FormField>
                        <FormField field="contact_name" error={action.fieldError('contact_name')} label={t('fo.company.contactName')}><Input maxLength={100} onChange={(e) => setForm({ ...form, contact_name: e.target.value })} value={form.contact_name} /></FormField>
                        <FormField field="contact_phone" error={action.fieldError('contact_phone')} label={t('fo.company.contactPhone')}><Input maxLength={30} onChange={(e) => setForm({ ...form, contact_phone: e.target.value })} value={form.contact_phone} /></FormField>
                        <FormField field="contact_email" error={action.fieldError('contact_email')} label={t('fo.company.contactEmail')}><Input maxLength={190} onChange={(e) => setForm({ ...form, contact_email: e.target.value })} value={form.contact_email} /></FormField>
                        <FormField field="credit_limit_minor" error={invalid ? t('fo.folio.invalidAmount') : action.fieldError('credit_limit_minor')} hint={t('fo.company.limitHint')} label={t('fo.company.limitLabel')}><MoneyInput onChange={(e) => setForm({ ...form, limit: e.target.value })} value={form.limit} /></FormField>
                    </div>
                    <FormField field="billing_instruction" error={action.fieldError('billing_instruction')} label={t('fo.company.instruction')}><Textarea maxLength={500} onChange={(e) => setForm({ ...form, billing_instruction: e.target.value })} rows={3} value={form.billing_instruction} /></FormField>
                    <fieldset className="flex flex-col gap-1 text-sm">
                        <legend className="font-medium">{t('fo.company.routing')}</legend>
                        <label className="flex items-center gap-2"><input checked={form.route_rooms} onChange={(e) => setForm({ ...form, route_rooms: e.target.checked })} type="checkbox" />{t('fo.company.routeRooms')}</label>
                        <label className="flex items-center gap-2"><input checked={form.route_extras} onChange={(e) => setForm({ ...form, route_extras: e.target.checked })} type="checkbox" />{t('fo.company.routeExtras')}</label>
                    </fieldset>
                    {form.id !== null ? (
                        <>
                            <label className="flex items-center gap-2 text-sm"><input checked={form.active} onChange={(e) => setForm({ ...form, active: e.target.checked })} type="checkbox" />{t('fo.company.active')}</label>
                            <FormField field="reason" error={action.fieldError('reason')} label={t('fo.folio.reason')}><Input maxLength={300} onChange={(e) => setForm({ ...form, reason: e.target.value })} value={form.reason} /></FormField>
                        </>
                    ) : null}
                    <Alert title={t('fo.company.routingNote')} tone="info" />
                    <div className="flex gap-2"><Button loading={action.busy} onClick={() => void save()} type="button">{t('property.action.save')}</Button><Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button></div>
                </section>
            )}
        </FrontOfficeShell>
    );
}
