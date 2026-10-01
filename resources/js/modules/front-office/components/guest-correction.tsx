import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

export type Corrections = {
    corrections: { field: string; old: string | null; new: string | null; reason: string; approved: boolean; by: string | null; at: string }[];
    approvals: { id: string; status: string; consumed: boolean; fields: string[] }[];
    may_correct: boolean; may_correct_identity: boolean;
};
type Guest = { full_name: string; nationality: string; id_type: string; id_number: string; id_valid_until: string | null; visa_number: string | null; address: string | null; identity_visible: boolean };

const FIELDS = ['full_name', 'nationality', 'id_type', 'id_number', 'id_valid_until', 'visa_number', 'address'] as const;
const CRITICAL = ['nationality', 'id_type', 'id_number', 'visa_number'];
const ID_TYPES = ['ktp', 'passport', 'sim', 'kitas', 'other'];

/** The corrections made to a guest's registration, and the form to make one (FR-FO-039). Only what changes is sent. */
export function GuestCorrections({ corrections, guest, stayId }: { corrections: Corrections; guest: Guest; stayId: string }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<Record<string, string> | null>(null);
    const [reason, setReason] = useState('');
    const [needsApproval, setNeedsApproval] = useState(false);
    const [intent, setIntent] = useState(() => newIdempotencyKey());
    const identityOk = corrections.may_correct_identity && guest.identity_visible;
    const label = (f: string) => t(`fo.corr.field.${f}` as 'fo.corr.field.full_name');
    const current = (f: string): string => {
        const v = { full_name: guest.full_name, nationality: guest.nationality, id_type: guest.id_type, id_number: guest.id_number, id_valid_until: guest.id_valid_until ?? '', visa_number: guest.visa_number ?? '', address: guest.address ?? '' }[f];
        return v ?? '';
    };
    const changes = (): Record<string, string | null> => {
        const out: Record<string, string | null> = {};
        if (form === null) return out;
        for (const f of FIELDS) {
            if ((CRITICAL.includes(f) || f === 'address') && !identityOk) continue;
            if (form[f] !== current(f)) out[f] = form[f] === '' ? null : form[f];
        }
        return out;
    };
    const changed = Object.keys(changes());
    const critical = changed.some((f) => CRITICAL.includes(f));
    const approved = corrections.approvals.find((a) => a.status === 'approved' && !a.consumed && a.fields.length === changed.length && changed.every((f) => a.fields.includes(f)));
    const pending = corrections.approvals.find((a) => a.status === 'pending' && a.fields.length === changed.length && changed.every((f) => a.fields.includes(f)));

    function close() {
        action.clear();
        setForm(null);
        setReason('');
        setNeedsApproval(false);
    }

    async function save() {
        const done = await action.run(`/front-office/stays/${stayId}/corrections`, {
            body: { changes: changes(), reason: reason.trim(), approval_id: approved?.id ?? null }, reload: ['stay', 'corrections'],
            onFailure: (failure) => { if (failure.kind === 'conflict' && failure.conflict?.reason === 'approval_required') setNeedsApproval(true); },
        });
        if (done !== null) { setIntent(newIdempotencyKey()); close(); }
    }

    async function requestApproval() {
        await action.run(`/front-office/stays/${stayId}/corrections/approval`, { idempotencyKey: intent, body: { changes: changes(), reason: reason.trim() }, reload: ['stay', 'corrections'] });
    }

    const show = (c: { field: string; old: string | null; new: string | null }) => t('fo.corr.row', { field: label(c.field), old: c.old ?? '—', new: c.new ?? '—' });

    return (
        <section aria-labelledby="corr-h" className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-lg font-semibold" id="corr-h">{t('fo.corr.title')}</h2>
                {corrections.may_correct ? <Button onClick={() => { action.clear(); setForm(Object.fromEntries(FIELDS.map((f) => [f, current(f)]))); }} size="sm" type="button" variant="outline">{t('fo.corr.action')}</Button> : null}
            </div>
            {corrections.corrections.length === 0 ? <p className="text-sm text-muted-foreground">{t('fo.corr.none')}</p> : (
                <ul className="divide-y divide-border border-y border-border text-sm" data-testid="corrections">{corrections.corrections.map((c, i) => (
                    <li className="flex flex-col py-1" key={i}>
                        <span>{show(c)}{c.approved ? ` (${t('fo.corr.approvedTag')})` : ''}</span>
                        <span className="text-xs text-muted-foreground">{t('fo.corr.rowMeta', { time: format.instant(c.at), name: c.by ?? '—', reason: c.reason })}</span>
                    </li>
                ))}</ul>
            )}

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={close} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    {critical && needsApproval && approved === undefined && pending === undefined ? <Button loading={action.busy} onClick={() => void requestApproval()} type="button">{t('fo.corr.requestApproval')}</Button> : null}
                    <Button disabled={changed.length === 0} loading={action.busy} onClick={() => void save()} type="button">{t('fo.corr.save')}</Button>
                </>}
                onClose={close} open={form !== null} title={t('fo.corr.dialogTitle')}
            >
                {form !== null && (
                    <div className="flex flex-col gap-3">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <p className="text-xs text-muted-foreground">{t('fo.corr.note')}</p>
                        {!identityOk ? <p className="text-xs text-muted-foreground">{t('fo.corr.identityLocked')}</p> : null}
                        {FIELDS.map((f) => {
                            const locked = (CRITICAL.includes(f) || f === 'address') && !identityOk;
                            return (
                                <FormField error={action.fieldError(f) ?? action.fieldError('changes')} key={f} label={label(f)}>
                                    {f === 'id_type'
                                        ? <Select disabled={locked} onChange={(e) => setForm({ ...form, [f]: e.target.value })} value={form[f]}>{ID_TYPES.map((x) => <option key={x} value={x}>{t(`fo.checkin.idType.${x}` as 'fo.checkin.idType.ktp')}</option>)}</Select>
                                        : <Input disabled={locked} maxLength={f === 'address' ? 500 : 150} onChange={(e) => setForm({ ...form, [f]: f === 'nationality' ? e.target.value.toUpperCase() : e.target.value })} type={f === 'id_valid_until' ? 'date' : 'text'} value={locked && f !== 'address' ? current(f) : form[f]} />}
                                </FormField>
                            );
                        })}
                        <FormField error={action.fieldError('reason')} label={t('fo.corr.reason')}><Input maxLength={300} onChange={(e) => setReason(e.target.value)} value={reason} /></FormField>
                        {critical && (needsApproval || approved !== undefined || pending !== undefined) ? <Alert title={approved !== undefined ? t('fo.corr.approved') : pending !== undefined ? t('fo.corr.requested') : t('fo.corr.approvalNeeded')} tone={approved !== undefined ? 'success' : 'warning'} /> : null}
                    </div>
                )}
            </Dialog>
        </section>
    );
}
