import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Guest = { guest_name: string; stays: number; nights: number; last_arrival: string; last_departure: string; last_reservation_id: string; upcoming: number; flag: 'vip' | 'attention' | null; note: string | null };

/** The guests the property has hosted, so a returning guest is recognised. Contact and identity details stay behind the reservation. */
export default function GuestsPage({ guests, query }: { guests: Guest[]; query: string }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const [text, setText] = useState(query);

    return (
        <FrontOfficeShell description={t('fo.guests.description')} title={t('fo.guests.title')} wide>
            <form className="flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); router.get('/front-office/guests', text.trim() === '' ? {} : { query: text.trim() }, { preserveState: true }); }}>
                <div className="min-w-0 flex-1 basis-64"><Input aria-label={t('fo.guests.search')} onChange={(e) => setText(e.target.value)} placeholder={t('fo.guests.search')} value={text} /></div>
                <Button type="submit" variant="outline">{t('fo.guests.find')}</Button>
            </form>
            {guests.length === 0 ? <EmptyState title={t('fo.guests.empty')} /> : (
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm" data-testid="guest-list">
                        <thead>
                            <tr className="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                                <th className="py-2 pr-4 font-medium" scope="col">{t('fo.guests.name')}</th>
                                <th className="py-2 pr-4 text-right font-medium" scope="col">{t('fo.guests.stays')}</th>
                                <th className="py-2 pr-4 text-right font-medium" scope="col">{t('fo.guests.nights')}</th>
                                <th className="py-2 pr-4 font-medium" scope="col">{t('fo.guests.last')}</th>
                                <th className="py-2 font-medium" scope="col"><span className="sr-only">{t('fo.guests.open')}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            {guests.map((g) => (
                                <tr className="border-b border-border" key={g.last_reservation_id}>
                                    <td className="py-2 pr-4 font-medium">{g.guest_name}{g.stays > 1 ? <span className="ml-2 border border-border px-1.5 py-0.5 text-xs font-normal text-muted-foreground">{t('fo.guests.returning')}</span> : null}{g.upcoming > 0 ? <span className="ml-2 text-xs font-normal text-muted-foreground">{t('fo.guests.upcoming', { count: g.upcoming })}</span> : null}{g.flag !== null ? <span className="ml-2 border border-foreground px-1.5 py-0.5 text-xs font-semibold">{t(`fo.gnote.flag.${g.flag}` as 'fo.gnote.flag.vip')}</span> : null}{g.note !== null ? <span className="block max-w-md truncate text-xs font-normal text-muted-foreground" title={g.note}>{g.note}</span> : null}</td>
                                    <td className="py-2 pr-4 text-right tabular-nums">{g.stays}</td>
                                    <td className="py-2 pr-4 text-right tabular-nums">{g.nights}</td>
                                    <td className="py-2 pr-4">{format.date(g.last_arrival)} – {format.date(g.last_departure)}</td>
                                    <td className="py-2 text-right"><Button asChild size="sm" variant="outline"><Link href={`/front-office/reservations/${g.last_reservation_id}`}>{t('fo.guests.open')}</Link></Button></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            <p className="text-xs text-muted-foreground">{t('fo.guests.privacy')}</p>
        </FrontOfficeShell>
    );
}
