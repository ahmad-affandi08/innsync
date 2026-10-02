import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Rate = { currency: string; version: number; rate_e4: number; reason: string; created_at: string };
type Recent = { posting_id: string; currency: string; foreign_minor: number; rate_e4: number; rate_version: number; booked_minor: number; booked_currency: string; business_date: string; folio_id: string; folio_number: string; reversed: boolean };
type Today = { currency: string; foreign_minor: number; booked_minor: number; count: number };
type Overview = { enabled: boolean; lock_version: number; home_currency: string; currencies: string[]; rates: Rate[]; recent: Recent[]; today: Today[]; business_date: string };

/** A rate typed as "16050" or "16050.5" (at most four decimals) as ten-thousandths of the hotel's currency, or null. */
function parseRate(text: string): number | null {
    const match = /^(\d{1,7})(?:[.,](\d{1,4}))?$/.exec(text.trim());
    if (match === null) return null;
    const value = Number(match[1]) * 10000 + Number((match[2] ?? '').padEnd(4, '0'));
    return value >= 1 && value <= 10_000_000_000 ? value : null;
}

/** Whether the hotel takes foreign money, the rates it gives and what was taken (FR-FO-026). */
export default function ForeignCurrencyPage({ overview }: { overview: Overview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [switchReason, setSwitchReason] = useState('');
    const [rate, setRate] = useState({ currency: overview.currencies[0] ?? 'USD', value: '', reason: '' });
    const [invalid, setInvalid] = useState(false);
    const rateText = (e4: number) => format.number(e4 / 10000);

    async function toggle() {
        const done = await action.run('/front-office/foreign-currency/enabled', { body: { enabled: !overview.enabled, lock_version: overview.lock_version, reason: switchReason.trim() }, reload: ['overview'] });
        if (done !== null) setSwitchReason('');
    }

    async function saveRate() {
        const e4 = parseRate(rate.value);
        setInvalid(e4 === null);
        if (e4 === null) return;
        const done = await action.run('/front-office/foreign-currency/rates', { body: { currency: rate.currency, rate_e4: e4, reason: rate.reason.trim() }, reload: ['overview'] });
        if (done !== null) setRate({ ...rate, value: '', reason: '' });
    }

    return (
        <FrontOfficeShell description={t('fo.foreign.description')} title={t('fo.foreign.title')} wide>
            {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            <Alert title={t('fo.foreign.counsel')} tone="warning" />

            <section aria-labelledby="fx-switch-h" className="flex flex-col gap-2" data-testid="fx-switch">
                <h2 className="text-lg font-semibold" id="fx-switch-h">{t('fo.foreign.switch')}</h2>
                <p className="text-sm"><StatusBadge label={overview.enabled ? t('fo.foreign.on') : t('fo.foreign.off')} tone={overview.enabled ? 'success' : 'neutral'} /></p>
                <div className="flex flex-wrap items-end gap-2">
                    <FormField error={action.fieldError('reason')} label={t('fo.folio.reason')}><Input maxLength={300} onChange={(e) => setSwitchReason(e.target.value)} value={switchReason} /></FormField>
                    <Button disabled={action.busy || switchReason.trim() === ''} onClick={() => void toggle()} type="button" variant="outline">{overview.enabled ? t('fo.foreign.turnOff') : t('fo.foreign.turnOn')}</Button>
                </div>
            </section>

            <section aria-labelledby="fx-rates-h" className="flex flex-col gap-2">
                <h2 className="text-lg font-semibold" id="fx-rates-h">{t('fo.foreign.rates')}</h2>
                {overview.rates.length === 0 ? <EmptyState title={t('fo.foreign.noRates')} /> : (
                    <Table data-testid="rates">
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead scope="col">{t('fo.foreign.currency')}</TableHead>
                                <TableHead className="text-right" scope="col">{t('fo.foreign.rate', { currency: overview.home_currency })}</TableHead>
                                <TableHead className="text-right" scope="col">{t('fo.foreign.version')}</TableHead>
                                <TableHead scope="col">{t('fo.folio.reason')}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>{overview.rates.map((r) => (
                            <TableRow key={r.currency}>
                                <TableHead className="font-medium text-foreground" scope="row">{r.currency}</TableHead>
                                <TableCell className="text-right tabular-nums">{rateText(r.rate_e4)}</TableCell>
                                <TableCell className="text-right tabular-nums">{r.version}</TableCell>
                                <TableCell>{r.reason}</TableCell>
                            </TableRow>
                        ))}</TableBody>
                    </Table>
                )}
                <form className="grid gap-3 sm:grid-cols-4" onSubmit={(e) => { e.preventDefault(); void saveRate(); }}>
                    <FormField error={action.fieldError('currency')} label={t('fo.foreign.currency')}><Select onChange={(e) => setRate({ ...rate, currency: e.target.value })} value={rate.currency}>{overview.currencies.map((c) => <option key={c} value={c}>{c}</option>)}</Select></FormField>
                    <FormField error={invalid ? t('fo.foreign.invalidRate') : action.fieldError('rate_e4')} hint={t('fo.foreign.rateHint', { currency: overview.home_currency })} label={t('fo.foreign.rate', { currency: overview.home_currency })}><Input inputMode="decimal" onChange={(e) => setRate({ ...rate, value: e.target.value })} required value={rate.value} /></FormField>
                    <FormField error={action.fieldError('reason')} label={t('fo.folio.reason')}><Input maxLength={300} onChange={(e) => setRate({ ...rate, reason: e.target.value })} required value={rate.reason} /></FormField>
                    <div className="flex items-end"><Button loading={action.busy} type="submit">{t('fo.foreign.setRate')}</Button></div>
                </form>
            </section>

            <section aria-labelledby="fx-today-h" className="flex flex-col gap-2">
                <h2 className="text-lg font-semibold" id="fx-today-h">{t('fo.foreign.today', { date: format.date(overview.business_date) })}</h2>
                {overview.today.length === 0 ? <p className="text-sm text-muted-foreground">{t('fo.foreign.nothingToday')}</p> : (
                    <ul className="text-sm" data-testid="fx-today">{overview.today.map((x) => <li key={x.currency}>{x.currency} {format.money(x.foreign_minor, x.currency)} → {format.money(x.booked_minor, overview.home_currency)} ({x.count})</li>)}</ul>
                )}
            </section>

            <section aria-labelledby="fx-recent-h" className="flex flex-col gap-2">
                <h2 className="text-lg font-semibold" id="fx-recent-h">{t('fo.foreign.recent')}</h2>
                {overview.recent.length === 0 ? <EmptyState title={t('fo.foreign.nonePaid')} /> : (
                    <ul className="divide-y divide-border border-y border-border text-sm" data-testid="fx-recent">{overview.recent.map((p) => (
                        <li className="flex flex-wrap items-center justify-between gap-2 py-2" key={p.posting_id}>
                            <span>{format.date(p.business_date)} · {p.currency} {format.money(p.foreign_minor, p.currency)} @ {rateText(p.rate_e4)} → {format.money(p.booked_minor, p.booked_currency)}{p.reversed ? <StatusBadge label={t('fo.foreign.reversed')} tone="neutral" /> : null}</span>
                            <Link className="underline-offset-2 hover:underline" href={`/front-office/folios/${p.folio_id}`}>{p.folio_number}</Link>
                        </li>
                    ))}</ul>
                )}
            </section>
        </FrontOfficeShell>
    );
}
