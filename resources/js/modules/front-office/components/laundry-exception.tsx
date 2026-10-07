import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

export type LaundryHold = {
    orders: number;
    exception: { mode: string; reason: string; orders: number; at: string } | null;
    approvals: { id: string; status: string; consumed: boolean; mode: string | null; reason: string | null }[];
};
export type LaundryChoice = { mode: string; reason: string; approvalId: string };

export const NO_LAUNDRY_CHOICE: LaundryChoice = { mode: '', reason: '', approvalId: '' };

/**
 * The guest's laundry that is still in the laundry's hands when the stay is closed (FR-LDY-012). The stay cannot be closed until it is delivered or cancelled, or
 * until a second person approves turning it into a late charge or a claim; the approved request is then picked here and sent with the check-out.
 */
export function LaundryExceptionPanel({ choice, laundry, onChange, stayId }: { choice: LaundryChoice; laundry: LaundryHold; onChange: (next: LaundryChoice) => void; stayId: string }) {
    const { t } = useTranslation();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [mode, setMode] = useState('late_charge');
    const [reason, setReason] = useState('');
    const [intent, setIntent] = useState(() => newIdempotencyKey());
    const usable = laundry.approvals.filter((a) => a.status === 'approved' && !a.consumed && a.mode !== null && a.reason !== null);
    const pending = laundry.approvals.filter((a) => a.status === 'pending' && !a.consumed);

    async function ask() {
        const done = await action.run(`/front-office/stays/${stayId}/laundry-exception/approval`, { body: { mode, reason: reason.trim() }, idempotencyKey: intent, reload: ['stay'] });
        if (done !== null) { setIntent(newIdempotencyKey()); setReason(''); }
    }

    function pick(id: string) {
        const approval = usable.find((a) => a.id === id);
        onChange(approval === undefined ? NO_LAUNDRY_CHOICE : { mode: approval.mode ?? '', reason: approval.reason ?? '', approvalId: approval.id });
    }

    if (laundry.orders === 0) return null;

    return (
        <div className="flex flex-col gap-3 border border-border p-3" data-testid="laundry-exception">
            <Alert title={t('fo.stay.laundry.hold', { count: laundry.orders })} tone="warning">{t('fo.stay.laundry.explain')}</Alert>
            {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            <fieldset className="grid gap-3 sm:grid-cols-2">
                <legend className="mb-1 text-sm font-medium">{t('fo.stay.laundry.ask')}</legend>
                <FormField error={action.fieldError('mode')} field="mode" label={t('fo.stay.laundry.mode')}>
                    <Select onChange={(e) => setMode(e.target.value)} value={mode}>
                        <option value="late_charge">{t('fo.stay.laundry.lateCharge')}</option>
                        <option value="claim">{t('fo.stay.laundry.claim')}</option>
                    </Select>
                </FormField>
                <FormField error={action.fieldError('reason')} field="reason" label={t('fo.stay.laundry.reason')}><Input maxLength={300} onChange={(e) => setReason(e.target.value)} value={reason} /></FormField>
                <div className="sm:col-span-2"><Button disabled={reason.trim() === ''} loading={action.busy} onClick={() => void ask()} size="sm" type="button" variant="outline">{t('fo.stay.laundry.request')}</Button></div>
            </fieldset>
            {pending.length > 0 ? <p className="text-sm text-muted-foreground">{t('fo.stay.laundry.pending', { count: pending.length })}</p> : null}
            <FormField label={t('fo.stay.laundry.approved')} field="approval_id">
                <Select disabled={usable.length === 0} onChange={(e) => pick(e.target.value)} value={choice.approvalId}>
                    <option value="">{usable.length === 0 ? t('fo.stay.laundry.noneApproved') : t('fo.stay.laundry.pick')}</option>
                    {usable.map((a) => <option key={a.id} value={a.id}>{t(a.mode === 'claim' ? 'fo.stay.laundry.claim' : 'fo.stay.laundry.lateCharge')} · {a.reason}</option>)}
                </Select>
            </FormField>
        </div>
    );
}
