import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Gate = { code: 'pending_arrivals' | 'overdue_departures' | 'in_house_without_open_folio' | 'same_day_stays'; count: number; items: string[] };
type Row = { id: string; business_date: string; next_business_date: string; report: { currency: string; room_nights_charged: number; revenue: { net: { total: number } } }; waivers: unknown[] };
type Preview = {
    business_date: string; earliest_local: string; can_start: boolean; gates: Gate[]; rooms_to_charge: number; may_run: boolean; may_waive: boolean; history: Row[];
};

export default function NightAuditPage({ preview: p }: { preview: Preview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [intent, setIntent] = useState(() => newIdempotencyKey());
    const [waive, setWaive] = useState<Record<string, string>>({});
    const [confirming, setConfirming] = useState(false);
    const [done, setDone] = useState<string | null>(null);
    const pending = p.gates.filter((g) => g.count > 0);
    const unresolved = pending.filter((g) => (waive[g.code] ?? '').trim() === '');
    const canRun = p.may_run && p.can_start && unresolved.length === 0;

    async function run() {
        const waivers = pending.map((g) => ({ gate: g.code, reason: (waive[g.code] ?? '').trim() }));
        const result = await action.run<{ audit: { business_date: string } }>('/front-office/night-audit', { idempotencyKey: intent, body: { waivers }, reload: ['preview'] });
        setConfirming(false);
        if (result !== null) {
            setIntent(newIdempotencyKey());
            setWaive({});
            setDone(result.audit.business_date);
        }
    }

    return (
        <FrontOfficeShell description={t('fo.audit.description')} title={t('fo.audit.title')}>
            {done !== null ? (
                <Alert actions={<Button asChild size="sm" variant="outline"><Link href={`/front-office/night-audit/${done}`}>{t('fo.audit.viewReport')}</Link></Button>} title={t('fo.audit.done', { date: format.date(done, 'long') })} tone="success" />
            ) : null}
            {action.error !== null && !confirming ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}

            <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[max-content_1fr]">
                <dt className="text-muted-foreground">{t('fo.audit.date')}</dt><dd className="font-medium" data-testid="audit-date">{format.date(p.business_date, 'long')}</dd>
                <dt className="text-muted-foreground">{t('fo.audit.earliest')}</dt><dd>{p.earliest_local}</dd>
                <dt className="text-muted-foreground">{t('fo.audit.toCharge')}</dt><dd>{p.rooms_to_charge}</dd>
            </dl>
            <Alert title={p.can_start ? t('fo.audit.ready') : t('fo.audit.tooEarly')} tone={p.can_start ? 'info' : 'warning'} />

            <section aria-labelledby="gates-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="gates-h">{t('fo.audit.checks')}</h2>
                {pending.length === 0 ? <p className="text-sm text-muted-foreground">{t('fo.audit.clear')}</p> : (
                    <>
                        {p.may_waive ? <p className="text-xs text-muted-foreground">{t('fo.audit.waiveHint')}</p> : null}
                        <ul className="flex flex-col gap-4">
                            {pending.map((g) => (
                                <li className="flex flex-col gap-2 border border-border p-3" key={g.code}>
                                    <p className="font-medium">{t(`fo.audit.gate.${g.code}` as 'fo.audit.gate.pending_arrivals')} ({g.count})</p>
                                    <p className="text-xs text-muted-foreground">{t(`fo.audit.gate.${g.code}.help` as 'fo.audit.gate.pending_arrivals.help')}</p>
                                    <ul className="list-disc pl-5 text-sm">{g.items.map((item) => <li key={item}>{item}</li>)}</ul>
                                    {p.may_waive ? (
                                        <FormField label={t('fo.audit.waiveReason', { gate: t(`fo.audit.gate.${g.code}` as 'fo.audit.gate.pending_arrivals') })}>
                                            <Input maxLength={300} onChange={(e) => setWaive({ ...waive, [g.code]: e.target.value })} placeholder={t('fo.audit.waive')} value={waive[g.code] ?? ''} />
                                        </FormField>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    </>
                )}
            </section>

            {p.may_run ? <div><Button disabled={!canRun} onClick={() => { action.clear(); setConfirming(true); }} type="button">{t('fo.audit.run')}</Button></div> : null}

            <section aria-labelledby="hist-h" className="flex flex-col gap-2">
                <h2 className="text-lg font-semibold" id="hist-h">{t('fo.audit.history')}</h2>
                {p.history.length === 0 ? <p className="text-sm text-muted-foreground">{t('fo.audit.historyNone')}</p> : (
                    <ul className="divide-y divide-border border-y border-border text-sm">
                        {p.history.map((h) => (
                            <li className="flex flex-wrap items-center justify-between gap-2 py-2" key={h.id}>
                                <Link className="font-medium underline-offset-2 hover:underline" href={`/front-office/night-audit/${h.business_date}`}>{format.date(h.business_date, 'long')}</Link>
                                <span className="text-xs text-muted-foreground">{t('fo.audit.report.charged')}: {h.report.room_nights_charged} · {t('fo.audit.report.net')}: {format.money(h.report.revenue.net.total, h.report.currency)}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <ConfirmDialog
                cancelLabel={t('fo.action.back')}
                confirmLabel={t('fo.audit.run')}
                consequence={t('fo.audit.runConsequence', { date: format.date(p.business_date, 'long') })}
                destructive
                onCancel={() => setConfirming(false)}
                onConfirm={() => void run()}
                open={confirming}
                pending={action.busy}
                title={t('fo.audit.runConfirm', { date: format.date(p.business_date, 'long') })}
            >
                {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            </ConfirmDialog>
        </FrontOfficeShell>
    );
}
