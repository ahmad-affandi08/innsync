import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { RateChangePanel, type Rates } from '@/modules/front-office/components/rate-change';
import { statusTone } from '@/modules/front-office/pages/reservations';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Reservation = {
    id: string; number: string; status: string; source: string; guest_name: string; guest_phone: string | null; guest_email: string | null;
    arrival: string; departure: string; nights: number; adults: number; children: number; room_type_id: string; rate_plan_id: string; notes: string | null;
    currency: string; total_minor: number; oversold: boolean; oversell_reason: string | null; status_reason: string | null; lock_version: number;
    price_snapshot: { nights: { date: string; base_minor: number; service_charge_minor: number; tax_minor: number; total_minor: number }[] };
};
type Lookups = { types: { id: string; code: string; name: string }[]; plans: { id: string; code: string; name: string; inclusions: string | null }[] };
type FolioRow = { id: string; number: string; window: number; label: string; status: string; balance_minor: number; currency: string };
type Billing = { company: { id: string; code: string; name: string; billing_instruction: string | null; route_rooms: boolean; route_extras: boolean } | null; folio_id: string | null; options: { id: string; code: string; name: string }[]; may_link: boolean };
type Group = { id: string; number: string; name: string; billing_mode: string; master_folio_id: string | null } | null;
type Kind = 'confirm' | 'cancel' | 'noShow';
type Fee = { kind: string; value: number };
type Policy = {
    guarantee_required: boolean; deposit_required_minor: number; deposit_due_date: string | null; deposit_held_minor: number; deposit_complete: boolean; free_cancellation_until: string;
    cancellation_penalty: Fee; no_show_penalty: Fee; currency: string; may_guarantee: boolean;
};
type Penalty = { amount_minor: number; free: boolean; currency: string; may_waive: boolean };

const PATH = { confirm: 'confirm', cancel: 'cancel', noShow: 'no-show' } as const;

