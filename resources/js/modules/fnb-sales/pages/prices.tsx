import { router } from '@inertiajs/react';
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
import { TimeInput } from '@/components/ui/time-input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { FnbShell } from '@/modules/fnb-sales/components/fnb-shell';
import type { PriceOverview, PriceRule } from '@/modules/fnb-sales/lib/fnb';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';
import type { MessageKey } from '@/locales/en/index';

type Form = { item: string; variant: string; channel: PriceRule['channel']; kind: PriceRule['kind']; name: string; price: string; from: string; to: string; days: number; fromTime: string; toTime: string };

const DAYS = [1, 2, 4, 8, 16, 32, 64] as const;

/** The price lists and scheduled promotions of an outlet, and what each dish costs right now by channel. A price is never edited: retire it and add another. */
export default function PricesPage({ overview }: { overview: PriceOverview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<Form | null>(null);
    const [retire, setRetire] = useState<{ rule: PriceRule; reason: string } | null>(null);
    const money = (minor: number) => format.money(minor, overview.currency);
    const outlet = overview.outlet;
    const reload = ['overview'];
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const item = form === null ? undefined : overview.items.find((i) => i.id === form.item);

    function start() {
        action.clear();
        setForm({ item: overview.items[0]?.id ?? '', variant: '', channel: 'all', kind: 'price', name: '', price: '', from: format.calendarDateOf(new Date()), to: '', days: 127, fromTime: '', toTime: '' });
    }

    async function save() {
        if (form === null || outlet === null) return;
        const done = await action.run('/fnb/prices', {
            reload,
            body: {
                outlet_id: outlet.id, item_id: form.item, variant_id: form.variant === '' ? null : form.variant, channel: form.channel, kind: form.kind, name: form.name.trim(), price_minor: parseMajorToMinor(form.price, overview.currency) ?? 0,
                valid_from: form.from, valid_to: form.to === '' ? null : form.to, days: form.days, from_time: form.fromTime === '' ? null : form.fromTime, to_time: form.toTime === '' ? null : form.toTime,
            },
        });
        if (done !== null) setForm(null);
    }

    async function submitRetire() {
        if (retire === null) return;
        const done = await action.run(`/fnb/prices/${retire.rule.id}/retire`, { body: { reason: retire.reason.trim() }, reload });
        if (done !== null) setRetire(null);
    }

    const schedule = (r: PriceRule): string => {
        const days = r.days === 127 ? t('fnb.px.everyDay') : DAYS.filter((d) => (r.days & d) !== 0).map((d) => t(`fnb.px.day.${d}` as MessageKey)).join(', ');
        const hours = r.from_time !== null && r.to_time !== null ? ` · ${r.from_time}–${r.to_time}` : '';
        const dates = r.valid_to === null ? t('fnb.px.from', { from: format.date(r.valid_from) }) : `${format.date(r.valid_from)} – ${format.date(r.valid_to)}`;

        return `${dates} · ${days}${hours}`;
    };

    const columns: DataGridColumn<PriceRule>[] = [
        { id: 'item', label: t('fnb.px.item'), value: (r) => r.item, rowHeader: true, cell: (r) => <span>{r.item}{r.variant !== null ? ` (${r.variant})` : ''}<span className="block text-xs text-muted-foreground">{r.name}</span></span> },
        { id: 'kind', label: t('fnb.px.kind'), value: (r) => r.kind, cell: (r) => t(`fnb.px.kind.${r.kind}` as MessageKey) },
        { id: 'channel', label: t('fnb.px.channel'), value: (r) => r.channel, cell: (r) => t(`fnb.px.channel.${r.channel}` as MessageKey) },
        { id: 'price', label: t('fnb.px.price'), align: 'right', value: (r) => r.price_minor, cell: (r) => money(r.price_minor) },
        { id: 'when', label: t('fnb.px.when'), value: (r) => r.valid_from, sortable: false, cell: (r) => schedule(r) },
        {
            id: 'status', label: t('fnb.px.status'), value: (r) => (r.is_active ? (r.holds_now ? 'now' : 'active') : 'retired'),
            cell: (r) => (r.is_active ? <StatusBadge label={r.holds_now ? t('fnb.px.holdsNow') : t('fnb.px.active')} tone={r.holds_now ? 'success' : 'info'} /> : <span><StatusBadge label={t('fnb.px.retired')} tone="neutral" /><span className="block text-xs text-muted-foreground">{r.retire_reason}</span></span>),
        },
        { id: 'act', label: '', value: () => '', sortable: false, cell: (r) => (overview.may.manage && r.is_active ? <Button disabled={action.busy} onClick={() => { action.clear(); setRetire({ rule: r, reason: '' }); }} size="sm" type="button" variant="outline">{t('fnb.px.retire')}</Button> : null) },
    ];
    const nowColumns: DataGridColumn<PriceOverview['items'][number]>[] = [
        { id: 'item', label: t('fnb.px.item'), value: (i) => i.code, rowHeader: true, cell: (i) => `${i.code} · ${i.name}` },
        { id: 'list', label: t('fnb.px.menuPrice'), align: 'right', value: (i) => i.price_minor, cell: (i) => money(i.price_minor) },
        { id: 'dine', label: t('fnb.px.channel.dine_in'), align: 'right', value: (i) => i.now.dine_in, cell: (i) => <span className={i.now.dine_in !== i.price_minor ? 'font-semibold' : ''}>{money(i.now.dine_in)}</span> },
        { id: 'room', label: t('fnb.px.channel.room_service'), align: 'right', value: (i) => i.now.room_service, cell: (i) => <span className={i.now.room_service !== i.price_minor ? 'font-semibold' : ''}>{money(i.now.room_service)}</span> },
        { id: 'take', label: t('fnb.px.channel.takeaway'), align: 'right', value: (i) => i.now.takeaway, cell: (i) => <span className={i.now.takeaway !== i.price_minor ? 'font-semibold' : ''}>{money(i.now.takeaway)}</span> },
    ];

    return (
        <FnbShell actions={outlet !== null && overview.may.manage ? <Button onClick={start} type="button">{t('fnb.px.add')}</Button> : undefined} description={t('fnb.px.description')} title={t('fnb.px.title')} wide>
            {action.error !== null && form === null && retire === null ? failure : null}
            {overview.outlets.length > 1 ? (
                <FormField label={t('fnb.px.outlet')}>
                    <Select onChange={(e) => router.get('/fnb/prices', { outlet: e.target.value }, { preserveScroll: true })} value={outlet?.id ?? ''}>
                        {overview.outlets.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                    </Select>
                </FormField>
            ) : null}
            {outlet === null ? <EmptyState illustration="checklist" title={t('fnb.px.noOutlet')} /> : (
                <Tabs defaultValue="rules">
                    <TabsList aria-label={t('fnb.px.title')}>
                        <TabsTrigger value="rules">{t('fnb.px.rulesTab')}</TabsTrigger>
                        <TabsTrigger value="now">{t('fnb.px.nowTab')}</TabsTrigger>
                    </TabsList>
                    <TabsContent className="flex flex-col gap-3" value="rules">
                        <p className="text-sm text-muted-foreground">{t('fnb.px.hint')}</p>
                        <DataGrid caption={t('fnb.px.rulesTab')} columns={columns} empty={<EmptyState illustration="checklist" title={t('fnb.px.none')} />} getRowId={(r) => r.id} id="fnb.px.rules" rows={overview.rules} testId="fnb-price-rules" />
                    </TabsContent>
                    <TabsContent className="flex flex-col gap-3" value="now">
                        <DataGrid caption={t('fnb.px.nowTab')} columns={nowColumns} empty={<EmptyState illustration="checklist" title={t('fnb.px.noItems')} />} getRowId={(i) => i.id} id="fnb.px.now" rows={overview.items} testId="fnb-price-now" />
                    </TabsContent>
                </Tabs>
            )}

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={form === null || form.name.trim() === '' || parseMajorToMinor(form.price, overview.currency) === null || form.days === 0} loading={action.busy} onClick={() => void save()} type="button">{t('fnb.px.save')}</Button></>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={t('fnb.px.add')}
            >
                {form !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {failure !== null ? <div className="sm:col-span-2">{failure}</div> : null}
                        <FormField error={action.fieldError('item_id')} field="item_id" label={t('fnb.px.item')}>
                            <Select onChange={(e) => setForm({ ...form, item: e.target.value, variant: '' })} value={form.item}>
                                {overview.items.map((i) => <option key={i.id} value={i.id}>{i.code} · {i.name} ({money(i.price_minor)})</option>)}
                            </Select>
                        </FormField>
                        <FormField error={action.fieldError('variant_id')} field="variant_id" label={t('fnb.px.variant')}>
                            <Select disabled={(item?.variants.length ?? 0) === 0} onChange={(e) => setForm({ ...form, variant: e.target.value })} value={form.variant}>
                                <option value="">{t('fnb.px.allVariants')}</option>
                                {(item?.variants ?? []).map((v) => <option key={v.id} value={v.id}>{v.name} ({money(v.price_minor)})</option>)}
                            </Select>
                        </FormField>
                        <FormField error={action.fieldError('kind')} field="kind" label={t('fnb.px.kind')}>
                            <Select onChange={(e) => setForm({ ...form, kind: e.target.value as Form['kind'] })} value={form.kind}>
                                {(['price', 'promo'] as const).map((k) => <option key={k} value={k}>{t(`fnb.px.kind.${k}` as MessageKey)}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={action.fieldError('channel')} field="channel" label={t('fnb.px.channel')}>
                            <Select onChange={(e) => setForm({ ...form, channel: e.target.value as Form['channel'] })} value={form.channel}>
                                {(['all', 'dine_in', 'room_service', 'takeaway'] as const).map((c) => <option key={c} value={c}>{t(`fnb.px.channel.${c}` as MessageKey)}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={action.fieldError('name')} field="name" label={t('fnb.px.name')}><Input maxLength={80} onChange={(e) => setForm({ ...form, name: e.target.value })} value={form.name} /></FormField>
                        <FormField error={action.fieldError('price_minor')} field="price_minor" label={t('fnb.px.price')}><MoneyInput onChange={(e) => setForm({ ...form, price: e.target.value })} value={form.price} /></FormField>
                        <FormField error={action.fieldError('valid_from')} field="valid_from" label={t('fnb.px.validFrom')}><DatePicker onChange={(e) => setForm({ ...form, from: e.target.value })} value={form.from} /></FormField>
                        <FormField error={action.fieldError('valid_to')} field="valid_to" hint={t('fnb.px.validToHint')} label={t('fnb.px.validTo')}><DatePicker onChange={(e) => setForm({ ...form, to: e.target.value })} value={form.to} /></FormField>
                        <FormField error={action.fieldError('from_time')} field="from_time" hint={t('fnb.px.hoursHint')} label={t('fnb.px.fromTime')}><TimeInput onChange={(e) => setForm({ ...form, fromTime: e.target.value })} value={form.fromTime} /></FormField>
                        <FormField error={action.fieldError('to_time')} field="to_time" label={t('fnb.px.toTime')}><TimeInput onChange={(e) => setForm({ ...form, toTime: e.target.value })} value={form.toTime} /></FormField>
                        <fieldset className="flex flex-wrap gap-3 sm:col-span-2">
                            <legend className="text-sm font-medium">{t('fnb.px.days')}</legend>
                            {DAYS.map((d) => (
                                <label className="flex items-center gap-1 text-sm" key={d}>
                                    <input checked={(form.days & d) !== 0} onChange={(e) => setForm({ ...form, days: e.target.checked ? form.days | d : form.days & ~d })} type="checkbox" />
                                    {t(`fnb.px.day.${d}` as MessageKey)}
                                </label>
                            ))}
                        </fieldset>
                        {form.days === 0 ? <div className="sm:col-span-2"><Alert title={t('fnb.px.pickDay')} tone="warning" /></div> : null}
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setRetire(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={retire === null || retire.reason.trim() === ''} loading={action.busy} onClick={() => void submitRetire()} type="button">{t('fnb.px.retireConfirm')}</Button></>}
                onClose={() => setRetire(null)}
                open={retire !== null}
                title={retire === null ? '' : t('fnb.px.retireTitle', { name: retire.rule.name, price: money(retire.rule.price_minor) })}
            >
                {retire !== null && (
                    <div className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">{t('fnb.px.retireHint')}</p>
                        {failure}
                        <FormField error={action.fieldError('reason')} field="reason" label={t('fnb.px.reason')}><Input maxLength={200} onChange={(e) => setRetire({ ...retire, reason: e.target.value })} value={retire.reason} /></FormField>
                    </div>
                )}
            </Dialog>
        </FnbShell>
    );
}
