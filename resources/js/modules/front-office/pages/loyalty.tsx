import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Account = { id: string; name: string; phone_last4: string | null; balance_points: number; earned_points: number; tier: string | null; bonus_percent: number };
type Entry = { id: string; kind: string; points: number; balance_after: number; source_type: string; note: string | null; business_date: string };
type Overview = { currency: string; enabled: boolean; earn_per_point_minor: number; point_value_minor: number; min_redeem_points: number; expire_months: number; tiers: { key: string; from_points: number; bonus_percent: number }[]; accounts: Account[]; open: { account: Account; entries: Entry[] } | null; may: { redeem: boolean; adjust: boolean } };
type Made = { card: { face_minor: number; expires_on: string | null }; code: string | null; replayed: boolean; balance_points: number };

/** Loyalty points: who holds how many, what they came from, turning points into a voucher (the code is shown once), and a correction with a reason. */
export default function LoyaltyPage({ overview }: { overview: Overview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const reload = ['overview'];
    const money = (minor: number) => format.money(minor, overview.currency);
    const [redeem, setRedeem] = useState<{ account: Account; points: string; intent: string } | null>(null);
    const [adjust, setAdjust] = useState<{ account: Account; points: string; reason: string } | null>(null);
    const [made, setMade] = useState<Made | null>(null);

    function look(account: Account) {
        router.get('/front-office/loyalty', { account: account.id }, { preserveState: true, preserveScroll: true, only: ['overview'] });
    }

    async function redeemNow() {
        if (redeem === null) return;
        const done = await action.run<Made>(`/front-office/loyalty/${redeem.account.id}/redeem`, { body: { points: Number(redeem.points) || 0 }, idempotencyKey: redeem.intent, reload });
        if (done !== null) { setRedeem(null); setMade(done); }
    }

    async function adjustNow() {
        if (adjust === null) return;
        const done = await action.run(`/front-office/loyalty/${adjust.account.id}/adjust`, { body: { points: Number(adjust.points) || 0, reason: adjust.reason.trim() }, reload });
        if (done !== null) setAdjust(null);
    }

    const columns: DataGridColumn<Account>[] = [
        { id: 'guest', label: t('fo.ly.col.guest'), value: (a) => a.name, rowHeader: true, cell: (a) => <span>{a.name}{a.phone_last4 !== null ? <span className="text-xs text-muted-foreground"> · …{a.phone_last4}</span> : null}{a.tier !== null ? <span className="ml-2"><StatusBadge label={`${a.tier}${a.bonus_percent > 0 ? ` +${a.bonus_percent}%` : ''}`} tone="info" /></span> : null}</span> },
        { id: 'balance', label: t('fo.ly.col.balance'), align: 'right', value: (a) => a.balance_points, cell: (a) => <span>{format.number(a.balance_points)}<span className="block text-xs text-muted-foreground">{money(a.balance_points * overview.point_value_minor)}</span></span> },
        { id: 'earned', label: t('fo.ly.col.earned'), align: 'right', value: (a) => a.earned_points, cell: (a) => format.number(a.earned_points) },
        {
            id: 'actions',
            label: '',
            value: () => '',
            sortable: false,
            cell: (a) => (
                <span className="flex flex-wrap justify-end gap-2">
                    <Button onClick={() => look(a)} size="sm" type="button" variant="ghost">{t('fo.ly.history')}</Button>
                    {overview.may.redeem && overview.enabled ? <Button disabled={a.balance_points < overview.min_redeem_points} onClick={() => { action.clear(); setRedeem({ account: a, points: String(Math.max(overview.min_redeem_points, 0)), intent: newIdempotencyKey() }); }} size="sm" type="button" variant="outline">{t('fo.ly.redeem')}</Button> : null}
                    {overview.may.adjust ? <Button onClick={() => { action.clear(); setAdjust({ account: a, points: '', reason: '' }); }} size="sm" type="button" variant="ghost">{t('fo.ly.adjust')}</Button> : null}
                </span>
            ),
        },
    ];

    const history: DataGridColumn<Entry>[] = [
        { id: 'date', label: t('fo.ly.col.date'), value: (e) => e.business_date, cell: (e) => format.date(e.business_date) },
        { id: 'kind', label: t('fo.ly.col.what'), value: (e) => e.kind, cell: (e) => <span>{t(`fo.ly.kind.${e.kind}` as 'fo.ly.kind.earn')}{e.note !== null ? <span className="block text-xs text-muted-foreground">{e.note}</span> : null}</span> },
        { id: 'points', label: t('fo.ly.col.points'), align: 'right', value: (e) => e.points, cell: (e) => `${e.points > 0 ? '+' : ''}${format.number(e.points)}` },
        { id: 'after', label: t('fo.ly.col.after'), align: 'right', value: (e) => e.balance_after, cell: (e) => format.number(e.balance_after) },
    ];

    return (
        <FrontOfficeShell description={t('fo.ly.description')} title={t('fo.ly.title')} wide>
            {action.error !== null && redeem === null && adjust === null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            {!overview.enabled ? <Alert title={t('fo.ly.off')} tone="warning">{t('fo.ly.offBody')}</Alert> : <div className="grid gap-1 text-sm text-muted-foreground"><p>{t('fo.ly.rules', { earn: money(overview.earn_per_point_minor), value: money(overview.point_value_minor), min: overview.min_redeem_points })}</p>{overview.tiers.length > 0 ? <p>{t('fo.ly.tiers', { list: overview.tiers.map((x) => `${x.key} ${format.number(x.from_points)}+ (+${x.bonus_percent}%)`).join(', ') })}</p> : null}<p>{overview.expire_months > 0 ? t('fo.ly.expires', { n: overview.expire_months }) : t('fo.ly.neverExpires')}</p></div>}
            <DataGrid caption={t('fo.ly.title')} columns={columns} empty={<EmptyState title={t('fo.ly.none')} />} getRowId={(a) => a.id} id="fo.loyalty" rows={overview.accounts} testId="loyalty-accounts" />

            {overview.open !== null ? (
                <section className="grid gap-2">
                    <h2 className="text-base font-semibold">{t('fo.ly.historyOf', { name: overview.open.account.name })}</h2>
                    <DataGrid caption={t('fo.ly.history')} columns={history} getRowId={(e) => e.id} id="fo.loyalty.entries" rows={overview.open.entries} testId="loyalty-entries" />
                </section>
            ) : null}

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setRedeem(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void redeemNow()} type="button">{t('fo.ly.redeem')}</Button></>}
                onClose={() => setRedeem(null)}
                open={redeem !== null}
                title={t('fo.ly.redeemTitle')}
            >
                {redeem !== null && (
                    <div className="grid gap-3">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <p className="text-sm">{t('fo.ly.redeemBody', { name: redeem.account.name, balance: format.number(redeem.account.balance_points), min: overview.min_redeem_points })}</p>
                        <FormField error={action.fieldError('points')} field="points" hint={t('fo.ly.worth', { value: money((Number(redeem.points) || 0) * overview.point_value_minor) })} label={t('fo.ly.col.points')}><Input inputMode="numeric" maxLength={7} onChange={(e) => setRedeem({ ...redeem, points: e.target.value.replace(/\D/g, '') })} value={redeem.points} /></FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setAdjust(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={adjust?.reason.trim() === '' || adjust?.points === ''} loading={action.busy} onClick={() => void adjustNow()} type="button">{t('fo.ly.adjust')}</Button></>}
                onClose={() => setAdjust(null)}
                open={adjust !== null}
                title={t('fo.ly.adjustTitle')}
            >
                {adjust !== null && (
                    <div className="grid gap-3">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <p className="text-sm">{adjust.account.name} · {format.number(adjust.account.balance_points)}</p>
                        <FormField error={action.fieldError('points')} field="points" hint={t('fo.ly.adjustHint')} label={t('fo.ly.col.points')}><Input inputMode="numeric" maxLength={7} onChange={(e) => setAdjust({ ...adjust, points: e.target.value.replace(/[^\d-]/g, '') })} value={adjust.points} /></FormField>
                        <FormField error={action.fieldError('reason')} field="reason" label={t('fo.lock.reason')}><Input maxLength={200} onChange={(e) => setAdjust({ ...adjust, reason: e.target.value })} value={adjust.reason} /></FormField>
                    </div>
                )}
            </Dialog>

            <Dialog footer={<><Button onClick={() => window.print()} type="button" variant="outline">{t('fo.gc.print')}</Button><Button onClick={() => setMade(null)} type="button">{t('fo.gc.done')}</Button></>} onClose={() => setMade(null)} open={made !== null} title={t('fo.gc.madeTitle')}>
                {made !== null && (
                    <div className="grid gap-3">
                        <p className="text-sm">{t('fo.gc.madeBody', { amount: money(made.card.face_minor), until: made.card.expires_on === null ? '—' : format.date(made.card.expires_on) })}</p>
                        {made.code !== null ? (
                            <>
                                <p className="select-all break-all border border-border bg-muted p-3 text-center font-mono text-2xl font-semibold tracking-wider" data-testid="loyalty-voucher-code">{made.code}</p>
                                <Alert title={t('fo.gc.onceTitle')} tone="warning">{t('fo.gc.once')}</Alert>
                            </>
                        ) : <Alert title={t('fo.gc.sentTwice')} tone="warning">{t('fo.gc.sentTwiceBody')}</Alert>}
                    </div>
                )}
            </Dialog>
        </FrontOfficeShell>
    );
}