export default function ReservationPage({ billing, folios, group, lookups, policy, rates, reservation: r }: { billing: Billing; folios: FolioRow[]; group: Group; lookups: Lookups; policy: Policy | null; rates: Rates; reservation: Reservation }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [kind, setKind] = useState<Kind | null>(null);
    const [reason, setReason] = useState('');
    const [penalty, setPenalty] = useState<Penalty | null>(null);
    const [waive, setWaive] = useState(false);
    const preview = useServerAction();
    const type = lookups.types.find((x) => x.id === r.room_type_id);
    const plan = lookups.plans.find((x) => x.id === r.rate_plan_id);
    const [newFolio, setNewFolio] = useState<string | null>(null);
    const [companyId, setCompanyId] = useState('');

    async function linkCompany() {
        await action.run(`/front-office/reservations/${r.id}/company`, { body: { company_id: companyId }, reload: ['billing', 'folios'] });
        setCompanyId('');
    }

    async function openFolio() {
        const done = await action.run<{ folio: { id: string } }>(`/front-office/reservations/${r.id}/folios`, { body: { label: (newFolio ?? '').trim() || 'Guest', window: folios.length + 1 } });
        if (done !== null) router.visit(`/front-office/folios/${done.folio.id}`);
    }

    const expected = r.status === 'tentative' || r.status === 'confirmed' || r.status === 'guaranteed';

    function close() {
        action.clear();
        setKind(null);
        setReason('');
        setPenalty(null);
        setWaive(false);
    }

    async function begin(next: Kind) {
        action.clear();
        setPenalty(null);
        setWaive(false);
        setKind(next);
        if (next !== 'confirm') {
            const done = await preview.run<{ penalty: Penalty }>(`/front-office/reservations/${r.id}/penalty?kind=${next === 'cancel' ? 'cancel' : 'no_show'}`, { method: 'GET' });
            if (done !== null) setPenalty(done.penalty);
        }
    }

    async function guarantee() {
        await action.run(`/front-office/reservations/${r.id}/guarantee`, { body: { lock_version: r.lock_version }, reload: ['reservation', 'policy'] });
    }

    const fee = (f: Fee, currency: string) => (f.kind === 'percent' ? t('fo.res.fee.percent', { value: (f.value / 100).toString() }) : f.kind === 'fixed' ? t('fo.res.fee.fixed', { amount: format.money(f.value, currency) }) : t(`fo.res.fee.${f.kind}` as 'fo.res.fee.none'));

    async function apply() {
        if (kind === null) return;
        const done = await action.run(`/front-office/reservations/${r.id}/${PATH[kind]}`, { body: kind === 'confirm' ? { lock_version: r.lock_version } : { lock_version: r.lock_version, reason, waive_penalty: waive }, reload: ['reservation', 'folios'] });
        if (done !== null) close();
    }

    const title = { confirm: t('fo.action.confirm.title'), cancel: t('fo.action.cancel.title'), noShow: t('fo.action.noShow.title') };
    const consequence = { confirm: t('fo.action.confirm.consequence'), cancel: t('fo.action.cancel.consequence'), noShow: t('fo.action.noShow.consequence') };

    return (
        <FrontOfficeShell description={`${format.date(r.arrival, 'long')} – ${format.date(r.departure, 'long')} · ${t('fo.res.nights', { n: r.nights })}`} title={`${r.number} · ${r.guest_name}`}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <Button asChild size="sm" variant="outline"><Link href="/front-office/reservations">{t('fo.res.back')}</Link></Button>
                <StatusBadge label={t(`fo.status.${r.status}` as 'fo.status.tentative')} tone={statusTone[r.status] ?? 'neutral'} />
            </div>

            {kind === null && action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            {r.oversold ? <Alert title={t('fo.res.oversold', { reason: r.oversell_reason ?? '' })} tone="warning" /> : null}
            {r.status_reason !== null ? <Alert title={t('fo.res.statusReason', { reason: r.status_reason })} tone="info" /> : null}

            <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[max-content_1fr]">
                <dt className="text-muted-foreground">{t('fo.res.roomType')}</dt><dd>{type?.code} · {type?.name}</dd>
                <dt className="text-muted-foreground">{t('fo.res.ratePlan')}</dt><dd>{plan?.code} · {plan?.name}{plan?.inclusions ? ` (${plan.inclusions})` : ''}</dd>
                <dt className="text-muted-foreground">{t('fo.res.adults')} / {t('fo.res.children')}</dt><dd>{t('fo.res.guests', { adults: r.adults, children: r.children })}</dd>
                <dt className="text-muted-foreground">{t('fo.res.source')}</dt><dd>{t(`fo.source.${r.source}`as 'fo.source.direct')}</dd>
                <dt className="text-muted-foreground">{t('fo.res.phone')}</dt><dd>{r.guest_phone ?? '—'}</dd>
                <dt className="text-muted-foreground">{t('fo.res.email')}</dt><dd>{r.guest_email ?? '—'}</dd>
                {r.notes !== null && <><dt className="text-muted-foreground">{t('fo.res.notes')}</dt><dd className="break-words">{r.notes}</dd></>}
                <dt className="text-muted-foreground">{t('fo.res.total')}</dt><dd className="font-medium">{format.money(r.total_minor, r.currency)}</dd>
            </dl>
            {r.guest_phone === null && r.guest_email === null ? <p className="text-xs text-muted-foreground">{t('fo.res.contactHidden')}</p> : null}

            <section aria-labelledby="pol-h" className="flex flex-col gap-2">
                <h2 className="text-lg font-semibold" id="pol-h">{t('fo.res.policy')}</h2>
                {policy === null ? <p className="text-sm text-muted-foreground">{t('fo.res.policyNone')}</p> : (
                    <ul className="flex flex-col gap-1 text-sm">
                        {policy.deposit_required_minor > 0 && policy.deposit_due_date !== null ? <li data-testid="deposit-line">{t('fo.res.depositLine', { held: format.money(policy.deposit_held_minor, policy.currency), required: format.money(policy.deposit_required_minor, policy.currency), date: format.date(policy.deposit_due_date) })}</li> : null}
                        <li>{t('fo.res.freeUntil', { date: format.date(policy.free_cancellation_until) })}</li>
                        <li>{t('fo.res.feeCancel', { fee: fee(policy.cancellation_penalty, policy.currency) })}</li>
                        <li>{t('fo.res.feeNoShow', { fee: fee(policy.no_show_penalty, policy.currency) })}</li>
                    </ul>
                )}
                {r.status === 'confirmed' ? (
                    <div className="flex flex-wrap items-center gap-2">
                        <Button disabled={action.busy || policy?.may_guarantee === false} onClick={() => void guarantee()} size="sm" type="button" variant="outline">{t('fo.res.guarantee')}</Button>
                        {policy !== null && policy.deposit_required_minor > 0 && !policy.deposit_complete ? <span className="text-xs text-muted-foreground">{t('fo.res.guaranteeNeedsDeposit')}</span> : null}
                    </div>
                ) : null}
            </section>

            <section aria-labelledby="snap-h" className="flex flex-col gap-2">
                <h2 className="text-lg font-semibold" id="snap-h">{t('fo.res.snapshot')}</h2>
                <p className="text-xs text-muted-foreground">{t('fo.res.snapshotNote')}</p>
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead scope="col">{t('fo.res.arrival')}</TableHead>
                            <TableHead className="text-right" scope="col">{t('rates.quote.base')}</TableHead>
                            <TableHead className="text-right" scope="col">{t('rates.quote.service')}</TableHead>
                            <TableHead className="text-right" scope="col">{t('rates.quote.tax')}</TableHead>
                            <TableHead className="text-right" scope="col">{t('rates.quote.total')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>{r.price_snapshot.nights.map((n) => (
                        <TableRow key={n.date}>
                            <TableCell>{format.date(n.date)}</TableCell>
                            <TableCell className="text-right tabular-nums">{format.money(n.base_minor, r.currency)}</TableCell>
                            <TableCell className="text-right tabular-nums">{format.money(n.service_charge_minor, r.currency)}</TableCell>
                            <TableCell className="text-right tabular-nums">{format.money(n.tax_minor, r.currency)}</TableCell>
                            <TableCell className="text-right tabular-nums">{format.money(n.total_minor, r.currency)}</TableCell>
                        </TableRow>
                    ))}</TableBody>
                </Table>
            </section>

            <RateChangePanel currency={r.currency} rates={rates} reservationId={r.id} />

            {group !== null ? (
                <p className="text-sm" data-testid="group-banner">{t('fo.group.partOf', { number: group.number, name: group.name })} · <Link className="underline-offset-2 hover:underline" href={`/front-office/groups/${group.id}`}>{t('fo.group.openGroup')}</Link></p>
            ) : null}

            {billing.company !== null || billing.may_link ? (
                <section aria-labelledby="bill-h" className="flex flex-col gap-2" data-testid="billing">
                    <h2 className="text-lg font-semibold" id="bill-h">{t('fo.company.billTo')}</h2>
                    {billing.company !== null ? (
                        <div className="flex flex-col gap-1 text-sm">
                            <p className="font-medium">{billing.company.code} · {billing.company.name}</p>
                            <p className="text-muted-foreground">{[billing.company.route_rooms ? t('fo.company.takesRooms') : null, billing.company.route_extras ? t('fo.company.takesExtras') : null].filter((x) => x !== null).join(' · ')}</p>
                            {billing.company.billing_instruction !== null ? <p>{t('fo.company.instructionShown')}: {billing.company.billing_instruction}</p> : null}
                            {billing.folio_id !== null ? <Link className="underline-offset-2 hover:underline" href={`/front-office/folios/${billing.folio_id}`}>{t('fo.company.openFolio')}</Link> : null}
                        </div>
                    ) : (
                        <div className="flex flex-wrap items-end gap-2">
                            <FormField field="company_id" error={action.fieldError('company_id')} hint={t('fo.company.linkNote')} label={t('fo.company.choose')}>
                                <select className="min-h-11 border border-border bg-background px-3 text-sm" onChange={(e) => setCompanyId(e.target.value)} value={companyId}>
                                    <option value="">—</option>
                                    {billing.options.map((o) => <option key={o.id} value={o.id}>{o.code} · {o.name}</option>)}
                                </select>
                            </FormField>
                            <Button disabled={action.busy || companyId === ''} onClick={() => void linkCompany()} type="button" variant="outline">{t('fo.company.link')}</Button>
                        </div>
                    )}
                </section>
            ) : null}

            <section aria-labelledby="folio-h" className="flex flex-col gap-2">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-lg font-semibold" id="folio-h">{t('fo.folio.postings')}</h2>
                    {r.status !== 'cancelled' && r.status !== 'no_show' ? <Button disabled={action.busy} onClick={() => setNewFolio(folios.length === 0 ? 'Guest' : '')} size="sm" type="button" variant="outline">{t('fo.folio.open')}</Button> : null}
                </div>
                {newFolio !== null && (
                    <form className="flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); void openFolio(); }}>
                        <FormField field="label" error={action.fieldError('label')} hint={t('fo.folio.labelHint')} label={t('fo.folio.label')}><Input maxLength={60} onChange={(e) => setNewFolio(e.target.value)} value={newFolio} /></FormField>
                        <Button loading={action.busy} size="sm" type="submit">{t('fo.folio.open')}</Button>
                        <Button disabled={action.busy} onClick={() => setNewFolio(null)} size="sm" type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    </form>
                )}
                {folios.length === 0 ? <p className="text-sm text-muted-foreground">{t('fo.folio.none')}</p> : (
                    <ul className="divide-y divide-border border-y border-border text-sm">{folios.map((f) => (
                        <li className="flex flex-wrap items-center justify-between gap-2 py-2" key={f.id}>
                            <Link className="font-medium underline-offset-2 hover:underline" href={`/front-office/folios/${f.id}`}>{t('fo.folio.openLink', { number: f.number })}</Link>
                            <span className="text-xs text-muted-foreground">{t('fo.folio.windowLabel', { n: f.window, label: f.label })} · {t(`fo.folio.status.${f.status}`as 'fo.folio.status.open')} · {format.money(f.balance_minor, f.currency)}</span>
                        </li>
                    ))}</ul>
                )}
            </section>

            {r.status === 'checked_in' || r.status === 'completed' ? (
                <div><Button asChild variant="outline"><Link href={`/front-office/reservations/${r.id}/check-in`}>{t('fo.checkin.goToStay')}</Link></Button></div>
            ) : null}

            {expected && (
                <div className="flex flex-wrap gap-2">
                    {r.status !== 'tentative' && <Button asChild><Link href={`/front-office/reservations/${r.id}/check-in`}>{t('fo.checkin.action')}</Link></Button>}
                    {r.status === 'tentative' && <Button onClick={() => void begin('confirm')} type="button">{t('fo.action.confirm')}</Button>}
                    <Button onClick={() => void begin('cancel')} type="button" variant="outline">{t('fo.action.cancel')}</Button>
                    <Button onClick={() => void begin('noShow')} type="button" variant="outline">{t('fo.action.noShow')}</Button>
                </div>
            )}

            <ConfirmDialog
                cancelLabel={t('fo.action.back')}
                confirmLabel={kind === 'confirm' ? t('fo.action.confirm') : kind === 'cancel' ? t('fo.action.cancel') : t('fo.action.noShow')}
                consequence={kind === null ? '' : consequence[kind]}
                destructive={kind !== 'confirm'}
                onCancel={close}
                onConfirm={() => void apply()}
                open={kind !== null}
                pending={action.busy}
                title={kind === null ? '' : title[kind]}
            >
                <div className="flex flex-col gap-3">
                    {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                    {kind !== 'confirm' && penalty !== null && (penalty.amount_minor > 0
                        ? <Alert title={t('fo.res.penaltyDue', { amount: format.money(penalty.amount_minor, penalty.currency) })} tone="warning">
                            {penalty.may_waive ? <label className="mt-1 flex items-center gap-2 text-sm"><input checked={waive} onChange={(e) => setWaive(e.target.checked)} type="checkbox" />{t('fo.res.penaltyWaive')}</label> : null}
                        </Alert>
                        : <p className="text-xs text-muted-foreground">{t('fo.res.penaltyFree')}</p>)}
                    {kind !== 'confirm' && <FormField field="reason" error={action.fieldError('reason')} label={t('fo.action.reason')}><Input maxLength={500} onChange={(e) => setReason(e.target.value)} value={reason} /></FormField>}
                </div>
            </ConfirmDialog>
        </FrontOfficeShell>
    );
}
