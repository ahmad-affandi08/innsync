import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DatePicker } from '@/components/ui/date-picker';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Metric } from '@/components/ui/metric';
import { MoneyInput } from '@/components/ui/money-input';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { minorToMajorText, parseMajorToMinor } from '@/shared/money/money';

type Room = { id: string; code: string; name: string; is_active: boolean; lock_version: number };
type Treatment = { id: string; name: string; minutes: number; price_minor: number; is_active: boolean; lock_version: number };
type Therapist = { id: string; name: string; user_id: string | null; is_active: boolean; commission_bp: number | null; lock_version: number };
type Overview = { currency: string; rooms: Room[]; treatments: Treatment[]; therapists: Therapist[]; staff: { id: string; name: string }[] };
type Report = {
    from: string;
    to: string;
    currency: string;
    treatments_done: number;
    therapists: { therapist_id: string; name: string; treatments: number; revenue_minor: number; commission_minor: number }[];
    treatments: { name: string; count: number; revenue_minor: number }[];
    money: { base_minor: number; service_charge_minor: number; tax_minor: number; total_minor: number; charged_to_room_minor: number; paid_at_desk_minor: number; unpaid_minor: number };
};

type RoomForm = { id: string | null; lock: number; code: string; name: string; active: boolean };
type TreatmentForm = { id: string | null; lock: number; name: string; minutes: string; price: string; active: boolean };
type TherapistForm = { id: string | null; lock: number; name: string; user: string; percent: string; active: boolean };

/** Basis points as the percent a person types: 4000 → "40", 2550 → "25.5". */
const toPercent = (bp: number): string => String(bp / 100);
const toBasisPoints = (text: string): number => Math.round(Number(text.replace(',', '.')) * 100);

