import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { ConfirmDialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { FlagsPanel, RequestsList, type RoomFlag, type RoomRequest } from '@/modules/housekeeping/components/room-annotations';
import { HousekeepingShell } from '@/modules/housekeeping/components/housekeeping-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Task = { id: string; kind: string; status: string; assigned_to: string | null; assigned_name: string | null; lock_version: number };
type Room = { room_id: string; number: string; floor: string | null; building: string | null; status: string; occupied: boolean; expected_departure: string | null; task: Task | null; requests: RoomRequest[]; flags: RoomFlag[] };
type Discrepancy = { room_id: string; number: string; rule: string; task_id: string | null; task_lock_version: number | null };
type Board = { rooms: Room[]; discrepancies: Discrepancy[]; flag_kinds: string[]; staff: { id: string; name: string }[]; inspection_required: boolean; may: { manage: boolean; inspect: boolean; waive: boolean; settings: boolean } };
type Finding = { id: string; description: string; mandatory: boolean };
type RoomDetail = { room_id: string; number: string; status: string; open_findings: Finding[] };

export const statusTone: Record<string, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = { ready: 'success', clean: 'info', cleaning: 'info', dirty: 'warning', rework: 'danger' };

export default function HousekeepingBoardPage({ board }: { board: Board }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const detail = useServerAction();
    const reload = ['board'];
    const [assignTo, setAssignTo] = useState<Record<string, string>>({});
    const [request, setRequest] = useState<Room | null>(null);
    const [requestForm, setRequestForm] = useState({ kind: 'vacant', reason: '' });
    const [inspecting, setInspecting] = useState<RoomDetail | null>(null);
    const [pass, setPass] = useState(true);
    const [findings, setFindings] = useState<string[]>(['']);
    const [waiveReason, setWaiveReason] = useState<Record<string, string>>({});
    const [setting, setSetting] = useState({ required: board.inspection_required, reason: '' });
    const [saved, setSaved] = useState(false);

    async function assign(task: Task) {
        const to = assignTo[task.id];
        if (to === undefined || to === '') return;
        await action.run(`/housekeeping/tasks/${task.id}/assign`, { body: { assigned_to: to, lock_version: task.lock_version }, reload });
    }

    async function cancel(task: Task) {
        await action.run(`/housekeeping/tasks/${task.id}/cancel`, { body: { reason: 'Cancelled by the supervisor', lock_version: task.lock_version }, reload });
    }

    async function raiseFlag(room: Room, kind: string, note: string) {
        await action.run('/housekeeping/flags', { body: { room_id: room.room_id, kind, note: note.trim() || null }, reload });
    }

    async function endFlag(flag: RoomFlag) {
        await action.run(`/housekeeping/flags/${flag.id}/end`, { body: { lock_version: flag.lock_version }, reload });
    }

    async function cancelDiscrepancy(d: Discrepancy) {
        if (d.task_id === null || d.task_lock_version === null) return;
        await action.run(`/housekeeping/tasks/${d.task_id}/cancel`, { body: { reason: 'Front desk and housekeeping disagreed about this room', lock_version: d.task_lock_version }, reload });
    }

    async function createRequest() {
        if (request === null) return;
        const done = await action.run('/housekeeping/tasks', { body: { room_id: request.room_id, kind: requestForm.kind, reason: requestForm.reason }, reload });
        if (done !== null) { setRequest(null); setRequestForm({ kind: 'vacant', reason: '' }); }
    }

    async function openInspection(room: Room) {
        const done = await detail.run<{ room: RoomDetail }>(`/housekeeping/rooms/${room.room_id}`, { method: 'GET' });
        if (done !== null) { setInspecting(done.room); setPass(true); setFindings(['']); action.clear(); }
    }

    async function submitInspection() {
        if (inspecting === null) return;
        const list = pass ? [] : findings.filter((f) => f.trim() !== '').map((description) => ({ description, mandatory: true }));
        const done = await action.run(`/housekeeping/rooms/${inspecting.room_id}/inspections`, { body: { passed: pass, findings: list }, reload });
        if (done !== null) setInspecting(null);
    }

    async function waive(finding: Finding) {
        const done = await action.run(`/housekeeping/findings/${finding.id}/waive`, { body: { reason: waiveReason[finding.id] ?? '' } });
        if (done !== null && inspecting !== null) setInspecting({ ...inspecting, open_findings: inspecting.open_findings.filter((f) => f.id !== finding.id) });
    }

    async function saveSetting() {
        const done = await action.run('/housekeeping/settings', { body: { inspection_required: setting.required, lock_version: 0, reason: setting.reason }, reload });
        if (done !== null) setSaved(true);
    }

    const occupancy = (r: Room) => (r.occupied && r.expected_departure !== null ? t('hk.board.occupied', { date: format.date(r.expected_departure) }) : t('hk.board.vacant'));
    const taskText = (r: Room) => (r.task === null ? '' : `${t(`hk.kind.${r.task.kind}` as 'hk.kind.departure')} · ${t(`hk.task.${r.task.status}` as 'hk.task.open')}${r.task.assigned_name !== null ? ` · ${r.task.assigned_name}` : ''}`);
    const columns: DataGridColumn<Room>[] = [
        {
            id: 'room', label: t('hk.board.room'), value: (r) => r.number, searchText: (r) => `${r.number} ${r.building ?? ''} ${r.floor ?? ''}`, rowHeader: true,
            cell: (r) => <>{r.number}{r.building !== null || r.floor !== null ? <span className="ml-1 text-xs font-normal text-muted-foreground">· {[r.building, r.floor].filter((x) => x !== null).join(' · ')}</span> : null}</>,
        },
        { id: 'state', label: t('hk.board.state'), value: (r) => r.status, filter: 'select', filterLabel: (v) => t(`hk.status.${v}` as 'hk.status.dirty'), cell: (r) => <StatusBadge label={t(`hk.status.${r.status}` as 'hk.status.dirty')} tone={statusTone[r.status] ?? 'neutral'} /> },
        { id: 'occupancy', label: t('hk.board.occupancy'), value: occupancy },
        {
            id: 'task', label: t('hk.board.task'), value: taskText,
            cell: (r) => (
                <>
                    {r.requests.length > 0 || r.flags.length > 0 || r.occupied ? (
                        <div className="mb-2 flex flex-col gap-1">
                            <RequestsList requests={r.requests} />
                            {r.occupied ? <FlagsPanel busy={action.busy} flags={r.flags} kinds={board.flag_kinds} onEnd={(f) => void endFlag(f)} onRaise={(k, n) => void raiseFlag(r, k, n)} /> : null}
                        </div>
                    ) : null}
                    {r.task === null ? '—' : (
                        <div className="flex flex-col gap-1">
                            <span>{taskText(r)}</span>
                            {board.may.manage && r.task.status !== 'in_progress' ? (
                                <div className="flex flex-wrap items-center gap-2">
                                    <Select aria-label={t('hk.board.assignTo')} className="min-h-9 w-44" onChange={(e) => setAssignTo({ ...assignTo, [r.task!.id]: e.target.value })} value={assignTo[r.task.id] ?? ''}>
                                        <option value="">{t('hk.board.chooseStaff')}</option>
                                        {board.staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                    </Select>
                                    <Button disabled={action.busy || (assignTo[r.task.id] ?? '') === ''} onClick={() => void assign(r.task!)} size="sm" type="button" variant="outline">{t('hk.board.assign')}</Button>
                                    <Button disabled={action.busy} onClick={() => void cancel(r.task!)} size="sm" type="button" variant="outline">{t('hk.board.cancel')}</Button>
                                </div>
                            ) : null}
                        </div>
                    )}
                </>
            ),
        },
        ...(board.may.manage || board.may.inspect ? [{
            id: 'actions', label: t('hk.board.actions'),
            cell: (r: Room) => (
                <div className="flex flex-wrap gap-2">
                    {board.may.manage && r.task === null ? <Button onClick={() => { action.clear(); setRequest(r); }} size="sm" type="button" variant="outline">{t('hk.board.request')}</Button> : null}
                    {board.may.inspect && r.status === 'clean' ? <Button onClick={() => void openInspection(r)} size="sm" type="button">{t('hk.board.inspect')}</Button> : null}
                </div>
            ),
        }] : []),
    ];

    return (
        <HousekeepingShell description={t('hk.board.description')} title={t('hk.board.title')} wide>
            {action.error !== null && request === null && inspecting === null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            {board.staff.length === 0 && board.may.manage ? <Alert title={t('hk.board.noStaff')} tone="warning" /> : null}

            {board.discrepancies.length > 0 ? (
                <section aria-labelledby="disc-h" className="flex flex-col gap-2" data-testid="discrepancies">
                    <h2 className="text-lg font-semibold" id="disc-h">{t('hk.disc.title')}</h2>
                    {board.discrepancies.map((d) => (
                        <Alert actions={board.may.manage && d.task_id !== null ? <Button disabled={action.busy} onClick={() => void cancelDiscrepancy(d)} size="sm" type="button" variant="outline">{t('hk.disc.cancel')}</Button> : undefined} key={d.room_id} title={t(`hk.disc.rule.${d.rule}` as 'hk.disc.rule.occupied_with_vacant_task', { room: d.number })} tone="warning" />
                    ))}
                </section>
            ) : null}

            <DataGrid
                caption={t('hk.board.title')}
                columns={columns}
                empty={<EmptyState title={t('hk.board.empty')} />}
                getRowId={(r) => r.room_id}
                id="hk.board"
                rows={board.rooms}
            />

            {board.may.settings && (
                <section aria-labelledby="set-h" className="flex flex-col gap-3 border-t border-border pt-4">
                    <h2 className="text-lg font-semibold" id="set-h">{t('hk.settings.title')}</h2>
                    {saved ? <Alert title={t('hk.board.saved')} tone="success" /> : null}
                    <label className="flex items-center gap-2 text-sm"><input checked={setting.required} onChange={(e) => setSetting({ ...setting, required: e.target.checked })} type="checkbox" />{t('hk.settings.required')}</label>
                    <FormField field="reason" error={action.fieldError('reason')} label={t('hk.settings.reason')}><Input maxLength={300} onChange={(e) => setSetting({ ...setting, reason: e.target.value })} value={setting.reason} /></FormField>
                    <div><Button disabled={action.busy || setting.reason.trim() === ''} onClick={() => void saveSetting()} type="button" variant="outline">{t('hk.settings.save')}</Button></div>
                </section>
            )}

            <ConfirmDialog cancelLabel={t('ui.dialog.cancel')} confirmLabel={t('hk.board.requestSave')} consequence={t('hk.board.cancelConsequence')} onCancel={() => setRequest(null)} onConfirm={() => void createRequest()} open={request !== null} pending={action.busy} title={`${t('hk.board.request')}: ${request?.number ?? ''}`}>
                <div className="flex flex-col gap-3">
                    {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                    <FormField field="kind" error={action.fieldError('kind')} label={t('hk.board.requestKind')}>
                        <Select onChange={(e) => setRequestForm({ ...requestForm, kind: e.target.value })} value={requestForm.kind}>{['vacant', 'request', 'stayover'].map((k) => <option key={k} value={k}>{t(`hk.kind.${k}` as 'hk.kind.vacant')}</option>)}</Select>
                    </FormField>
                    <FormField field="reason" error={action.fieldError('reason')} label={t('hk.board.requestReason')}><Input maxLength={300} onChange={(e) => setRequestForm({ ...requestForm, reason: e.target.value })} value={requestForm.reason} /></FormField>
                </div>
            </ConfirmDialog>

            <ConfirmDialog
                cancelLabel={t('ui.dialog.cancel')}
                confirmLabel={t('hk.inspect.submit')}
                consequence={pass ? t('hk.inspect.consequencePass') : t('hk.inspect.consequenceFail')}
                onCancel={() => setInspecting(null)}
                onConfirm={() => void submitInspection()}
                open={inspecting !== null}
                pending={action.busy}
                title={t('hk.inspect.title', { room: inspecting?.number ?? '' })}
            >
                <div className="flex flex-col gap-3">
                    {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                    {inspecting !== null && inspecting.open_findings.length > 0 && (
                        <div className="flex flex-col gap-2">
                            <p className="text-sm font-medium">{t('hk.inspect.openFindings')}</p>
                            {inspecting.open_findings.map((f) => (
                                <div className="flex flex-col gap-1 border border-border p-2 text-sm" key={f.id}>
                                    <span>{f.description}{f.mandatory ? ` (${t('hk.inspect.mandatoryTag')})` : ''}</span>
                                    {board.may.waive ? (
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Input aria-label={t('hk.inspect.waiveReason')} className="min-h-9 w-56" maxLength={300} onChange={(e) => setWaiveReason({ ...waiveReason, [f.id]: e.target.value })} placeholder={t('hk.inspect.waiveReason')} value={waiveReason[f.id] ?? ''} />
                                            <Button disabled={action.busy || (waiveReason[f.id] ?? '').trim() === ''} onClick={() => void waive(f)} size="sm" type="button" variant="outline">{t('hk.inspect.waive')}</Button>
                                        </div>
                                    ) : null}
                                </div>
                            ))}
                        </div>
                    )}
                    <label className="flex items-center gap-2 text-sm"><input checked={pass} name="result" onChange={() => setPass(true)} type="radio" />{t('hk.inspect.pass')}</label>
                    <label className="flex items-center gap-2 text-sm"><input checked={!pass} name="result" onChange={() => setPass(false)} type="radio" />{t('hk.inspect.fail')}</label>
                    {!pass && (
                        <div className="flex flex-col gap-2">
                            {findings.map((f, i) => (
                                <FormField field="findings" error={i === 0 ? action.fieldError('findings') : undefined} key={i} label={`${t('hk.inspect.finding')} ${i + 1}`}><Input maxLength={300} onChange={(e) => setFindings(findings.map((x, j) => (j === i ? e.target.value : x)))} value={f} /></FormField>
                            ))}
                            <div><Button onClick={() => setFindings([...findings, ''])} size="sm" type="button" variant="outline">{t('hk.inspect.addFinding')}</Button></div>
                        </div>
                    )}
                </div>
            </ConfirmDialog>
        </HousekeepingShell>
    );
}
