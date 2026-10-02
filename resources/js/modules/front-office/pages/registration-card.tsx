import { Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/error-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Card = {
    hotel: string; stay_id: string; reservation_number: string; status: string; room_number: string | null; arrival: string; departure: string; adults: number; children: number;
    guest: { full_name: string; nationality: string; id_type: string; id_number: string; visa_number: string | null; address: string | null; identity_visible: boolean };
    rate: { nights: number; total_minor: number; currency: string } | null;
    terms: { version: number | null; body: string } | null;
    signed: { at: string; by: string | null } | null;
    may_sign: boolean; may_see_signature: boolean;
};

/** The registration card (FR-FO-017): printed for the guest to sign, or signed on a tablet. */
export default function RegistrationCardPage({ card: c }: { card: Card }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const canvas = useRef<HTMLCanvasElement | null>(null);
    const drawing = useRef(false);
    const [inked, setInked] = useState(false);

    useEffect(() => {
        const el = canvas.current;
        if (el === null) return;
        const ctx = el.getContext('2d');
        if (ctx === null) return;
        ctx.lineWidth = 2.5;
        ctx.lineCap = 'round';
        ctx.strokeStyle = '#111827';
    }, [c.may_sign]);

    function point(event: React.PointerEvent<HTMLCanvasElement>) {
        const rect = event.currentTarget.getBoundingClientRect();
        return { x: ((event.clientX - rect.left) * event.currentTarget.width) / rect.width, y: ((event.clientY - rect.top) * event.currentTarget.height) / rect.height };
    }

    function down(event: React.PointerEvent<HTMLCanvasElement>) {
        const ctx = event.currentTarget.getContext('2d');
        if (ctx === null) return;
        event.currentTarget.setPointerCapture(event.pointerId);
        drawing.current = true;
        const p = point(event);
        ctx.beginPath();
        ctx.moveTo(p.x, p.y);
    }

    function move(event: React.PointerEvent<HTMLCanvasElement>) {
        if (!drawing.current) return;
        const ctx = event.currentTarget.getContext('2d');
        if (ctx === null) return;
        const p = point(event);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
        setInked(true);
    }

    function clear() {
        const el = canvas.current;
        el?.getContext('2d')?.clearRect(0, 0, el.width, el.height);
        setInked(false);
    }

    async function sign() {
        const el = canvas.current;
        if (el === null || !inked) return;
        await action.run(`/front-office/stays/${c.stay_id}/registration-card/sign`, { body: { signature: el.toDataURL('image/png') }, reload: ['card'] });
    }

    return (
        <FrontOfficeShell description={t('fo.regcard.description')} title={t('fo.regcard.title')}>
            <div className="flex flex-wrap gap-2 print:hidden">
                <Button asChild size="sm" variant="outline"><Link href={`/front-office/stays/${c.stay_id}`}>{t('fo.regcard.backToStay')}</Link></Button>
                <Button onClick={() => window.print()} size="sm" type="button" variant="outline">{t('rpt.export.print')}</Button>
            </div>
            {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}

            <article className="flex flex-col gap-4 border border-border p-6 print:border-0 print:p-0" data-testid="registration-card">
                <header className="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 className="text-2xl font-semibold">{c.hotel}</h2>
                    <p className="text-sm text-muted-foreground">{t('fo.regcard.number', { number: c.reservation_number })}</p>
                </header>
                <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[max-content_1fr]">
                    <dt className="text-muted-foreground">{t('fo.checkin.fullName')}</dt><dd className="font-medium">{c.guest.full_name}</dd>
                    <dt className="text-muted-foreground">{t('fo.checkin.nationality')}</dt><dd>{c.guest.nationality}</dd>
                    <dt className="text-muted-foreground">{t('fo.checkin.idType')}</dt><dd>{t(`fo.checkin.idType.${c.guest.id_type}` as 'fo.checkin.idType.ktp')} · <span className="font-mono">{c.guest.id_number}</span></dd>
                    {c.guest.visa_number !== null && <><dt className="text-muted-foreground">{t('fo.checkin.visa')}</dt><dd className="font-mono">{c.guest.visa_number}</dd></>}
                    {c.guest.address !== null && <><dt className="text-muted-foreground">{t('fo.checkin.address')}</dt><dd className="break-words">{c.guest.address}</dd></>}
                    <dt className="text-muted-foreground">{t('fo.regcard.room')}</dt><dd>{c.room_number ?? '—'}</dd>
                    <dt className="text-muted-foreground">{t('fo.regcard.stay')}</dt><dd>{format.date(c.arrival, 'long')} – {format.date(c.departure, 'long')}</dd>
                    <dt className="text-muted-foreground">{t('fo.regcard.guests')}</dt><dd>{t('fo.res.guests', { adults: c.adults, children: c.children })}</dd>
                    {c.rate !== null && <><dt className="text-muted-foreground">{t('fo.regcard.rate')}</dt><dd>{t('fo.regcard.rateLine', { nights: c.rate.nights, amount: format.money(c.rate.total_minor, c.rate.currency) })}</dd></>}
                </dl>
                {!c.guest.identity_visible ? <p className="text-xs text-muted-foreground print:hidden">{t('fo.stay.identityHidden')}</p> : null}

                <section aria-labelledby="terms-h" className="flex flex-col gap-1">
                    <h3 className="text-sm font-semibold" id="terms-h">{t('fo.regcard.terms')}</h3>
                    {c.terms === null ? <p className="text-sm text-muted-foreground">{t('fo.regcard.noTerms')}</p> : <p className="whitespace-pre-line text-sm" data-testid="card-terms">{c.terms.body}</p>}
                </section>

                <section aria-labelledby="sign-h" className="flex flex-col gap-2">
                    <h3 className="text-sm font-semibold" id="sign-h">{t('fo.regcard.signature')}</h3>
                    {c.signed !== null ? (
                        <div className="flex flex-col gap-1" data-testid="signed">
                            {c.may_see_signature ? <img alt={t('fo.regcard.signatureAlt')} className="h-32 w-80 border border-border bg-white object-contain" src={`/front-office/stays/${c.stay_id}/registration-card/signature`} /> : null}
                            <p className="text-xs text-muted-foreground">{t('fo.regcard.signedAt', { time: format.instant(c.signed.at), by: c.signed.by ?? '—' })}</p>
                            <StatusBadge label={t('fo.regcard.signed')} tone="success" />
                        </div>
                    ) : c.may_sign ? (
                        <div className="flex flex-col gap-2 print:hidden">
                            <canvas aria-label={t('fo.regcard.signatureArea')} className="h-40 w-full max-w-xl touch-none border border-border bg-white" data-testid="signature-pad" height={160} onPointerDown={down} onPointerMove={move} onPointerUp={() => { drawing.current = false; }} ref={canvas} width={640} />
                            <div className="flex gap-2">
                                <Button disabled={!inked} loading={action.busy} onClick={() => void sign()} type="button">{t('fo.regcard.sign')}</Button>
                                <Button disabled={action.busy || !inked} onClick={clear} type="button" variant="outline">{t('fo.regcard.clear')}</Button>
                            </div>
                        </div>
                    ) : null}
                    <div aria-hidden className="mt-6 hidden border-b border-foreground print:block" />
                </section>
            </article>
        </FrontOfficeShell>
    );
}
