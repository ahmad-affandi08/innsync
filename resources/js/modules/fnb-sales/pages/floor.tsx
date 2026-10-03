import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { FnbShell } from '@/modules/fnb-sales/components/fnb-shell';
import type { Floor, FloorBill, FloorTable } from '@/modules/fnb-sales/lib/fnb';
import { useServerAction } from '@/shared/api/use-server-action';
import { newIdempotencyKey } from '@/shared/api/http';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

type Start = { table: FloorTable | null; room: string; covers: string; note: string; kind: 'table' | 'room' | 'counter' };

const TONE = { free: 'neutral', occupied: 'info', ordered: 'warning' } as const;

/** The tables of an outlet with who is at them, and the bills that are open. A bill is opened from a table, a room or the counter. */
export default function FloorPage({ floor }: { floor: Floor }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [start, setStart] = useState<Start | null>(null);
    const outlet = floor.outlet;
    const money = (minor: number) => format.money(minor, floor.currency);

    function pickOutlet(id: string) {
        router.get('/fnb/pos', { outlet: id }, { preserveScroll: true });
    }

    function openFor(kind: Start['kind'], table: FloorTable | null = null) {
        action.clear();
        setStart({ kind, table, room: '', covers: String(table === null ? 1 : Math.min(table.seats, 2)), note: '' });
    }

    async function open() {
        if (start === null || outlet === null) return;
        const covers = Number.parseInt(start.covers, 10);
        const done = await action.run<{ bill: { id: string } }>('/fnb/bills', {
            idempotencyKey: newIdempotencyKey(),
            body: { outlet_id: outlet.id, table_id: start.table?.id ?? null, room_id: start.kind === 'room' && start.room !== '' ? start.room : null, covers: Number.isNaN(covers) ? 0 : covers, note: start.note.trim() === '' ? null : start.note.trim() },
        });
        if (done !== null) router.visit(`/fnb/bills/${done.bill.id}`);
    }

    const place = (b: FloorBill) => (b.table !== null ? t('fnb.pos.tableLabel', { code: b.table }) : b.room !== null ? t('fnb.pos.roomLabel', { number: b.room }) : t('fnb.pos.counter'));
    const columns: DataGridColumn<FloorBill>[] = [
        { id: 'number', label: t('fnb.pos.colBill'), value: (b) => b.number, rowHeader: true, cell: (b) => (b.source === 'qr' ? <span>{b.number} <StatusBadge label={t('fnb.pos.sourceQr')} tone="info" /></span> : b.number) },
        { id: 'place', label: t('fnb.pos.colPlace'), value: place },
        { id: 'covers', label: t('fnb.pos.colGuests'), align: 'right', value: (b) => b.covers },
        { id: 'lines', label: t('fnb.pos.colLines'), align: 'right', value: (b) => b.lines },
        { id: 'sent', label: t('fnb.pos.colSent'), value: (b) => (b.sent ? 'yes' : 'no'), filter: 'select', filterLabel: (v) => t(v === 'yes' ? 'fnb.pos.yes' : 'fnb.pos.no') },
        { id: 'total', label: t('fnb.pos.colTotal'), align: 'right', value: (b) => b.subtotal_minor, cell: (b) => money(b.subtotal_minor) },
        { id: 'opened', label: t('fnb.pos.openBill'), cell: (b) => <Button asChild size="sm" variant="outline"><Link href={`/fnb/bills/${b.id}`}>{t('fnb.pos.openBill')}</Link></Button> },
    ];

    return (
        <FnbShell
            actions={outlet !== null && floor.may.operate ? <><Button onClick={() => openFor('room')} type="button" variant="outline">{t('fnb.pos.openRoom')}</Button><Button onClick={() => openFor('counter')} type="button" variant="outline">{t('fnb.pos.openCounter')}</Button></> : undefined}
            description={t('fnb.pos.description')}
            title={t('fnb.pos.title')}
            wide
        >
            <div className="flex flex-wrap items-end gap-4 border border-border bg-surface p-4">
                <FormField label={t('fnb.pos.outlet')}>
                    <Select disabled={floor.outlets.length === 0} onChange={(e) => pickOutlet(e.target.value)} value={outlet?.id ?? ''}>
                        {floor.outlets.map((o) => <option key={o.id} value={o.id}>{o.name} ({o.code})</option>)}
                    </Select>
                </FormField>
            </div>

            {outlet === null ? <Alert title={t('fnb.pos.noOutlet')} tone="info" /> : (
                <Tabs defaultValue="tables">
                    <TabsList aria-label={t('fnb.pos.title')}>
                        <TabsTrigger value="tables">{t('fnb.pos.tabTables', { count: floor.tables.length })}</TabsTrigger>
                        <TabsTrigger value="bills">{t('fnb.pos.tabBills', { count: floor.bills.length })}</TabsTrigger>
                    </TabsList>

                    <TabsContent className="flex flex-col gap-3" value="tables">
                        {floor.tables.length === 0 ? <EmptyState illustration="armchair" title={t('fnb.pos.noTables')} /> : (
                            <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6" data-testid="floor-tables">
                                {floor.tables.map((tb) => {
                                    const body = (
                                        <>
                                            <span className="flex items-center justify-between gap-2">
                                                <span className="text-lg font-semibold">{tb.code}</span>
                                                <StatusBadge label={t(`fnb.pos.status.${tb.status}` as MessageKey)} tone={TONE[tb.status]} />
                                            </span>
                                            <span className="text-sm text-muted-foreground">{tb.area !== null ? `${tb.area} · ` : ''}{t('fnb.pos.seats', { count: tb.seats })}</span>
                                            {tb.bill_number !== null ? <span className="text-sm font-medium">{tb.bill_number} · {money(tb.subtotal_minor)}</span> : <span className="text-sm text-muted-foreground">{floor.may.operate ? t('fnb.pos.open') : '—'}</span>}
                                            {tb.ready_lines > 0 ? <StatusBadge label={t('fnb.pos.readyLines', { count: tb.ready_lines })} tone="success" /> : null}
                                        </>
                                    );
                                    const cls = 'flex h-full min-h-28 w-full flex-col justify-between gap-2 border border-border bg-surface p-4 text-left transition-colors hover:border-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';

                                    return (
                                        <li key={tb.id}>
                                            {tb.bill_id !== null
                                                ? <Link className={`${cls} border-l-4 border-l-brand`} href={`/fnb/bills/${tb.bill_id}`}>{body}</Link>
                                                : <button className={cls} disabled={!floor.may.operate} onClick={() => openFor('table', tb)} type="button">{body}</button>}
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </TabsContent>

                    <TabsContent className="flex flex-col gap-3" value="bills">
                        <DataGrid caption={t('fnb.pos.tabBills', { count: floor.bills.length })} columns={columns} empty={<EmptyState illustration="bell" title={t('fnb.pos.noBills')} />} getRowId={(b) => b.id} id="fnb.pos.bills" rows={floor.bills} testId="floor-bills" />
                    </TabsContent>
                </Tabs>
            )}

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setStart(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void open()} type="button">{t('fnb.pos.start')}</Button>
                </>}
                onClose={() => setStart(null)}
                open={start !== null}
                title={start === null ? '' : start.kind === 'table' ? t('fnb.pos.openTable', { code: start.table?.code ?? '' }) : start.kind === 'room' ? t('fnb.pos.openRoom') : t('fnb.pos.openCounter')}
            >
                {start !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        {start.kind === 'room' ? (
                            <FormField error={action.fieldError('room_id')} field="room_id" hint={t('fnb.pos.roomHint')} label={t('fnb.pos.room')}>
                                <Select onChange={(e) => setStart({ ...start, room: e.target.value })} value={start.room}>
                                    <option value="">{t('fnb.pos.roomNone')}</option>
                                    {floor.rooms.map((r) => <option key={r.id} value={r.id}>{r.number}</option>)}
                                </Select>
                            </FormField>
                        ) : null}
                        <FormField error={action.fieldError('covers')} field="covers" label={t('fnb.pos.covers')}>
                            <Input inputMode="numeric" onChange={(e) => setStart({ ...start, covers: e.target.value })} value={start.covers} />
                        </FormField>
                        <div className="sm:col-span-2">
                            <FormField error={action.fieldError('note')} field="note" label={t('fnb.pos.note')}>
                                <Input maxLength={200} onChange={(e) => setStart({ ...start, note: e.target.value })} value={start.note} />
                            </FormField>
                        </div>
                    </div>
                )}
            </Dialog>
        </FnbShell>
    );
}
