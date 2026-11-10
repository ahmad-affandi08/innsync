import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Guest = { guest_name: string; stays: number; nights: number; last_arrival: string; last_departure: string; last_reservation_id: string; upcoming: number; flag: 'vip' | 'attention' | 'do_not_rent' | null; note: string | null };

/** The guests the property has hosted, so a returning guest is recognised. Contact and identity details stay behind the reservation. */
export default function GuestsPage({ guests, query }: { guests: Guest[]; query: string }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const [text, setText] = useState(query);

    const columns: DataGridColumn<Guest>[] = [
        {
            id: 'name', label: t('fo.guests.name'), rowHeader: true, value: (g) => g.guest_name, searchText: (g) => `${g.guest_name} ${g.note ?? ''}`,
            cell: (g) => (
                <span>
                    <span className="font-medium">{g.guest_name}</span>
                    {g.stays > 1 ? <span className="ml-2 border border-border px-1.5 py-0.5 text-xs font-normal text-muted-foreground">{t('fo.guests.returning')}</span> : null}
                    {g.upcoming > 0 ? <span className="ml-2 text-xs font-normal text-muted-foreground">{t('fo.guests.upcoming', { count: g.upcoming })}</span> : null}
                    {g.flag !== null ? <span className="ml-2 border border-foreground px-1.5 py-0.5 text-xs font-semibold">{t(`fo.gnote.flag.${g.flag}` as 'fo.gnote.flag.vip')}</span> : null}
                    {g.note !== null ? <span className="block max-w-md truncate text-xs font-normal text-muted-foreground" title={g.note}>{g.note}</span> : null}
                </span>
            ),
        },
        { id: 'stays', label: t('fo.guests.stays'), align: 'right', value: (g) => g.stays, cell: (g) => <span className="tabular-nums">{g.stays}</span> },
        { id: 'nights', label: t('fo.guests.nights'), align: 'right', value: (g) => g.nights, cell: (g) => <span className="tabular-nums">{g.nights}</span> },
        { id: 'last', label: t('fo.guests.last'), value: (g) => g.last_arrival, searchText: (g) => `${format.date(g.last_arrival)} ${format.date(g.last_departure)}`, cell: (g) => `${format.date(g.last_arrival)} – ${format.date(g.last_departure)}` },
        { id: 'open', label: '', value: () => '', sortable: false, cell: (g) => <Button asChild size="sm" variant="outline"><Link href={`/front-office/reservations/${g.last_reservation_id}`}>{t('fo.guests.open')}</Link></Button> },
    ];

    return (
        <FrontOfficeShell description={t('fo.guests.description')} title={t('fo.guests.title')} wide>
            <form className="flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); router.get('/front-office/guests', text.trim() === '' ? {} : { query: text.trim() }, { preserveState: true }); }}>
                <div className="min-w-0 flex-1 basis-64"><Input aria-label={t('fo.guests.search')} onChange={(e) => setText(e.target.value)} placeholder={t('fo.guests.search')} value={text} /></div>
                <Button type="submit" variant="outline">{t('fo.guests.find')}</Button>
            </form>
            <DataGrid caption={t('fo.guests.title')} columns={columns} empty={<EmptyState title={t('fo.guests.empty')} />} getRowId={(g) => g.last_reservation_id} id="fo.guests" rows={guests} testId="guest-list" />
            <p className="text-xs text-muted-foreground">{t('fo.guests.privacy')}</p>
        </FrontOfficeShell>
    );
}