/** The set-up of the spa (treatment rooms, treatments, therapists with the commission each earns) and the report of the period: commission by therapist, what sells best, and the revenue. */
export default function SpaManagePage({ overview, report }: { overview: Overview; report: Report }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const reload = ['overview', 'report'];
    const money = (minor: number) => format.money(minor, overview.currency);
    const [room, setRoom] = useState<RoomForm | null>(null);
    const [treatment, setTreatment] = useState<TreatmentForm | null>(null);
    const [therapist, setTherapist] = useState<TherapistForm | null>(null);
    const [period, setPeriod] = useState({ from: report.from, to: report.to });
    const busy = room !== null || treatment !== null || therapist !== null;

    async function saveRoom() {
        if (room === null) return;
        const done = room.id === null
            ? await action.run('/front-office/spa/rooms', { body: { code: room.code.trim(), name: room.name.trim() }, reload })
            : await action.run(`/front-office/spa/rooms/${room.id}`, { body: { name: room.name.trim(), is_active: room.active, lock_version: room.lock }, reload });
        if (done !== null) setRoom(null);
    }

    async function saveTreatment() {
        if (treatment === null) return;
        const body = { name: treatment.name.trim(), minutes: Number(treatment.minutes) || 0, price_minor: parseMajorToMinor(treatment.price, overview.currency) ?? 0 };
        const done = treatment.id === null
            ? await action.run('/front-office/spa/treatments', { body, reload })
            : await action.run(`/front-office/spa/treatments/${treatment.id}`, { body: { ...body, is_active: treatment.active, lock_version: treatment.lock }, reload });
        if (done !== null) setTreatment(null);
    }

    async function saveTherapist() {
        if (therapist === null) return;
        const body = { name: therapist.name.trim(), user_id: therapist.user === '' ? null : therapist.user, commission_bp: toBasisPoints(therapist.percent) };
        const done = therapist.id === null
            ? await action.run('/front-office/spa/therapists', { body, reload })
            : await action.run(`/front-office/spa/therapists/${therapist.id}`, { body: { ...body, is_active: therapist.active, lock_version: therapist.lock }, reload });
        if (done !== null) setTherapist(null);
    }

    const staffName = (id: string | null) => overview.staff.find((s) => s.id === id)?.name ?? null;
    const state = (on: boolean) => <StatusBadge label={on ? t('fo.spa.active') : t('fo.spa.paused')} tone={on ? 'success' : 'neutral'} />;

    const byTherapist: DataGridColumn<Report['therapists'][number]>[] = [
        { id: 'name', label: t('fo.spa.col.therapist'), value: (r) => r.name, rowHeader: true, cell: (r) => r.name },
        { id: 'n', label: t('fo.spa.rep.treatments'), align: 'right', value: (r) => r.treatments, cell: (r) => r.treatments },
        { id: 'revenue', label: t('fo.spa.rep.revenue'), align: 'right', value: (r) => r.revenue_minor, cell: (r) => money(r.revenue_minor) },
        { id: 'commission', label: t('fo.spa.rep.commission'), align: 'right', value: (r) => r.commission_minor, cell: (r) => <strong>{money(r.commission_minor)}</strong> },
    ];

    const bySale: DataGridColumn<Report['treatments'][number]>[] = [
        { id: 'name', label: t('fo.spa.col.treatment'), value: (r) => r.name, rowHeader: true, cell: (r) => r.name },
        { id: 'count', label: t('fo.spa.rep.sold'), align: 'right', value: (r) => r.count, cell: (r) => r.count },
        { id: 'revenue', label: t('fo.spa.rep.revenue'), align: 'right', value: (r) => r.revenue_minor, cell: (r) => money(r.revenue_minor) },
    ];

    const rooms: DataGridColumn<Room>[] = [
        { id: 'room', label: t('fo.spa.col.room'), value: (r) => r.name, rowHeader: true, cell: (r) => <span>{r.name}<span className="block text-xs text-muted-foreground">{r.code}</span></span> },
        { id: 'state', label: t('fo.spa.col.status'), value: (r) => (r.is_active ? 'on' : 'off'), cell: (r) => state(r.is_active) },
        { id: 'edit', label: '', value: () => '', sortable: false, cell: (r) => <Button onClick={() => { action.clear(); setRoom({ id: r.id, lock: r.lock_version, code: r.code, name: r.name, active: r.is_active }); }} size="sm" type="button" variant="outline">{t('fo.spa.edit')}</Button> },
    ];

    const treatments: DataGridColumn<Treatment>[] = [
        { id: 'name', label: t('fo.spa.col.treatment'), value: (x) => x.name, rowHeader: true, cell: (x) => x.name },
        { id: 'minutes', label: t('fo.spa.col.length'), align: 'right', value: (x) => x.minutes, cell: (x) => t('fo.spa.minutes', { n: x.minutes }) },
        { id: 'price', label: t('fo.spa.col.price'), align: 'right', value: (x) => x.price_minor, cell: (x) => money(x.price_minor) },
        { id: 'state', label: t('fo.spa.col.status'), value: (x) => (x.is_active ? 'on' : 'off'), cell: (x) => state(x.is_active) },
        { id: 'edit', label: '', value: () => '', sortable: false, cell: (x) => <Button onClick={() => { action.clear(); setTreatment({ id: x.id, lock: x.lock_version, name: x.name, minutes: String(x.minutes), price: minorToMajorText(x.price_minor, overview.currency), active: x.is_active }); }} size="sm" type="button" variant="outline">{t('fo.spa.edit')}</Button> },
    ];

    const therapists: DataGridColumn<Therapist>[] = [
        { id: 'name', label: t('fo.spa.col.therapist'), value: (x) => x.name, rowHeader: true, cell: (x) => <span>{x.name}<span className="block text-xs text-muted-foreground">{x.user_id === null ? t('fo.spa.noAccount') : staffName(x.user_id) ?? t('fo.spa.accountLinked')}</span></span> },
        { id: 'commission', label: t('fo.spa.rep.rate'), align: 'right', value: (x) => x.commission_bp ?? 0, cell: (x) => `${toPercent(x.commission_bp ?? 0)}%` },
        { id: 'state', label: t('fo.spa.col.status'), value: (x) => (x.is_active ? 'on' : 'off'), cell: (x) => state(x.is_active) },
        { id: 'edit', label: '', value: () => '', sortable: false, cell: (x) => <Button onClick={() => { action.clear(); setTherapist({ id: x.id, lock: x.lock_version, name: x.name, user: x.user_id ?? '', percent: toPercent(x.commission_bp ?? 0), active: x.is_active }); }} size="sm" type="button" variant="outline">{t('fo.spa.edit')}</Button> },
    ];

    const footer = (close: () => void, save: () => void) => <><Button disabled={action.busy} onClick={close} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={save} type="button">{t('fo.spa.save')}</Button></>;
    const error = action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null;

    return (
        <FrontOfficeShell description={t('fo.spa.manage.description')} title={t('fo.spa.manage.title')} wide>
            {action.error !== null && !busy ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            <Link className="text-sm font-medium text-brand underline-offset-4 hover:underline print:hidden" href="/front-office/spa">{t('fo.spa.backToBoard')}</Link>
            <Tabs defaultValue="report">
                <TabsList>
                    <TabsTrigger value="report">{t('fo.spa.tab.report')}</TabsTrigger>
                    <TabsTrigger value="treatments">{t('fo.spa.tab.treatments')}</TabsTrigger>
                    <TabsTrigger value="therapists">{t('fo.spa.tab.therapists')}</TabsTrigger>
                    <TabsTrigger value="rooms">{t('fo.spa.tab.rooms')}</TabsTrigger>
                </TabsList>

                <TabsContent className="gap-4" value="report">
                    <div className="flex flex-wrap items-end gap-3 print:hidden">
                        <FormField label={t('fo.spa.rep.from')}><DatePicker onChange={(e) => setPeriod({ ...period, from: e.target.value })} value={period.from} /></FormField>
                        <FormField label={t('fo.spa.rep.to')}><DatePicker onChange={(e) => setPeriod({ ...period, to: e.target.value })} value={period.to} /></FormField>
                        <Button onClick={() => router.get('/front-office/spa/manage', period, { only: reload, preserveScroll: true, preserveState: true })} type="button" variant="outline">{t('fo.spa.rep.show')}</Button>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <Metric label={t('fo.spa.rep.treatments')} value={report.treatments_done} />
                        <Metric detail={t('fo.spa.rep.beforeCharges')} label={t('fo.spa.rep.revenue')} value={money(report.money.base_minor)} />
                        <Metric detail={t('fo.spa.rep.withCharges')} label={t('fo.spa.rep.total')} value={money(report.money.total_minor)} />
                        <Metric label={t('fo.spa.rep.unpaid')} value={money(report.money.unpaid_minor)} />
                        <Metric label={t('fo.spa.rep.toRoom')} value={money(report.money.charged_to_room_minor)} />
                        <Metric label={t('fo.spa.rep.atDesk')} value={money(report.money.paid_at_desk_minor)} />
                    </div>
                    <h2 className="text-base font-semibold">{t('fo.spa.rep.byTherapist')}</h2>
                    <DataGrid caption={t('fo.spa.rep.byTherapist')} columns={byTherapist} empty={<EmptyState title={t('fo.spa.rep.none')} />} getRowId={(r) => r.therapist_id} id="fo.spa.report.therapists" rows={report.therapists} testId="spa-commission" />
                    <h2 className="text-base font-semibold">{t('fo.spa.rep.bySale')}</h2>
                    <DataGrid caption={t('fo.spa.rep.bySale')} columns={bySale} empty={<EmptyState title={t('fo.spa.rep.none')} />} getRowId={(r) => r.name} id="fo.spa.report.treatments" rows={report.treatments} testId="spa-best-sellers" />
                </TabsContent>

                <TabsContent className="gap-3" value="treatments">
                    <div><Button onClick={() => { action.clear(); setTreatment({ id: null, lock: 0, name: '', minutes: '60', price: '', active: true }); }} type="button">{t('fo.spa.addTreatment')}</Button></div>
                    <DataGrid caption={t('fo.spa.tab.treatments')} columns={treatments} empty={<EmptyState title={t('fo.spa.noTreatments')} />} getRowId={(x) => x.id} id="fo.spa.treatments" rows={overview.treatments} testId="spa-treatments" />
                </TabsContent>

                <TabsContent className="gap-3" value="therapists">
                    <div><Button onClick={() => { action.clear(); setTherapist({ id: null, lock: 0, name: '', user: '', percent: '30', active: true }); }} type="button">{t('fo.spa.addTherapist')}</Button></div>
                    <p className="text-sm text-muted-foreground">{t('fo.spa.therapistHint')}</p>
                    <DataGrid caption={t('fo.spa.tab.therapists')} columns={therapists} empty={<EmptyState title={t('fo.spa.noTherapists')} />} getRowId={(x) => x.id} id="fo.spa.therapists" rows={overview.therapists} testId="spa-therapists" />
                </TabsContent>

                <TabsContent className="gap-3" value="rooms">
                    <div><Button onClick={() => { action.clear(); setRoom({ id: null, lock: 0, code: '', name: '', active: true }); }} type="button">{t('fo.spa.addRoom')}</Button></div>
                    <DataGrid caption={t('fo.spa.tab.rooms')} columns={rooms} empty={<EmptyState title={t('fo.spa.noRooms')} />} getRowId={(x) => x.id} id="fo.spa.rooms" rows={overview.rooms} testId="spa-room-list" />
                </TabsContent>
            </Tabs>

            <Dialog footer={footer(() => setRoom(null), () => void saveRoom())} onClose={() => setRoom(null)} open={room !== null} title={room?.id === null ? t('fo.spa.addRoom') : t('fo.spa.edit')}>
                {room !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {error}
                        <FormField error={action.fieldError('code')} field="code" label={t('fo.spa.code')}><Input disabled={room.id !== null} maxLength={12} onChange={(e) => setRoom({ ...room, code: e.target.value })} value={room.code} /></FormField>
                        <FormField error={action.fieldError('name')} field="name" label={t('fo.spa.name')}><Input maxLength={60} onChange={(e) => setRoom({ ...room, name: e.target.value })} value={room.name} /></FormField>
                        {room.id !== null ? <label className="flex items-center gap-2 text-sm sm:col-span-2"><Checkbox checked={room.active} onCheckedChange={(v) => setRoom({ ...room, active: v === true })} />{t('fo.spa.active')}</label> : null}
                    </div>
                )}
            </Dialog>

            <Dialog footer={footer(() => setTreatment(null), () => void saveTreatment())} onClose={() => setTreatment(null)} open={treatment !== null} title={treatment?.id === null ? t('fo.spa.addTreatment') : t('fo.spa.edit')}>
                {treatment !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {error}
                        <FormField error={action.fieldError('name')} field="name" label={t('fo.spa.name')}><Input maxLength={80} onChange={(e) => setTreatment({ ...treatment, name: e.target.value })} value={treatment.name} /></FormField>
                        <FormField error={action.fieldError('minutes')} field="minutes" label={t('fo.spa.col.length')}><Input inputMode="numeric" maxLength={3} onChange={(e) => setTreatment({ ...treatment, minutes: e.target.value.replace(/\D/g, '') })} value={treatment.minutes} /></FormField>
                        <FormField error={action.fieldError('price_minor')} field="price_minor" hint={t('fo.spa.priceHint')} label={t('fo.spa.col.price')}><MoneyInput onChange={(e) => setTreatment({ ...treatment, price: e.target.value })} value={treatment.price} /></FormField>
                        {treatment.id !== null ? <label className="flex items-center gap-2 text-sm sm:col-span-2"><Checkbox checked={treatment.active} onCheckedChange={(v) => setTreatment({ ...treatment, active: v === true })} />{t('fo.spa.active')}</label> : null}
                    </div>
                )}
            </Dialog>

            <Dialog footer={footer(() => setTherapist(null), () => void saveTherapist())} onClose={() => setTherapist(null)} open={therapist !== null} title={therapist?.id === null ? t('fo.spa.addTherapist') : t('fo.spa.edit')}>
                {therapist !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {error}
                        <FormField error={action.fieldError('name')} field="name" label={t('fo.spa.name')}><Input maxLength={80} onChange={(e) => setTherapist({ ...therapist, name: e.target.value })} value={therapist.name} /></FormField>
                        <FormField error={action.fieldError('commission')} field="commission_bp" hint={t('fo.spa.rateHint')} label={t('fo.spa.rep.rate')}><Input inputMode="decimal" maxLength={6} onChange={(e) => setTherapist({ ...therapist, percent: e.target.value.replace(/[^0-9.,]/g, '') })} value={therapist.percent} /></FormField>
                        <FormField error={action.fieldError('user_id')} field="user_id" hint={t('fo.spa.accountHint')} label={t('fo.spa.account')}>
                            <Select onChange={(e) => setTherapist({ ...therapist, user: e.target.value })} value={therapist.user}>
                                <option value="">{t('fo.spa.noAccount')}</option>
                                {overview.staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                            </Select>
                        </FormField>
                        {therapist.id !== null ? <label className="flex items-center gap-2 text-sm sm:col-span-2"><Checkbox checked={therapist.active} onCheckedChange={(v) => setTherapist({ ...therapist, active: v === true })} />{t('fo.spa.active')}</label> : null}
                    </div>
                )}
            </Dialog>
        </FrontOfficeShell>
    );
}
