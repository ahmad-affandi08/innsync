import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import type { BillLine, RearrangeTargets } from '@/modules/fnb-sales/lib/fnb';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Mode = 'move' | 'merge' | 'split';
type Props = { bill: { id: string; number: string; covers: number; lock_version: number; lines: BillLine[] }; targets: RearrangeTargets; lineTitle: (line: BillLine) => string; money: (minor: number) => string };

/** Moving a bill to another table, merging it into another bill, or splitting some of its lines onto a new bill that is paid apart. */
export function BillRearrange({ bill, lineTitle, money, targets }: Props) {
    const { t } = useTranslation();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [mode, setMode] = useState<Mode | null>(null);
    const [table, setTable] = useState('');
    const [target, setTarget] = useState('');
    const [covers, setCovers] = useState('1');
    const [picked, setPicked] = useState<Record<string, string>>({});
    const [pickError, setPickError] = useState(false);
    const [created, setCreated] = useState<string | null>(null);
    const reload = ['view', 'targets'];
    const live = bill.lines.filter((l) => l.status === 'pending' || l.status === 'sent');
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const place = (b: { table: string | null }) => (b.table !== null ? t('fnb.pos.tableLabel', { code: b.table }) : t('fnb.pos.counter'));

    function start(next: Mode) {
        action.clear();
        setTable('');
        setTarget('');
        setCovers('1');
        setPicked({});
        setPickError(false);
        setMode(next);
    }

    async function move() {
        const done = await action.run(`/fnb/bills/${bill.id}/table`, { idempotencyKey: newIdempotencyKey(), body: { lock_version: bill.lock_version, table_id: table }, reload });
        if (done !== null) setMode(null);
    }

    async function merge() {
        const other = targets.bills.find((b) => b.id === target);
        if (other === undefined) return;
        const done = await action.run(`/fnb/bills/${bill.id}/merge`, { idempotencyKey: newIdempotencyKey(), body: { lock_version: bill.lock_version, target_id: other.id, target_lock_version: other.lock_version } });
        if (done !== null) router.visit(`/fnb/bills/${other.id}`);
    }

    async function split() {
        const lines = Object.entries(picked).map(([line_id, quantity]) => ({ line_id, quantity: quantity === '' ? null : Number(quantity) }));

        if (lines.length === 0) {
            setPickError(true);

            return;
        }

        setPickError(false);
        const done = await action.run<{ new_bill_id: string }>(`/fnb/bills/${bill.id}/split`, {
            idempotencyKey: newIdempotencyKey(), reload,
            body: { lock_version: bill.lock_version, lines, table_id: table === '' ? null : table, covers: Number(covers) },
        });

        if (done !== null) {
            setCreated((done as unknown as { new_bill_id: string }).new_bill_id);
            setMode(null);
        }
    }

    function toggle(line: BillLine, on: boolean) {
        const next = { ...picked };
        if (on) next[line.id] = '';
        else delete next[line.id];
        setPicked(next);
    }

    if (live.length === 0) return null;

    return (
        <div className="flex flex-col gap-2 border-t border-border pt-3" data-testid="bill-rearrange">
            <h3 className="font-semibold">{t('fnb.rg.title')}</h3>
            <div className="flex flex-wrap gap-2">
                <Button disabled={action.busy} onClick={() => start('move')} size="sm" type="button" variant="outline">{t('fnb.rg.move')}</Button>
                <Button disabled={action.busy} onClick={() => start('merge')} size="sm" type="button" variant="outline">{t('fnb.rg.merge')}</Button>
                <Button disabled={action.busy} onClick={() => start('split')} size="sm" type="button" variant="outline">{t('fnb.rg.split')}</Button>
            </div>
            {created !== null ? <Alert actions={<Button onClick={() => router.visit(`/fnb/bills/${created}`)} size="sm" type="button" variant="outline">{t('fnb.rg.openNew')}</Button>} title={t('fnb.rg.splitDone')} tone="success" /> : null}
            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setMode(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    {mode === 'move' ? <Button disabled={table === ''} loading={action.busy} onClick={() => void move()} type="button">{t('fnb.rg.moveConfirm')}</Button> : null}
                    {mode === 'merge' ? <Button disabled={target === ''} loading={action.busy} onClick={() => void merge()} type="button">{t('fnb.rg.mergeConfirm')}</Button> : null}
                    {mode === 'split' ? <Button loading={action.busy} onClick={() => void split()} type="button">{t('fnb.rg.splitConfirm')}</Button> : null}
                </>}
                onClose={() => setMode(null)}
                open={mode !== null}
                title={mode === 'move' ? t('fnb.rg.moveTitle', { number: bill.number }) : mode === 'merge' ? t('fnb.rg.mergeTitle', { number: bill.number }) : t('fnb.rg.splitTitle', { number: bill.number })}
            >
                <div className="flex flex-col gap-3">
                    {failure}
                    {mode === 'move' ? (
                        <>
                            <p className="text-sm text-muted-foreground">{t('fnb.rg.moveHint')}</p>
                            {targets.tables.length === 0 ? <Alert title={t('fnb.rg.noTables')} tone="info" /> : (
                                <FormField error={action.fieldError('table_id')} field="table_id" label={t('fnb.rg.table')}>
                                    <Select onChange={(e) => setTable(e.target.value)} value={table}>
                                        <option value="" />
                                        {targets.tables.map((x) => <option key={x.id} value={x.id}>{x.code}</option>)}
                                    </Select>
                                </FormField>
                            )}
                        </>
                    ) : null}
                    {mode === 'merge' ? (
                        <>
                            <p className="text-sm text-muted-foreground">{t('fnb.rg.mergeHint')}</p>
                            {targets.bills.length === 0 ? <Alert title={t('fnb.rg.noBills')} tone="info" /> : (
                                <FormField error={action.fieldError('target_id')} field="target_id" label={t('fnb.rg.target')}>
                                    <Select onChange={(e) => setTarget(e.target.value)} value={target}>
                                        <option value="" />
                                        {targets.bills.map((b) => <option key={b.id} value={b.id}>{b.number} · {place(b)}</option>)}
                                    </Select>
                                </FormField>
                            )}
                        </>
                    ) : null}
                    {mode === 'split' ? (
                        <>
                            <p className="text-sm text-muted-foreground">{t('fnb.rg.splitHint')}</p>
                            {pickError ? <Alert title={t('fnb.rg.pickSomething')} tone="warning" /> : null}
                            <ul className="flex flex-col divide-y divide-border" data-testid="split-lines">
                                {live.map((l) => (
                                    <li className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm" key={l.id}>
                                        <label className="flex items-center gap-2"><input checked={picked[l.id] !== undefined} onChange={(e) => toggle(l, e.target.checked)} type="checkbox" />{lineTitle(l)} <span className="tabular-nums text-muted-foreground">{money(l.line_total_minor)}</span></label>
                                        {picked[l.id] !== undefined && l.quantity > 1 && l.discount_minor === 0 ? (
                                            <Input aria-label={t('fnb.rg.portions', { max: l.quantity })} className="w-24" inputMode="numeric" onChange={(e) => setPicked({ ...picked, [l.id]: e.target.value.replace(/\D/g, '') })} placeholder={t('fnb.rg.all')} value={picked[l.id]} />
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <FormField error={action.fieldError('table_id')} field="table_id" label={t('fnb.rg.newTable')}>
                                    <Select onChange={(e) => setTable(e.target.value)} value={table}>
                                        <option value="">{t('fnb.rg.noTable')}</option>
                                        {targets.tables.map((x) => <option key={x.id} value={x.id}>{x.code}</option>)}
                                    </Select>
                                </FormField>
                                <FormField error={action.fieldError('covers')} field="covers" label={t('fnb.rg.covers')}>
                                    <Input inputMode="numeric" onChange={(e) => setCovers(e.target.value.replace(/\D/g, ''))} value={covers} />
                                </FormField>
                            </div>
                        </>
                    ) : null}
                </div>
            </Dialog>
        </div>
    );
}
