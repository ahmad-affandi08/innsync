import { Link } from '@inertiajs/react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { HrShell } from '@/modules/hr/components/hr-shell';
import type { PortalOverview } from '@/modules/hr/lib/hr';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import type { MessageKey } from '@/locales/en/index';

type ScheduleRow = NonNullable<PortalOverview['schedule']>[number];
type AttendanceRow = NonNullable<PortalOverview['attendance']>[number];

const TONE: Record<string, StatusTone> = { present: 'success', on_duty: 'info', missing_out: 'warning', absent: 'danger', not_in: 'warning', upcoming: 'neutral' };

/** The employee's own page: schedule, attendance, leave left, payslips and shift exchanges. */
export default function MePage({ portal }: { portal: PortalOverview }) {
    const { t, locale } = useTranslation();
    const format = useFormatters();
    const time = (iso: string | null) => (iso === null ? '—' : new Intl.DateTimeFormat(locale, { hour: '2-digit', minute: '2-digit', timeZone: format.timeZone ?? 'UTC' }).format(new Date(iso)));
    const shift = (r: ScheduleRow) => (r.is_off ? t('hr.me.dayOff') : [r.starts_at === null ? null : `${r.starts_at}–${r.ends_at}`, r.starts2_at === null ? null : `${r.starts2_at}–${r.ends2_at}`].filter((x) => x !== null).join(' · '));

    if (!portal.linked) {
        return <HrShell description={t('hr.me.description')} title={t('hr.me.title')}><Alert title={t('hr.att.noEmployee')} tone="warning" /></HrShell>;
    }

    const scheduleColumns: DataGridColumn<ScheduleRow>[] = [
        { id: 'date', label: t('hr.swap.date'), value: (r) => r.date, rowHeader: true, cell: (r) => format.date(r.date) },
        { id: 'shift', label: t('hr.att.shift'), value: (r) => r.code, cell: (r) => <span>{r.code}<span className="block text-xs text-muted-foreground">{shift(r)}</span></span> },
    ];
    const attendanceColumns: DataGridColumn<AttendanceRow>[] = [
        { id: 'date', label: t('hr.swap.date'), value: (r) => r.date, rowHeader: true, cell: (r) => format.date(r.date) },
        { id: 'shift', label: t('hr.att.shift'), value: (r) => r.code },
        { id: 'status', label: t('hr.col.status'), value: (r) => r.status, cell: (r) => <StatusBadge label={t(`hr.att.status.${r.status}` as MessageKey)} tone={TONE[r.status] ?? 'neutral'} /> },
        { id: 'in', label: t('hr.att.in'), value: (r) => r.in_at ?? '', cell: (r) => time(r.in_at) },
        { id: 'out', label: t('hr.att.out'), value: (r) => r.out_at ?? '', cell: (r) => time(r.out_at) },
        { id: 'flags', label: t('hr.att.flags'), value: (r) => r.late_minutes, cell: (r) => [r.late_minutes > 0 ? t('hr.att.late', { n: r.late_minutes }) : null, r.early_minutes > 0 ? t('hr.att.early', { n: r.early_minutes }) : null, r.overtime_minutes > 0 ? t('hr.att.overtime', { n: r.overtime_minutes }) : null].filter((x) => x !== null).join(' · ') || '—' },
    ];
    const e = portal.employee;

    return (
        <HrShell description={t('hr.me.description')} title={t('hr.me.title')}>
            <div className="flex flex-col gap-6" data-testid="hr-me">
                {(portal.announcements?.to_confirm ?? 0) > 0 ? <Alert title={t('hr.me.toConfirm', { n: portal.announcements?.to_confirm ?? 0 })} tone="warning" /> : null}
                {(portal.conduct?.active_warnings ?? 0) > 0 ? <Alert title={t('hr.me.warnings', { n: portal.conduct?.active_warnings ?? 0 })} tone="info" /> : null}
                {e !== undefined ? <p className="text-sm text-muted-foreground">{e.name} · {e.number} · {t(`hr.department.${e.department}` as MessageKey)} · {e.position}</p> : null}
                <section className="flex flex-col gap-2">
                    <h2 className="text-base font-semibold">{t('hr.me.leave', { year: portal.leave?.year ?? '' })}</h2>
                    <div className="flex flex-wrap gap-3">
                        {(portal.leave?.balances ?? []).map((b) => (
                            <div className="flex min-w-40 flex-col gap-1 border border-border bg-surface p-3" key={b.type_id}>
                                <span className="text-sm text-muted-foreground">{b.name}</span>
                                <span className="text-xl font-semibold">{t('hr.leave.daysLeft', { n: b.remaining })}</span>
                            </div>
                        ))}
                    </div>
                    <p className="text-xs text-muted-foreground">{t('hr.me.leavePending', { n: portal.leave?.pending ?? 0 })} <Link className="underline" href="/hr/leave">{t('hr.me.openLeave')}</Link></p>
                </section>
                <section className="flex flex-col gap-2">
                    <div className="flex items-center justify-between gap-2"><h2 className="text-base font-semibold">{t('hr.me.schedule')}</h2><Button asChild size="sm" variant="outline"><Link href="/hr/swaps">{t('hr.me.swaps', { n: portal.swaps?.open ?? 0 })}</Link></Button></div>
                    {(portal.swaps?.to_answer ?? 0) > 0 ? <Alert title={t('hr.me.toAnswer', { n: portal.swaps?.to_answer ?? 0 })} tone="info" /> : null}
                    <DataGrid caption={t('hr.me.schedule')} columns={scheduleColumns} empty={<EmptyState illustration="checklist" title={t('hr.me.noSchedule')} />} getRowId={(r) => r.date} id="hr.me.schedule" rows={portal.schedule ?? []} testId="hr-me-schedule" />
                </section>
                <section className="flex flex-col gap-2">
                    <div className="flex items-center justify-between gap-2"><h2 className="text-base font-semibold">{t('hr.me.attendance')}</h2><Button asChild size="sm" variant="outline"><Link href="/hr/attendance">{t('hr.me.openAttendance')}</Link></Button></div>
                    <DataGrid caption={t('hr.me.attendance')} columns={attendanceColumns} empty={<EmptyState illustration="checklist" title={t('hr.att.noRows')} />} getRowId={(r) => r.date} id="hr.me.attendance" rows={portal.attendance ?? []} testId="hr-me-attendance" />
                </section>
                <section className="flex flex-col gap-2">
                    <div className="flex items-center justify-between gap-2"><h2 className="text-base font-semibold">{t('hr.slip.listTitle')}</h2><Button asChild size="sm" variant="outline"><Link href="/hr/payslips">{t('hr.me.allPayslips')}</Link></Button></div>
                    {(portal.payslips ?? []).length === 0 ? <p className="text-sm text-muted-foreground">{t('hr.slip.none')}</p> : (
                        <ul className="flex flex-col gap-1 text-sm" data-testid="hr-me-payslips">{(portal.payslips ?? []).map((s) => <li key={s.run_id}><Link className="underline" href={`/hr/payslips/${s.run_id}`}>{s.period}</Link> · {format.money(s.net_minor, portal.currency ?? 'IDR')}</li>)}</ul>
                    )}
                </section>
            </div>
        </HrShell>
    );
}
