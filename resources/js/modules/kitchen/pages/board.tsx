import { useCallback, useEffect, useRef, useState } from 'react';

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
import { KitchenShell } from '@/modules/kitchen/components/kitchen-shell';
import type { Board, BoardPageProps, KitchenSettings, SoldOutItem, Ticket, TicketAction } from '@/modules/kitchen/lib/kitchen';
import { apiRequest, newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

const REFRESH_MS = 15_000;
const NEXT: Record<'new' | 'preparing' | 'ready', { action: TicketAction; label: MessageKey }> = {
    new: { action: 'start', label: 'kitchen.action.start' },
    preparing: { action: 'ready', label: 'kitchen.action.ready' },
    ready: { action: 'serve', label: 'kitchen.action.serve' },
};
const COLUMNS = ['new', 'preparing', 'ready'] as const;

/** The screen of the kitchen and the bar: tickets by step with the time they have waited, reloaded by itself, and the dishes that ran out. */
export default function KitchenBoardPage(props: BoardPageProps) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [board, setBoard] = useState<Board>(props.board);
    const [items, setItems] = useState<SoldOutItem[]>(props.sold_out.items);
    const [settings, setSettings] = useState<KitchenSettings>(props.settings);
    const [tab, setTab] = useState<string>(props.board.station);
    const [offline, setOffline] = useState(false);
    const [dialog, setDialog] = useState(false);
    const [form, setForm] = useState({ minutes: String(props.settings.late_after_minutes), location: props.settings.stock_location_id ?? '', reason: '' });
    const [badMinutes, setBadMinutes] = useState(false);
    const [saved, setSaved] = useState(false);
    const skew = useRef(new Date(props.board.loaded_at).getTime() - Date.now());
    const [now, setNow] = useState(() => Date.now() + skew.current);
    const station = tab === 'sold-out' ? board.station : (tab as Board['station']);

    const load = useCallback(async (which: string) => {
        try {
            const done = await apiRequest<{ board: Board }>('/kitchen/tickets', { method: 'GET', query: { station: which } });

            skew.current = new Date(done.board.loaded_at).getTime() - Date.now();
            setBoard(done.board);
            setOffline(false);
        } catch {
            setOffline(true);
        }
    }, []);

    // The screen looks again by itself, and shows how old it is when the network fails (FR-KIT-015).
    useEffect(() => {
        const refresh = window.setInterval(() => void load(station), REFRESH_MS);

        return () => window.clearInterval(refresh);
    }, [load, station]);
    useEffect(() => {
        const tick = window.setInterval(() => setNow(Date.now() + skew.current), 1000);

        return () => window.clearInterval(tick);
    }, []);

    const waited = (tk: Ticket): number => (tk.status === 'served' ? tk.waiting_seconds : Math.max(0, Math.floor((now - new Date(tk.received_at).getTime()) / 1000)));
    const clock = (seconds: number) => `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
    const place = (tk: Ticket) => (tk.place_kind === 'table' ? t('kitchen.place.table', { place: tk.place ?? '' }) : tk.place_kind === 'room' ? t('kitchen.place.room', { place: tk.place ?? '' }) : t('kitchen.place.counter'));
    const isLate = (tk: Ticket) => (tk.status === 'new' || tk.status === 'preparing') && waited(tk) >= board.late_after_minutes * 60;
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;

    function pickStation(value: string) {
        setTab(value);
        if (value !== 'sold-out' && value !== board.station) void load(value);
    }

    async function move(tk: Ticket, step: TicketAction) {
        const done = await action.run(`/kitchen/tickets/${tk.id}/advance`, { idempotencyKey: newIdempotencyKey(), body: { action: step, lock_version: tk.lock_version } });

        void done;
        await load(station);
    }

    async function mark(item: SoldOutItem, available: boolean) {
        const done = await action.run<{ items: SoldOutItem[] }>(`/kitchen/items/${item.id}/availability`, { body: { available } });

        if (done !== null) setItems(done.items);
    }

    async function save() {
        const minutes = Number(form.minutes);
        const bad = !Number.isInteger(minutes) || minutes < 1 || minutes > 240;

        setBadMinutes(bad);
        if (bad) return;
        const done = await action.run<{ settings: KitchenSettings }>('/kitchen/settings', { body: { late_after_minutes: minutes, stock_location_id: form.location === '' ? null : form.location, reason: form.reason.trim(), lock_version: settings.lock_version } });

        if (done !== null) {
            setSettings(done.settings);
            setDialog(false);
            setSaved(true);
            setForm({ minutes: String(done.settings.late_after_minutes), location: done.settings.stock_location_id ?? '', reason: '' });
            await load(station);
        }
    }

    const card = (tk: Ticket) => {
        const next = tk.status === 'new' || tk.status === 'preparing' || tk.status === 'ready' ? NEXT[tk.status] : null;
        const late = isLate(tk);

        return (
            <li className={`flex flex-col gap-2 border bg-surface p-3 ${late ? 'border-danger border-l-4' : 'border-border'}`} data-testid="kitchen-ticket" key={tk.id}>
                <div className="flex items-start justify-between gap-2">
                    <span className="text-lg font-semibold">{place(tk)}</span>
                    <span className="flex flex-wrap items-center justify-end gap-1">
                        {late ? <StatusBadge label={t('kitchen.ticket.late')} tone="danger" /> : null}
                        <span className="whitespace-nowrap text-sm tabular-nums text-muted-foreground">{t('kitchen.ticket.waiting', { time: clock(waited(tk)) })}</span>
                    </span>
                </div>
                <p className="text-xs text-muted-foreground">{t('kitchen.ticket.meta', { bill: tk.bill_number, batch: tk.batch_number, outlet: tk.outlet_code })}</p>
                <ul className="flex flex-col gap-1 text-sm">
                    {tk.lines.map((l) => (
                        <li className={l.cancelled ? 'text-muted-foreground line-through' : ''} key={l.id}>
                            <span className="font-medium">{l.quantity} × {l.name}{l.variant !== null ? ` (${l.variant})` : ''}</span>
                            {l.cancelled ? <span className="ml-2 text-xs">{t('kitchen.ticket.cancelledLine')}</span> : null}
                            {l.modifiers.length > 0 ? <span className="block text-xs text-muted-foreground">{l.modifiers.join(', ')}</span> : null}
                            {l.note !== null ? <span className="block text-xs text-muted-foreground">“{l.note}”</span> : null}
                        </li>
                    ))}
                </ul>
                {next !== null ? <div><Button disabled={action.busy} onClick={() => void move(tk, next.action)} size="sm" type="button">{t(next.label)}</Button></div> : null}
            </li>
        );
    };

    const boardPanel = (
        <div className="flex flex-col gap-4">
            {failure}
            {board.tickets.length === 0 ? <EmptyState illustration="coffee" title={t('kitchen.empty.board')} /> : null}
            <div className="grid gap-4 lg:grid-cols-3" data-testid="kitchen-board">
                {COLUMNS.map((c) => {
                    const list = board.tickets.filter((tk) => tk.status === c);

                    return (
                        <section aria-labelledby={`kitchen-col-${c}`} className="flex flex-col gap-3 bg-surface-muted p-3" key={c}>
                            <h2 className="flex items-center justify-between text-sm font-semibold uppercase tracking-wide" id={`kitchen-col-${c}`}>{t(`kitchen.col.${c}` as MessageKey)}<span className="tabular-nums">{list.length}</span></h2>
                            {list.length === 0 ? <p className="text-sm text-muted-foreground">{t('kitchen.empty.column')}</p> : <ul className="flex flex-col gap-3">{list.map(card)}</ul>}
                        </section>
                    );
                })}
            </div>
            <section aria-labelledby="kitchen-served-h" className="flex flex-col gap-2">
                <h2 className="text-sm font-semibold" id="kitchen-served-h">{t('kitchen.served.title')}</h2>
                {board.served.length === 0 ? <p className="text-sm text-muted-foreground">{t('kitchen.served.empty')}</p> : (
                    <ul className="flex flex-col divide-y divide-border border border-border bg-surface text-sm" data-testid="kitchen-served">
                        {board.served.map((tk) => <li className="px-3 py-2" key={tk.id}>{t('kitchen.served.meta', { place: place(tk), bill: tk.bill_number, time: clock(tk.waiting_seconds) })} · {tk.lines.filter((l) => !l.cancelled).map((l) => `${l.quantity} × ${l.name}`).join(', ')}</li>)}
                    </ul>
                )}
            </section>
        </div>
    );

    const itemColumns: DataGridColumn<SoldOutItem>[] = [
        { id: 'item', label: t('kitchen.soldOut.item'), value: (i) => i.name, rowHeader: true },
        { id: 'category', label: t('kitchen.soldOut.category'), value: (i) => i.category, filter: 'select' },
        { id: 'outlet', label: t('kitchen.soldOut.outlet'), value: (i) => i.outlet, filter: 'select' },
        { id: 'status', label: t('kitchen.soldOut.status'), value: (i) => (i.is_available ? 'on' : 'out'), filter: 'select', filterLabel: (v) => t(v === 'on' ? 'kitchen.soldOut.onSale' : 'kitchen.soldOut.soldOut'), cell: (i) => <StatusBadge label={t(i.is_available ? 'kitchen.soldOut.onSale' : 'kitchen.soldOut.soldOut')} tone={i.is_available ? 'success' : 'danger'} /> },
        { id: 'act', label: '', value: () => '', sortable: false, cell: (i) => <Button disabled={action.busy} onClick={() => void mark(i, !i.is_available)} size="sm" type="button" variant="outline">{t(i.is_available ? 'kitchen.soldOut.markOut' : 'kitchen.soldOut.putBack')}</Button> },
    ];

    const stamp = format.instant(board.loaded_at);

    return (
        <KitchenShell
            actions={board.may.settings ? <Button onClick={() => { setSaved(false); setDialog(true); }} type="button" variant="outline">{t('kitchen.settings.open')}</Button> : undefined}
            description={t('kitchen.description')}
            title={t('kitchen.title')}
        >
            {saved ? <Alert title={t('kitchen.settings.saved')} tone="success" /> : null}
            {offline ? <Alert title={t('kitchen.live.offline', { time: stamp })} tone="warning" /> : <p className="text-xs text-muted-foreground" data-testid="kitchen-updated">{t('kitchen.live.updated', { time: stamp })}</p>}
            <Tabs onValueChange={pickStation} value={tab}>
                <TabsList aria-label={t('kitchen.title')}>
                    {board.stations.map((s) => <TabsTrigger key={s.key} value={s.key}>{t(`kitchen.station.${s.key}` as MessageKey)} ({s.open})</TabsTrigger>)}
                    <TabsTrigger value="sold-out">{t('kitchen.tab.soldOut')}</TabsTrigger>
                </TabsList>
                {board.stations.map((s) => <TabsContent className="flex flex-col gap-3" key={s.key} value={s.key}>{tab === s.key ? boardPanel : null}</TabsContent>)}
                <TabsContent className="flex flex-col gap-3" value="sold-out">
                    <p className="text-sm text-muted-foreground">{t('kitchen.soldOut.hint')}</p>
                    {tab === 'sold-out' ? failure : null}
                    <DataGrid caption={t('kitchen.soldOut.title')} columns={itemColumns} empty={<EmptyState illustration="bell" title={t('kitchen.soldOut.empty')} />} getRowId={(i) => i.id} id="kitchen.soldout" rows={items} testId="kitchen-soldout" />
                </TabsContent>
            </Tabs>

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setDialog(false)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button disabled={form.reason.trim() === ''} loading={action.busy} onClick={() => void save()} type="button">{t('kitchen.settings.save')}</Button>
                </>}
                onClose={() => setDialog(false)}
                open={dialog}
                title={t('kitchen.settings.title')}
            >
                <div className="flex flex-col gap-4">
                    {failure}
                    <FormField error={badMinutes ? t('kitchen.settings.badMinutes') : action.fieldError('late_after_minutes')} field="late_after_minutes" hint={t('kitchen.settings.lateHint')} label={t('kitchen.settings.late')}>
                        <Input inputMode="numeric" onChange={(e) => setForm({ ...form, minutes: e.target.value })} value={form.minutes} />
                    </FormField>
                    <FormField error={action.fieldError('stock_location_id')} field="stock_location_id" hint={t('kitchen.settings.locationHint')} label={t('kitchen.settings.location')}>
                        <Select onChange={(e) => setForm({ ...form, location: e.target.value })} value={form.location}>
                            <option value="">{t('kitchen.settings.noLocation')}</option>
                            {settings.locations.map((l) => <option key={l.id} value={l.id}>{l.name} ({l.code})</option>)}
                        </Select>
                    </FormField>
                    <FormField error={action.fieldError('reason')} field="reason" label={t('kitchen.settings.reason')}>
                        <Input maxLength={200} onChange={(e) => setForm({ ...form, reason: e.target.value })} value={form.reason} />
                    </FormField>
                </div>
            </Dialog>
        </KitchenShell>
    );
}
