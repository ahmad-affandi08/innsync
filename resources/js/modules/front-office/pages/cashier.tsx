import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { ShiftActions } from '@/modules/front-office/components/shift-actions';
import { ShiftSummary, type Shift } from '@/modules/front-office/components/shift-summary';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';

type Props = { cashier: { shift: Shift | null; currency: string; require_open_shift: boolean; may_view_all: boolean } };

/** The person's own shift (FR-FO-036): open it with a float, see what went through, drop cash, and close it against the count. */
export default function CashierPage({ cashier: c }: Props) {
    const { t } = useTranslation();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [float, setFloat] = useState('');
    const [floatError, setFloatError] = useState(false);

    async function open() {
        const minor = parseMajorToMinor(float, c.currency);
        if (minor === null) { setFloatError(true); return; }
        setFloatError(false);
        await action.run('/front-office/cashier/shifts', { body: { opening_float_minor: minor }, reload: ['cashier'] });
    }

    return (
        <FrontOfficeShell description={t('fo.cash.description')} title={t('fo.cash.title')}>
            {c.may_view_all ? <div><Button asChild size="sm" variant="outline"><Link href="/front-office/cashier/shifts">{t('fo.cash.viewAll')}</Link></Button></div> : null}
            {c.require_open_shift ? <Alert title={t('fo.cash.required')} tone="info" /> : null}

            {c.shift === null ? (
                <form className="flex max-w-md flex-col gap-3" onSubmit={(e) => { e.preventDefault(); void open(); }}>
                    <p className="text-sm text-muted-foreground">{t('fo.cash.none')}</p>
                    {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                    <FormField error={floatError ? t('fo.cash.invalidAmount') : action.fieldError('opening_float')} hint={t('fo.cash.floatHint')} label={t('fo.cash.float')}><Input inputMode="decimal" onChange={(e) => setFloat(e.target.value)} value={float} /></FormField>
                    <div><Button loading={action.busy} type="submit">{t('fo.cash.open')}</Button></div>
                </form>
            ) : (
                <>
                    <h2 className="text-lg font-semibold" data-testid="shift-number">{t('fo.cash.shift', { number: c.shift.number })}</h2>
                    <ShiftActions currency={c.currency} mayClose mayDrop reload={['cashier']} shift={c.shift} />
                    <ShiftSummary currency={c.currency} shift={c.shift} />
                </>
            )}
        </FrontOfficeShell>
    );
}
