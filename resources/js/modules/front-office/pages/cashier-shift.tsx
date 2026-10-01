import { Link } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { ShiftActions } from '@/modules/front-office/components/shift-actions';
import { ShiftSummary, type Shift } from '@/modules/front-office/components/shift-summary';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { useTranslation } from '@/shared/i18n/i18n';

type Props = { detail: { shift: Shift; currency: string; may_close: boolean; may_drop: boolean } };

/** One shift for review (or the cashier's own closed shift), printable. */
export default function CashierShiftPage({ detail: d }: Props) {
    const { t } = useTranslation();

    return (
        <FrontOfficeShell description={t('fo.cash.detail.description')} title={t('fo.cash.detail.title', { number: d.shift.number })}>
            <div className="flex flex-wrap gap-2 print:hidden">
                <Button asChild size="sm" variant="outline"><Link href="/front-office/cashier/shifts">{t('fo.cash.viewAll')}</Link></Button>
                <Button onClick={() => window.print()} size="sm" type="button" variant="outline">{t('fo.cash.print')}</Button>
            </div>
            <ShiftActions currency={d.currency} mayClose={d.may_close} mayDrop={d.may_drop} reload={['detail']} shift={d.shift} />
            <ShiftSummary currency={d.currency} shift={d.shift} />
        </FrontOfficeShell>
    );
}
