import { Link } from '@inertiajs/react';

import { EmptyState } from '@/components/ui/empty-state';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Row = { id: string; room_number: string | null; expected_departure: string; guest: { full_name: string; nationality: string } };

export default function StaysPage({ stays }: { stays: Row[] }) {
    const { t } = useTranslation();
    const format = useFormatters();

    return (
        <FrontOfficeShell description={t('fo.stays.description')} title={t('fo.stays.title')}>
            {stays.length === 0 ? <EmptyState title={t('fo.stays.empty')} /> : (
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead><tr className="text-xs text-muted-foreground"><th className="py-1 font-medium" scope="col">{t('fo.stays.room')}</th><th scope="col">{t('fo.stays.guest')}</th><th scope="col">{t('fo.stays.departure')}</th></tr></thead>
                        <tbody>{stays.map((s) => (
                            <tr className="border-t border-border" key={s.id}>
                                <td className="py-2"><Link className="font-medium underline-offset-2 hover:underline" href={`/front-office/stays/${s.id}`}>{s.room_number}</Link></td>
                                <td>{s.guest.full_name} <span className="text-xs text-muted-foreground">({s.guest.nationality})</span></td>
                                <td>{format.date(s.expected_departure)}</td>
                            </tr>
                        ))}</tbody>
                    </table>
                </div>
            )}
        </FrontOfficeShell>
    );
}
