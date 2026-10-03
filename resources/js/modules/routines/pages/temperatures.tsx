import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DatePicker } from '@/components/ui/date-picker';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { RoutineShell } from '@/modules/routines/components/routine-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Point = { id: string; name: string; min_tenth: number; max_tenth: number; active: boolean; lock_version: number };
type Reading = { id: string; point: string; value_tenth: number; min_tenth: number; max_tenth: number; in_range: boolean; action_taken: string | null; by: string | null; at: string };
type Overview = { points: Point[]; readings: Reading[]; may: { record: boolean; manage: boolean }; business_date: string; from: string; to: string };

const degrees = (tenth: number) => (tenth / 10).toFixed(1);
const toTenth = (text: string) => {
    const n = Number(text.replace(',', '.'));

    return text.trim() === '' || Number.isNaN(n) ? null : Math.round(n * 10);
};

/** The storage temperatures: the places with the range of each, and the readings; a reading outside its range needs the action taken (FR-KIT-008). */
export default function TemperaturesPage({ department, overview }: { department: string; overview: Overview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const active = overview.points.filter((p) => p.active);
    const [reading, setReading] = useState({ pointId: active[0]?.id ?? '', value: '', actionTaken: '' });
    const [point, setPoint] = useState<{ id: string; name: string; min: string; max: string; active: boolean; lock: number } | null>(null);
    const [range, setRange] = useState({ from: overview.from, to: overview.to });
    const chosen = overview.points.find((p) => p.id === reading.pointId);
    const value = toTenth(reading.value);
    const outside = chosen !== undefined && value !== null && (value < chosen.min_tenth || value > chosen.max_tenth);

    async function record() {
        if (value === null) return;
        const done = await action.run(`/${department}/temperatures/readings`, { body: { point_id: reading.pointId, value_tenth: value, action_taken: reading.actionTaken.trim() || null }, reload: ['overview'] });
        if (done !== null) setReading({ ...reading, value: '', actionTaken: '' });
    }

    async function savePoint() {
        if (point === null) return;
        const min = toTenth(point.min);
        const max = toTenth(point.max);
        if (min === null || max === null) return;
        const done = point.id === ''
            ? await action.run(`/${department}/temperatures/points`, { body: { name: point.name.trim(), min_tenth: min, max_tenth: max }, reload: ['overview'] })
            : await action.run(`/${department}/temperatures/points/${point.id}`, { body: { name: point.name.trim(), min_tenth: min, max_tenth: max, active: point.active, lock_version: point.lock }, reload: ['overview'] });
        if (done !== null) setPoint(null);
    }

    const columns: DataGridColumn<Reading>[] = [
        { id: 'at', label: t('rtn.temp.at'), value: (r) => r.at, rowHeader: true, cell: (r) => format.instant(r.at) },
        { id: 'point', label: t('rtn.temp.point'), value: (r) => r.point, filter: 'select' },
        { id: 'value', label: t('rtn.temp.value'), align: 'right', value: (r) => r.value_tenth, cell: (r) => `${degrees(r.value_tenth)} °C` },
        { id: 'range', label: t('rtn.temp.range'), value: (r) => r.min_tenth, cell: (r) => `${degrees(r.min_tenth)} – ${degrees(r.max_tenth)} °C` },
        { id: 'state', label: t('rtn.temp.state'), value: (r) => (r.in_range ? 'ok' : 'out'), filter: 'select', filterLabel: (v) => t(`rtn.temp.${v}` as 'rtn.temp.ok'), cell: (r) => <StatusBadge label={t(r.in_range ? 'rtn.temp.ok' : 'rtn.temp.out')} tone={r.in_range ? 'success' : 'danger'} /> },
        { id: 'action', label: t('rtn.temp.action'), value: (r) => r.action_taken ?? '' },
        { id: 'by', label: t('rtn.temp.by'), value: (r) => r.by ?? '' },
    ];

    return (
        <RoutineShell department={department} description={t('rtn.temp.description')} title={t('rtn.temp.title')}>
            <div className="flex flex-wrap gap-2">
                <Button asChild size="sm" variant="outline"><Link href={`/${department}/routines`}>{t('rtn.nav')}</Link></Button>
                {overview.may.manage ? <Button onClick={() => { action.clear(); setPoint({ id: '', name: '', min: '0', max: '5', active: true, lock: 0 }); }} size="sm" type="button" variant="outline">{t('rtn.temp.addPoint')}</Button> : null}
            </div>
            {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}

            {overview.may.record ? (
                active.length === 0 ? <p className="text-sm text-muted-foreground">{t('rtn.temp.noPoints')}</p> : (
                    <section className="grid gap-3 border border-border bg-surface p-4 sm:grid-cols-3" data-testid="temperature-form">
                        <FormField error={action.fieldError('point_id')} field="point_id" label={t('rtn.temp.point')}><Select onChange={(e) => setReading({ ...reading, pointId: e.target.value })} value={reading.pointId}>{active.map((p) => <option key={p.id} value={p.id}>{p.name} ({degrees(p.min_tenth)} – {degrees(p.max_tenth)} °C)</option>)}</Select></FormField>
                        <FormField error={action.fieldError('value_tenth')} field="value_tenth" label={t('rtn.temp.valueField')}><Input inputMode="decimal" onChange={(e) => setReading({ ...reading, value: e.target.value })} value={reading.value} /></FormField>
                        <div className="flex items-end"><Button disabled={value === null || (outside && reading.actionTaken.trim() === '')} loading={action.busy} onClick={() => void record()} type="button">{t('rtn.temp.record')}</Button></div>
                        {outside ? <div className="sm:col-span-3"><FormField error={action.fieldError('action_taken')} field="action_taken" hint={t('rtn.temp.outHint')} label={t('rtn.temp.action')}><Input maxLength={200} onChange={(e) => setReading({ ...reading, actionTaken: e.target.value })} value={reading.actionTaken} /></FormField></div> : null}
                    </section>
                )
            ) : null}

            <ul className="flex flex-wrap gap-2 text-sm" data-testid="points">
                {overview.points.map((p) => (
                    <li className="flex items-center gap-2 border border-border px-3 py-1" key={p.id}>
                        <span className={p.active ? undefined : 'text-muted-foreground line-through'}>{p.name} · {degrees(p.min_tenth)} – {degrees(p.max_tenth)} °C</span>
                        {overview.may.manage ? <Button onClick={() => { action.clear(); setPoint({ id: p.id, name: p.name, min: degrees(p.min_tenth), max: degrees(p.max_tenth), active: p.active, lock: p.lock_version }); }} size="sm" type="button" variant="outline">{t('rtn.tpl.edit')}</Button> : null}
                    </li>
                ))}
            </ul>

            {point !== null ? (
                <section className="grid max-w-xl gap-3 border border-border p-4" data-testid="point-form">
                    <FormField error={action.fieldError('name')} field="name" label={t('rtn.temp.point')}><Input maxLength={60} onChange={(e) => setPoint({ ...point, name: e.target.value })} value={point.name} /></FormField>
                    <FormField error={action.fieldError('min_tenth')} field="min_tenth" label={t('rtn.temp.min')}><Input inputMode="decimal" onChange={(e) => setPoint({ ...point, min: e.target.value })} value={point.min} /></FormField>
                    <FormField error={action.fieldError('max_tenth')} field="max_tenth" label={t('rtn.temp.max')}><Input inputMode="decimal" onChange={(e) => setPoint({ ...point, max: e.target.value })} value={point.max} /></FormField>
                    {point.id !== '' ? <label className="flex items-center gap-2 text-sm"><input checked={point.active} onChange={(e) => setPoint({ ...point, active: e.target.checked })} type="checkbox" />{t('rtn.temp.activePoint')}</label> : null}
                    <div className="flex gap-2"><Button loading={action.busy} onClick={() => void savePoint()} type="button">{t('rtn.temp.savePoint')}</Button><Button onClick={() => setPoint(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button></div>
                </section>
            ) : null}

            <form className="flex flex-wrap items-end gap-3" onSubmit={(e) => { e.preventDefault(); router.get(`/${department}/temperatures`, range); }}>
                <FormField label={t('rtn.perf.from')}><DatePicker onChange={(e) => setRange({ ...range, from: e.target.value })} required value={range.from} /></FormField>
                <FormField label={t('rtn.perf.to')}><DatePicker onChange={(e) => setRange({ ...range, to: e.target.value })} required value={range.to} /></FormField>
                <Button size="sm" type="submit" variant="outline">{t('rtn.perf.apply')}</Button>
            </form>
            <DataGrid caption={t('rtn.temp.title')} columns={columns} empty={<EmptyState title={t('rtn.temp.none')} />} getRowId={(r) => r.id} id="rtn.readings" rows={overview.readings} testId="readings" />
        </RoutineShell>
    );
}
