import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Row = { id: string; number: string; cashier_name: string | null; status: 'open' | 'closed'; opened_at: string; variance_minor: number | null };
type Props = {
    list: { shifts: Row[]; currency: string }; filters: { status: string; cashier: string };
    settings: { require_open_shift: boolean; lock_version: number } | null;
};

/** Every shift with its cash difference, and for those who may, the switch that requires an open shift to take money. */
export default function CashierShiftsPage({ filters, list, settings }: Props) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [status, setStatus] = useState(filters.status);
    const [require, setRequire] = useState(settings?.require_open_shift ?? false);
    const [reason, setReason] = useState('');
    const [saved, setSaved] = useState(false);

    async function save() {
        if (settings === null) return;
        const done = await action.run('/front-office/cashier/settings', { body: { require_open_shift: require, lock_version: settings.lock_version, reason: reason.trim() }, reload: ['settings'] });
        if (done !== null) { setSaved(true); setReason(''); }
    }

    return (
        <FrontOfficeShell description={t('fo.cash.list.description')} title={t('fo.cash.list.title')} wide>
            <div><Button asChild size="sm" variant="outline"><Link href="/front-office/cashier">{t('fo.cash.mine')}</Link></Button></div>
            <form className="flex flex-wrap items-end gap-3" onSubmit={(e) => { e.preventDefault(); router.get('/front-office/cashier/shifts', status === '' ? {} : { status }); }}>
                <FormField label={t('fo.cash.list.filter')}>
                    <Select onChange={(e) => setStatus(e.target.value)} value={status}>
                        <option value="">{t('fo.cash.list.all')}</option><option value="open">{t('fo.cash.status.open')}</option><option value="closed">{t('fo.cash.status.closed')}</option>
                    </Select>
                </FormField>
                <Button size="sm" type="submit" variant="outline">{t('fo.cash.list.apply')}</Button>
            </form>

            {list.shifts.length === 0 ? <EmptyState title={t('fo.cash.list.empty')} /> : (
                <table className="w-full text-left text-sm" data-testid="shift-list">
                    <thead><tr className="text-xs text-muted-foreground"><th className="py-1 font-medium" scope="col">{t('fo.cash.list.col.number')}</th><th scope="col">{t('fo.cash.list.col.cashier')}</th><th scope="col">{t('fo.cash.list.col.opened')}</th><th scope="col">{t('fo.cash.list.col.status')}</th><th scope="col">{t('fo.cash.list.col.variance')}</th></tr></thead>
                    <tbody>{list.shifts.map((s) => (
                        <tr className="border-t border-border" key={s.id}>
                            <th className="py-1 font-medium" scope="row"><Link className="underline-offset-2 hover:underline" href={`/front-office/cashier/shifts/${s.id}`}>{s.number}</Link></th>
                            <td>{s.cashier_name ?? '—'}</td><td>{format.instant(s.opened_at)}</td>
                            <td><StatusBadge label={t(`fo.cash.status.${s.status}` as 'fo.cash.status.open')} tone={s.status === 'open' ? 'info' : 'neutral'} /></td>
                            <td className={s.variance_minor !== null && s.variance_minor !== 0 ? 'font-medium text-danger' : undefined}>{s.variance_minor === null ? '—' : format.money(s.variance_minor, list.currency)}</td>
                        </tr>
                    ))}</tbody>
                </table>
            )}

            {settings !== null && (
                <section aria-labelledby="cash-set-h" className="flex max-w-lg flex-col gap-3 border-t border-border pt-4">
                    <h2 className="text-lg font-semibold" id="cash-set-h">{t('fo.cash.settings.title')}</h2>
                    {saved ? <Alert title={t('fo.cash.settings.saved')} tone="success" /> : null}
                    {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                    <label className="flex items-center gap-2 text-sm"><input checked={require} onChange={(e) => { setRequire(e.target.checked); setSaved(false); }} type="checkbox" />{t('fo.cash.settings.require')}</label>
                    <FormField error={action.fieldError('reason')} label={t('fo.cash.settings.reason')}><Input maxLength={300} onChange={(e) => setReason(e.target.value)} value={reason} /></FormField>
                    <div><Button loading={action.busy} onClick={() => void save()} size="sm" type="button">{t('fo.cash.settings.save')}</Button></div>
                </section>
            )}
        </FrontOfficeShell>
    );
}
