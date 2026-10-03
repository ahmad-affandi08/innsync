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
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { HrShell } from '@/modules/hr/components/hr-shell';
import type { ServiceChargeDistribution, ServiceChargeLine, ServiceChargeOverview } from '@/modules/hr/lib/hr';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

type SettingsForm = { share: string; reserve: string; points: string; rows: { position: string; points: string }[]; lock: number | null };

const percent = (bp: number) => String(bp / 100);
const toX100 = (value: string) => Math.round(Number(value.replace(',', '.')) * 100);

/** The service charge: what was collected, the staff's share and the reserve, and how each person's part comes from the points of their position and how much they were there. */
export default function ServiceChargePage({ overview }: { overview: ServiceChargeOverview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [create, setCreate] = useState<string | null>(null);
    const [settings, setSettings] = useState<SettingsForm | null>(null);
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const money = (minor: number) => format.money(minor, overview.currency);
    const d = overview.selected;
    const reload = ['overview'];
    const pointsText = (x100: number) => String(x100 / 100);

    async function createDistribution() {
        if (create === null) return;
        const result = await action.run('/hr/service-charge', { body: { period: create }, reload });

        if (result !== null) setCreate(null);
    }

    async function saveSettings() {
        if (settings === null) return;
        const result = await action.run('/hr/service-charge/settings', {
            body: { staff_share_bp: toX100(settings.share), reserve_bp: toX100(settings.reserve), default_points_x100: toX100(settings.points), points: settings.rows.filter((r) => r.position.trim() !== '').map((r) => ({ position: r.position.trim(), points_x100: toX100(r.points) })), lock_version: settings.lock },
            reload,
        });

        if (result !== null) setSettings(null);
    }

    const step = (name: 'calculate' | 'approve') => (d === null ? Promise.resolve(null) : action.run(`/hr/service-charge/${d.id}/${name}`, { body: { lock_version: d.lock_version }, reload }));
    const approveText = (x: ServiceChargeDistribution) => (x.approval === null ? t('hr.sc.askApproval') : x.approval.status === 'approved' ? t('hr.sc.takeApproval') : x.approval.status === 'pending' ? t('hr.sc.checkApproval') : t('hr.sc.takeRefusal'));
    const columns: DataGridColumn<ServiceChargeLine>[] = [
        { id: 'name', label: t('hr.col.name'), value: (l) => l.employee.name, rowHeader: true, cell: (l) => <span>{l.employee.name}<span className="block text-xs text-muted-foreground">{l.employee.number} · {l.employee.position}</span></span> },
        { id: 'points', label: t('hr.sc.points'), align: 'right', value: (l) => l.points_x100, cell: (l) => <span>{pointsText(l.points_x100)}{l.default_points ? <span className="block text-xs text-warning">{t('hr.sc.defaultPoints')}</span> : null}</span> },
        { id: 'days', label: t('hr.run.days'), align: 'right', value: (l) => l.present_days, cell: (l) => t('hr.perf.presentOf', { present: l.present_days, scheduled: l.scheduled_days }) },
        { id: 'attendance', label: t('hr.perf.attendance'), align: 'right', value: (l) => l.attendance_bp, cell: (l) => `${percent(l.attendance_bp)}%` },
        { id: 'share', label: t('hr.sc.share'), align: 'right', value: (l) => l.share_minor, cell: (l) => <strong>{money(l.share_minor)}</strong> },
    ];
    const s = overview.settings;

    return (
        <HrShell actions={<Button onClick={() => { action.clear(); setCreate(overview.period); }} type="button">{t('hr.sc.new')}</Button>} description={t('hr.sc.description')} title={t('hr.sc.title')}>
            {action.error !== null && create === null && settings === null ? failure : null}
            <Tabs defaultValue="distribution">
                <TabsList aria-label={t('hr.sc.title')}>
                    <TabsTrigger value="distribution">{t('hr.sc.distributionTab')}</TabsTrigger>
                    <TabsTrigger value="settings">{t('hr.sc.settingsTab')}</TabsTrigger>
                </TabsList>
                <TabsContent className="flex flex-col gap-3" value="distribution">
                    <div className="flex flex-wrap items-end gap-3">
                        <FormField label={t('hr.run.period')}>
                            <Select onChange={(e) => router.get('/hr/service-charge', { distribution: e.target.value })} value={d?.id ?? ''}>
                                {overview.distributions.length === 0 ? <option value="">—</option> : null}
                                {overview.distributions.map((x) => <option key={x.id} value={x.id}>{x.period} · {t(`hr.sc.status.${x.status}` as MessageKey)}</option>)}
                            </Select>
                        </FormField>
                    </div>
                    {d === null ? <EmptyState illustration="checklist" title={t('hr.sc.none')} /> : (
                        <>
                            <div className="flex flex-wrap items-center gap-3" data-testid="hr-sc-summary">
                                <StatusBadge label={t(`hr.sc.status.${d.status}` as MessageKey)} tone={d.status === 'approved' ? 'success' : 'neutral'} />
                                <span className="text-sm text-muted-foreground">{d.number}</span>
                                <span className="text-sm">{t('hr.sc.collected', { amount: money(d.collected_minor), days: d.days_booked })}</span>
                                <span className="text-xs text-muted-foreground">{t('hr.sc.split', { pool: money(d.pool_minor), reserve: money(d.reserve_minor), shared: money(d.distributed_minor), residue: money(d.residue_minor) })}</span>
                            </div>
                            {d.sources.length > 0 ? <p className="text-xs text-muted-foreground">{d.sources.map((x) => `${x.outlet || '—'} ${money(x.minor)}`).join(' · ')}</p> : null}
                            <div className="flex flex-wrap gap-2">
                                {d.may.calculate ? <Button disabled={action.busy} onClick={() => void step('calculate')} type="button">{t('hr.sc.simulate')}</Button> : null}
                                {d.may.approve ? <Button disabled={action.busy || !d.month_over} onClick={() => void step('approve')} type="button" variant="outline">{approveText(d)}</Button> : null}
                                {d.may.discard ? <Button disabled={action.busy} onClick={() => void action.run(`/hr/service-charge/${d.id}/discard`, { body: {}, reload })} type="button" variant="outline">{t('hr.run.discard')}</Button> : null}
                            </div>
                            {d.may.approve && !d.month_over ? <Alert title={t('hr.sc.monthNotOver')} tone="info" /> : null}
                            {d.approval !== null && !d.approval.consumed ? <Alert title={t(`hr.run.approvalState.${d.approval.status}` as MessageKey)} tone="info" /> : null}
                            {d.status === 'approved' ? <Alert title={t('hr.sc.locked')} tone="success" /> : null}
                            <DataGrid caption={t('hr.sc.distributionTab')} columns={columns} empty={<EmptyState illustration="checklist" title={t('hr.sc.noLines')} />} getRowId={(l) => l.id} id="hr.sc.lines" rows={d.lines} testId="hr-sc-lines" />
                        </>
                    )}
                </TabsContent>
                <TabsContent className="flex flex-col gap-3" value="settings">
                    <div className="flex gap-2"><Button onClick={() => { action.clear(); setSettings({ share: percent(s.staff_share_bp), reserve: percent(s.reserve_bp), points: pointsText(s.default_points_x100), rows: s.points.map((p) => ({ position: p.position, points: pointsText(p.points_x100) })), lock: s.lock_version }); }} type="button">{t('hr.sc.editSettings')}</Button></div>
                    {s.is_baseline ? <Alert title={t('hr.sc.baseline')} tone="warning" /> : null}
                    {overview.unlisted_positions.length > 0 ? <Alert title={t('hr.sc.unlisted', { positions: overview.unlisted_positions.join(', '), points: pointsText(s.default_points_x100) })} tone="info" /> : null}
                    <dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2" data-testid="hr-sc-settings">
                        <dt>{t('hr.sc.staffShare')}</dt><dd>{percent(s.staff_share_bp)}%</dd>
                        <dt>{t('hr.sc.reserve')}</dt><dd>{percent(s.reserve_bp)}%</dd>
                        <dt>{t('hr.sc.defaultPointsLabel')}</dt><dd>{pointsText(s.default_points_x100)}</dd>
                        <dt>{t('hr.sc.positions')}</dt><dd>{s.points.length === 0 ? '—' : s.points.map((p) => `${p.position} ${pointsText(p.points_x100)}`).join(' · ')}</dd>
                    </dl>
                </TabsContent>
            </Tabs>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setCreate(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={create === null || !/^\d{4}-\d{2}$/.test(create)} loading={action.busy} onClick={() => void createDistribution()} type="button">{t('hr.run.create')}</Button></>}
                onClose={() => setCreate(null)}
                open={create !== null}
                title={t('hr.sc.new')}
            >
                {create !== null && (
                    <div className="grid gap-3">
                        {failure !== null ? failure : null}
                        <FormField error={action.fieldError('period')} field="period" hint={t('hr.sc.periodHint')} label={t('hr.run.period')}><Input onChange={(e) => setCreate(e.target.value)} type="month" value={create} /></FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setSettings(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void saveSettings()} type="button">{t('hr.pay.saveParameters')}</Button></>}
                onClose={() => setSettings(null)}
                open={settings !== null}
                title={t('hr.sc.editSettings')}
            >
                {settings !== null && (
                    <div className="grid gap-3 sm:grid-cols-3">
                        {failure !== null ? <div className="sm:col-span-3">{failure}</div> : null}
                        <FormField error={action.fieldError('staff_share_bp')} field="staff_share_bp" hint={t('hr.sc.staffShareHint')} label={t('hr.sc.staffShare')}><Input inputMode="decimal" onChange={(e) => setSettings({ ...settings, share: e.target.value })} value={settings.share} /></FormField>
                        <FormField error={action.fieldError('reserve_bp')} field="reserve_bp" hint={t('hr.sc.reserveHint')} label={t('hr.sc.reserve')}><Input inputMode="decimal" onChange={(e) => setSettings({ ...settings, reserve: e.target.value })} value={settings.reserve} /></FormField>
                        <FormField error={action.fieldError('default_points_x100')} field="default_points_x100" hint={t('hr.sc.defaultPointsHint')} label={t('hr.sc.defaultPointsLabel')}><Input inputMode="decimal" onChange={(e) => setSettings({ ...settings, points: e.target.value })} value={settings.points} /></FormField>
                        <div className="flex flex-col gap-2 sm:col-span-3">
                            <h3 className="text-sm font-semibold">{t('hr.sc.positions')}</h3>
                            {settings.rows.map((r, i) => (
                                <div className="grid grid-cols-[1fr_8rem_auto] items-end gap-2" key={i}>
                                    <FormField label={t('hr.sc.position')}><Input maxLength={80} onChange={(e) => setSettings({ ...settings, rows: settings.rows.map((x, n) => (n === i ? { ...x, position: e.target.value } : x)) })} value={r.position} /></FormField>
                                    <FormField label={t('hr.sc.points')}><Input inputMode="decimal" onChange={(e) => setSettings({ ...settings, rows: settings.rows.map((x, n) => (n === i ? { ...x, points: e.target.value } : x)) })} value={r.points} /></FormField>
                                    <Button onClick={() => setSettings({ ...settings, rows: settings.rows.filter((_, n) => n !== i) })} size="sm" type="button" variant="outline">{t('hr.pay.removeRow')}</Button>
                                </div>
                            ))}
                            <div className="flex flex-wrap gap-2">
                                <Button onClick={() => setSettings({ ...settings, rows: [...settings.rows, { position: '', points: '1' }] })} size="sm" type="button" variant="outline">{t('hr.sc.addPosition')}</Button>
                                {overview.unlisted_positions.filter((p) => !settings.rows.some((r) => r.position.toLowerCase() === p.toLowerCase())).map((p) => <Button key={p} onClick={() => setSettings({ ...settings, rows: [...settings.rows, { position: p, points: settings.points }] })} size="sm" type="button" variant="outline">+ {p}</Button>)}
                            </div>
                        </div>
                    </div>
                )}
            </Dialog>
        </HrShell>
    );
}
