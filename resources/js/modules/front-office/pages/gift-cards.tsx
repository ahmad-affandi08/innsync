import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DatePicker } from '@/components/ui/date-picker';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { MoneyInput } from '@/components/ui/money-input';
import { Select } from '@/components/ui/select';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';

type Card = { id: string; kind: string; last4: string; currency: string; face_minor: number; balance_minor: number; expires_on: string | null; state: string; holder_note: string | null; issue_method: string; issued_on: string };
type Overview = { currency: string; today: string; default_expiry: string; methods: string[]; dispositions: string[]; cards: Card[]; may: { issue: boolean; void: boolean } };

const TONE: Record<string, StatusTone> = { active: 'success', spent: 'neutral', expired: 'warning', void: 'neutral' };

/** Gift cards and vouchers: sell, find what a code is worth, void. The code is shown once, when the card is made. */
export default function GiftCardsPage({ overview }: { overview: Overview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const blank = { kind: 'gift_card', amount: '', method: 'cash', reference: '', expires: overview.default_expiry, note: '' };
    const [sell, setSell] = useState<typeof blank | null>(null);
    const [intent, setIntent] = useState(() => newIdempotencyKey());
    const [made, setMade] = useState<{ code: string | null; card: Card } | null>(null);
    const [code, setCode] = useState('');
    const [found, setFound] = useState<Card | null>(null);
    const [voiding, setVoiding] = useState<{ card: Card; disposition: string; reason: string } | null>(null);
    const reload = ['overview'];
    const money = (minor: number) => format.money(minor, overview.currency);

    async function issue() {
        if (sell === null) return;
        const amount = parseMajorToMinor(sell.amount, overview.currency);
        const done = await action.run<{ card: Card; code: string | null }>('/front-office/gift-cards', {
            idempotencyKey: intent,
            body: { amount_minor: amount ?? 0, kind: sell.kind, payment_method: sell.method, reference: sell.reference.trim() === '' ? null : sell.reference.trim(), expires_on: sell.expires === '' ? null : sell.expires, holder_note: sell.note.trim() === '' ? null : sell.note.trim() },
            reload,
        });

        if (done !== null) {
            setIntent(newIdempotencyKey());
            setSell(null);
            setMade(done);
        }
    }

    async function lookup() {
        setFound(null);
        const done = await action.run<{ card: Card }>('/front-office/gift-cards/lookup', { body: { code }, reload: [] });
        if (done !== null) setFound(done.card);
    }

    async function doVoid() {
        if (voiding === null) return;
        const done = await action.run(`/front-office/gift-cards/${voiding.card.id}/void`, { body: { disposition: voiding.disposition, reason: voiding.reason.trim() }, reload });
        if (done !== null) setVoiding(null);
    }

    const columns: DataGridColumn<Card>[] = [
        { id: 'card', label: t('fo.gc.col.card'), value: (c) => c.last4, rowHeader: true, cell: (c) => <span>GC-...{c.last4}<span className="block text-xs text-muted-foreground">{t(`fo.gc.kind.${c.kind}` as 'fo.gc.kind.gift_card')}{c.holder_note !== null ? ` · ${c.holder_note}` : ''}</span></span> },
        { id: 'issued', label: t('fo.gc.col.issued'), value: (c) => c.issued_on, cell: (c) => format.date(c.issued_on) },
        { id: 'face', label: t('fo.gc.col.face'), align: 'right', value: (c) => c.face_minor, cell: (c) => money(c.face_minor) },
        { id: 'balance', label: t('fo.gc.col.balance'), align: 'right', value: (c) => c.balance_minor, cell: (c) => money(c.balance_minor) },
        { id: 'expires', label: t('fo.gc.col.expires'), value: (c) => c.expires_on ?? '', cell: (c) => (c.expires_on === null ? '—' : format.date(c.expires_on)) },
        { id: 'state', label: t('fo.gc.col.state'), value: (c) => c.state, filter: 'select', filterLabel: (v) => t(`fo.gc.state.${v}` as 'fo.gc.state.active'), cell: (c) => <StatusBadge label={t(`fo.gc.state.${c.state}` as 'fo.gc.state.active')} tone={TONE[c.state] ?? 'neutral'} /> },
        { id: 'actions', label: '', value: () => '', sortable: false, cell: (c) => (overview.may.void && c.state !== 'void' ? <Button onClick={() => { action.clear(); setVoiding({ card: c, disposition: 'refunded', reason: '' }); }} size="sm" type="button" variant="outline">{t('fo.gc.void')}</Button> : null) },
    ];

    return (
        <FrontOfficeShell description={t('fo.gc.description')} title={t('fo.gc.title')} wide>
            {action.error !== null && sell === null && voiding === null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            <div className="flex flex-wrap items-end gap-3 print:hidden">
                {overview.may.issue ? <Button onClick={() => { action.clear(); setSell({ ...blank }); }} type="button">{t('fo.gc.sell')}</Button> : null}
                <FormField error={undefined} hint={t('fo.gc.lookupHint')} label={t('fo.gc.lookup')}>
                    <span className="flex gap-2"><Input autoComplete="off" maxLength={40} onChange={(e) => setCode(e.target.value)} value={code} /><Button disabled={code.trim() === '' || action.busy} onClick={() => void lookup()} type="button" variant="outline">{t('fo.gc.check')}</Button></span>
                </FormField>
            </div>
            {found !== null ? <Alert title={t('fo.gc.found', { last4: found.last4 })} tone="info"><span data-testid="gift-card-found">{t('fo.gc.foundBody', { balance: money(found.balance_minor), state: t(`fo.gc.state.${found.state}` as 'fo.gc.state.active'), expires: found.expires_on === null ? '—' : format.date(found.expires_on) })}</span></Alert> : null}

            <DataGrid caption={t('fo.gc.title')} columns={columns} empty={<EmptyState title={t('fo.gc.none')} />} getRowId={(c) => c.id} id="fo.gift-cards" rows={overview.cards} testId="gift-cards" />

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setSell(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void issue()} type="button">{t('fo.gc.sell')}</Button></>}
                onClose={() => setSell(null)}
                open={sell !== null}
                title={t('fo.gc.sell')}
            >
                {sell !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <FormField error={action.fieldError('kind')} field="kind" label={t('fo.gc.what')}><Select onChange={(e) => setSell({ ...sell, kind: e.target.value })} value={sell.kind}><option value="gift_card">{t('fo.gc.kind.gift_card')}</option><option value="voucher">{t('fo.gc.kind.voucher')}</option></Select></FormField>
                        <FormField error={action.fieldError('amount_minor')} field="amount_minor" label={t('fo.gc.amount', { currency: overview.currency })}><MoneyInput onChange={(e) => setSell({ ...sell, amount: e.target.value })} value={sell.amount} /></FormField>
                        <FormField error={action.fieldError('payment_method')} field="payment_method" label={t('fo.gc.paidBy')}><Select onChange={(e) => setSell({ ...sell, method: e.target.value })} value={sell.method}>{overview.methods.map((m) => <option key={m} value={m}>{t(`fo.gc.method.${m}` as 'fo.gc.method.cash')}</option>)}</Select></FormField>
                        {sell.method !== 'cash' && sell.method !== 'complimentary' ? <FormField error={action.fieldError('reference')} field="reference" label={t('fo.folio.reference')}><Input maxLength={60} onChange={(e) => setSell({ ...sell, reference: e.target.value })} value={sell.reference} /></FormField> : null}
                        <FormField error={action.fieldError('expires_on')} field="expires_on" hint={t('fo.gc.expiresHint')} label={t('fo.gc.col.expires')}><DatePicker min={overview.today} onChange={(e) => setSell({ ...sell, expires: e.target.value })} value={sell.expires} /></FormField>
                        <FormField error={action.fieldError('holder_note')} field="holder_note" hint={t('fo.gc.noteHint')} label={t('fo.gc.note')}><Input maxLength={120} onChange={(e) => setSell({ ...sell, note: e.target.value })} value={sell.note} /></FormField>
                    </div>
                )}
            </Dialog>

            <Dialog footer={<><Button onClick={() => window.print()} type="button" variant="outline">{t('fo.gc.print')}</Button><Button onClick={() => setMade(null)} type="button">{t('fo.gc.done')}</Button></>} onClose={() => setMade(null)} open={made !== null} title={t('fo.gc.madeTitle')}>
                {made !== null && (
                    <div className="grid gap-3">
                        <p className="text-sm">{t('fo.gc.madeBody', { amount: money(made.card.face_minor), until: made.card.expires_on === null ? '—' : format.date(made.card.expires_on) })}</p>
                        {made.code !== null ? (
                            <>
                                <p className="select-all break-all border border-border bg-muted p-3 text-center font-mono text-2xl font-semibold tracking-wider" data-testid="gift-card-code">{made.code}</p>
                                <Alert title={t('fo.gc.onceTitle')} tone="warning">{t('fo.gc.once')}</Alert>
                            </>
                        ) : <Alert title={t('fo.gc.sentTwice')} tone="warning">{t('fo.gc.sentTwiceBody')}</Alert>}
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setVoiding(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={voiding?.reason.trim() === ''} loading={action.busy} onClick={() => void doVoid()} type="button">{t('fo.gc.void')}</Button></>}
                onClose={() => setVoiding(null)}
                open={voiding !== null}
                title={t('fo.gc.voidTitle')}
            >
                {voiding !== null && (
                    <div className="grid gap-3">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <p className="text-sm">{t('fo.gc.voidBody', { last4: voiding.card.last4, balance: money(voiding.card.balance_minor) })}</p>
                        <FormField error={action.fieldError('disposition')} field="disposition" label={t('fo.gc.rest')}><Select onChange={(e) => setVoiding({ ...voiding, disposition: e.target.value })} value={voiding.disposition}>{overview.dispositions.map((d) => <option key={d} value={d}>{t(`fo.gc.disposition.${d}` as 'fo.gc.disposition.refunded')}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('reason')} field="reason" label={t('fo.lock.reason')}><Input maxLength={200} onChange={(e) => setVoiding({ ...voiding, reason: e.target.value })} value={voiding.reason} /></FormField>
                    </div>
                )}
            </Dialog>
        </FrontOfficeShell>
    );
}
