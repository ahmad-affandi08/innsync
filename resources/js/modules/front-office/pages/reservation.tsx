import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
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
type Kind = 'confirm' | 'cancel' | 'noShow';

const PATH = { confirm: 'confirm', cancel: 'cancel', noShow: 'no-show' } as const;

export default function ReservationPage({ folios, lookups, reservation: r }: { folios: FolioRow[]; lookups: Lookups; reservation: Reservation }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [kind, setKind] = useState<Kind | null>(null);
    const [reason, setReason] = useState('');
    const type = lookups.types.find((x) => x.id === r.room_type_id);
    const plan = lookups.plans.find((x) => x.id === r.rate_plan_id);
    async function openFolio() {
        const done = await action.run<{ folio: { id: string } }>(`/front-office/reservations/${r.id}/folios`, { body: { label: 'Guest', window: folios.length + 1 } });
        if (done !== null) router.visit(`/front-office/folios/${done.folio.id}`);
    }

    const expected = r.status === 'tentative' || r.status === 'confirmed' || r.status === 'guaranteed';

    function close() {
        action.clear();
        setKind(null);
        setReason('');
    }

    async function apply() {
        if (kind === null) return;
        const done = await action.run(`/front-office/reservations/${r.id}/${PATH[kind]}`, { body: kind === 'confirm' ? { lock_version: r.lock_version } : { lock_version: r.lock_version, reason }, reload: ['reservation'] });
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
                <dt className="text-muted-foreground">{t('fo.res.source')}</dt><dd>{t(`fo.source.${r.source}` as 'fo.source.direct')}</dd>
                <dt className="text-muted-foreground">{t('fo.res.phone')}</dt><dd>{r.guest_phone ?? '—'}</dd>
                <dt className="text-muted-foreground">{t('fo.res.email')}</dt><dd>{r.guest_email ?? '—'}</dd>
                {r.notes !== null && <><dt className="text-muted-foreground">{t('fo.res.notes')}</dt><dd className="break-words">{r.notes}</dd></>}
                <dt className="text-muted-foreground">{t('fo.res.total')}</dt><dd className="font-medium">{format.money(r.total_minor, r.currency)}</dd>
            </dl>
            {r.guest_phone === null && r.guest_email === null ? <p className="text-xs text-muted-foreground">{t('fo.res.contactHidden')}</p> : null}

            <section aria-labelledby="snap-h" className="flex flex-col gap-2">
                <h2 className="text-lg font-semibold" id="snap-h">{t('fo.res.snapshot')}</h2>
                <p className="text-xs text-muted-foreground">{t('fo.res.snapshotNote')}</p>
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead><tr className="text-xs text-muted-foreground"><th className="py-1 font-medium" scope="col">{t('fo.res.arrival')}</th><th scope="col">{t('rates.quote.base')}</th><th scope="col">{t('rates.quote.service')}</th><th scope="col">{t('rates.quote.tax')}</th><th scope="col">{t('rates.quote.total')}</th></tr></thead>
                        <tbody>{r.price_snapshot.nights.map((n) => (
                            <tr className="border-t border-border" key={n.date}><td className="py-1">{format.date(n.date)}</td><td>{format.money(n.base_minor, r.currency)}</td><td>{format.money(n.service_charge_minor, r.currency)}</td><td>{format.money(n.tax_minor, r.currency)}</td><td>{format.money(n.total_minor, r.currency)}</td></tr>
                        ))}</tbody>
                    </table>
                </div>
            </section>

            <section aria-labelledby="folio-h" className="flex flex-col gap-2">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-lg font-semibold" id="folio-h">{t('fo.folio.postings')}</h2>
                    {r.status !== 'cancelled' && r.status !== 'no_show' ? <Button disabled={action.busy} onClick={() => void openFolio()} size="sm" type="button" variant="outline">{t('fo.folio.open')}</Button> : null}
                </div>
                {folios.length === 0 ? <p className="text-sm text-muted-foreground">{t('fo.folio.none')}</p> : (
                    <ul className="divide-y divide-border border-y border-border text-sm">{folios.map((f) => (
                        <li className="flex flex-wrap items-center justify-between gap-2 py-2" key={f.id}>
                            <Link className="font-medium underline-offset-2 hover:underline" href={`/front-office/folios/${f.id}`}>{t('fo.folio.openLink', { number: f.number })}</Link>
                            <span className="text-xs text-muted-foreground">{t('fo.folio.windowLabel', { n: f.window, label: f.label })} · {t(`fo.folio.status.${f.status}` as 'fo.folio.status.open')} · {format.money(f.balance_minor, f.currency)}</span>
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
                    {r.status === 'tentative' && <Button onClick={() => { action.clear(); setKind('confirm'); }} type="button">{t('fo.action.confirm')}</Button>}
                    <Button onClick={() => { action.clear(); setKind('cancel'); }} type="button" variant="outline">{t('fo.action.cancel')}</Button>
                    <Button onClick={() => { action.clear(); setKind('noShow'); }} type="button" variant="outline">{t('fo.action.noShow')}</Button>
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
                    {kind !== 'confirm' && <FormField error={action.fieldError('reason')} label={t('fo.action.reason')}><Input maxLength={500} onChange={(e) => setReason(e.target.value)} value={reason} /></FormField>}
                </div>
            </ConfirmDialog>
        </FrontOfficeShell>
    );
}
