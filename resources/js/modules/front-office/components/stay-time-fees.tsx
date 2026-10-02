import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

export type StayTimeFees = {
    stay_id: string;
    items: {
        kind: 'early_checkin' | 'late_checkout'; policy_id: string | null; standard_time: string | null; minutes: number; grace_minutes: number; percent_bp: number; night_base_minor: number; fee_base_minor: number; chargeable: boolean;
        decision: { status: 'charged' | 'waived'; fee_base_minor: number; reason: string | null } | null;
    }[];
    may: { apply: boolean; waive: boolean };
};

/** Early check-in and late check-out of this stay against the property's policy (FR-FO-037): the minutes, the share, and a decision to charge or waive. */
export function StayTimeFeesPanel({ currency, fees }: { currency: string; fees: StayTimeFees }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [waiving, setWaiving] = useState<{ kind: string; reason: string } | null>(null);
    const shown = fees.items.filter((i) => i.decision !== null || i.chargeable);
    const pct = (bp: number) => `${bp / 100}%`;

    async function decide(kind: string, what: 'charge' | 'waive', reason: string | null) {
        const done = await action.run('/front-office/stays/' + fees.stay_id + '/time-fees', { body: { kind, action: what, reason }, reload: ['time_fees', 'stay'] });
        if (done !== null) setWaiving(null);
    }

    if (shown.length === 0) return null;

    return (
        <section aria-labelledby="time-fees-h" className="flex flex-col gap-2" data-testid="time-fees">
            <h2 className="text-lg font-semibold" id="time-fees-h">{t('fo.timefee.title')}</h2>
            {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            <ul className="flex flex-col gap-3">
                {shown.map((i) => (
                    <li className="flex flex-col gap-2 border border-border p-3 text-sm" data-testid={`time-fee-${i.kind}`} key={i.kind}>
                        <p>{t(`fo.timefee.${i.kind}` as 'fo.timefee.early_checkin', { minutes: i.minutes, time: i.standard_time ?? '', percent: pct(i.percent_bp), amount: format.money(i.decision?.fee_base_minor ?? i.fee_base_minor, currency) })}</p>
                        {i.decision !== null ? (
                            <p><StatusBadge label={t(`fo.timefee.status.${i.decision.status}` as 'fo.timefee.status.charged')} tone={i.decision.status === 'charged' ? 'success' : 'neutral'} />{i.decision.reason !== null ? ` ${i.decision.reason}` : ''}</p>
                        ) : (
                            <div className="flex flex-wrap gap-2">
                                {fees.may.apply ? <Button disabled={action.busy} onClick={() => void decide(i.kind, 'charge', null)} size="sm" type="button">{t('fo.timefee.charge')}</Button> : null}
                                {fees.may.waive ? <Button disabled={action.busy} onClick={() => setWaiving({ kind: i.kind, reason: '' })} size="sm" type="button" variant="outline">{t('fo.timefee.waive')}</Button> : null}
                            </div>
                        )}
                        {waiving?.kind === i.kind && (
                            <div className="flex flex-wrap items-end gap-2">
                                <FormField error={action.fieldError('reason')} label={t('fo.timefee.reason')}><Input maxLength={300} onChange={(e) => setWaiving({ ...waiving, reason: e.target.value })} value={waiving.reason} /></FormField>
                                <Button disabled={waiving.reason.trim() === ''} loading={action.busy} onClick={() => void decide(i.kind, 'waive', waiving.reason.trim())} size="sm" type="button">{t('fo.timefee.confirmWaive')}</Button>
                            </div>
                        )}
                    </li>
                ))}
            </ul>
            <p className="text-xs text-muted-foreground">{t('fo.timefee.note')}</p>
        </section>
    );
}
