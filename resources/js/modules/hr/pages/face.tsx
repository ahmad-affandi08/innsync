import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { HrShell } from '@/modules/hr/components/hr-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { descriptorOf } from '@/shared/lib/face';

type Person = { id: string; number: string; name: string; department: string; position: string; enrolled_at: string | null };
type Props = { overview: { samples: number; employees: Person[] }; mode: 'off' | 'flag' | 'require' };
type Shot = 'empty' | 'reading' | 'found' | 'none' | 'error';

/** Registering the faces used for attendance: done in person, with the employee there and agreeing; only numbers are kept, never the photos. */
export default function FacePage({ overview, mode }: Props) {
    const { t } = useTranslation();
    const format = useFormatters();
    const action = useServerAction();
    const errorCopy = useErrorStateCopy();
    const [adding, setAdding] = useState<Person | null>(null);
    const [removing, setRemoving] = useState<{ person: Person; reason: string } | null>(null);
    const [agreed, setAgreed] = useState(false);
    const [shots, setShots] = useState<{ state: Shot; face: number[] | null }[]>([]);
    const [key, setKey] = useState(0);

    const start = (person: Person) => {
        action.clear();
        setAgreed(false);
        setShots(Array.from({ length: overview.samples }, () => ({ state: 'empty', face: null })));
        setKey((k) => k + 1);
        setAdding(person);
    };

    async function read(index: number, file: File | undefined) {
        const set = (state: Shot, face: number[] | null) => setShots((all) => all.map((s, i) => (i === index ? { state, face } : s)));

        if (file === undefined) return set('empty', null);

        set('reading', null);

        try {
            const face = await descriptorOf(file);

            set(face === null ? 'none' : 'found', face);
        } catch {
            set('error', null);
        }
    }

    async function save() {
        if (adding === null) return;

        const done = await action.run(`/hr/face/${adding.id}`, { body: { samples: shots.map((s) => s.face), agreed }, reload: ['overview'] });

        if (done !== null) setAdding(null);
    }

    async function remove() {
        if (removing === null) return;

        const done = await action.run(`/hr/face/${removing.person.id}`, { method: 'DELETE', body: { reason: removing.reason }, reload: ['overview'] });

        if (done !== null) setRemoving(null);
    }

    const ready = agreed && shots.length > 0 && shots.every((s) => s.state === 'found');
    const enrolled = overview.employees.filter((p) => p.enrolled_at !== null).length;

    return (
        <HrShell description={t('hr.face.description')} title={t('hr.face.title')}>
            <Alert title={t(`hr.face.mode.${mode}` as 'hr.face.mode.off')} tone={mode === 'off' ? 'warning' : 'info'}>{t('hr.face.modeHint')}</Alert>
            <p className="max-w-3xl text-sm text-muted-foreground">{t('hr.face.privacy')}</p>
            <p className="text-sm font-medium">{t('hr.face.count', { done: enrolled, total: overview.employees.length })}</p>
            <div><Button asChild size="sm" variant="outline"><Link href="/hr/face/test">{t('hr.face.try')}</Link></Button></div>

            {overview.employees.length === 0 ? <EmptyState title={t('hr.face.none')} /> : (
                <ul className="divide-y divide-border border-y border-border" data-testid="face-list">
                    {overview.employees.map((p) => (
                        <li className="flex flex-wrap items-center justify-between gap-3 py-3" key={p.id}>
                            <div className="min-w-0">
                                <p className="truncate font-medium">{p.name}</p>
                                <p className="text-xs text-muted-foreground">{p.number} · {p.department} · {p.position}</p>
                            </div>
                            <div className="flex items-center gap-2">
                                {p.enrolled_at === null ? <StatusBadge label={t('hr.face.notRegistered')} tone="warning" /> : <StatusBadge label={t('hr.face.registered', { when: format.instant(`${p.enrolled_at.replace(' ', 'T')}Z`) })} tone="success" />}
                                <Button onClick={() => start(p)} size="sm" type="button" variant={p.enrolled_at === null ? 'default' : 'outline'}>{t(p.enrolled_at === null ? 'hr.face.register' : 'hr.face.again')}</Button>
                                {p.enrolled_at !== null ? <Button onClick={() => { action.clear(); setRemoving({ person: p, reason: '' }); }} size="sm" type="button" variant="outline">{t('hr.face.remove')}</Button> : null}
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setAdding(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={!ready} loading={action.busy} onClick={() => void save()} type="button">{t('hr.face.save')}</Button></>}
                onClose={() => setAdding(null)}
                open={adding !== null}
                title={t('hr.face.dialogTitle', { name: adding?.name ?? '' })}
            >
                <div className="flex flex-col gap-3">
                    <p className="text-sm text-muted-foreground">{t('hr.face.howTo', { n: overview.samples })}</p>
                    {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                    {shots.map((shot, i) => (
                        <FormField field={`samples.${i}`} key={`${key}-${i}`} label={t('hr.face.photo', { n: i + 1 })}>
                            <div className="flex flex-col gap-1">
                                <Input accept="image/*" capture="user" onChange={(e) => void read(i, e.target.files?.[0])} type="file" />
                                <span className="text-xs text-muted-foreground">{t(`hr.face.shot.${shot.state}` as 'hr.face.shot.empty')}</span>
                            </div>
                        </FormField>
                    ))}
                    <label className="flex items-start gap-2 text-sm"><input checked={agreed} className="mt-1" onChange={(e) => setAgreed(e.target.checked)} type="checkbox" />{t('hr.face.agreed')}</label>
                </div>
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setRemoving(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={removing?.reason.trim() === ''} loading={action.busy} onClick={() => void remove()} type="button">{t('hr.face.remove')}</Button></>}
                onClose={() => setRemoving(null)}
                open={removing !== null}
                title={t('hr.face.removeTitle', { name: removing?.person.name ?? '' })}
            >
                {removing !== null ? (
                    <div className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">{t('hr.face.removeHint')}</p>
                        <FormField error={action.fieldError('reason')} field="reason" label={t('hr.face.reason')}><Input maxLength={200} onChange={(e) => setRemoving({ ...removing, reason: e.target.value })} value={removing.reason} /></FormField>
                    </div>
                ) : null}
            </Dialog>
        </HrShell>
    );
}
