import { Link } from '@inertiajs/react';

import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { EmptyState } from '@/components/ui/empty-state';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Row = { id: string; room_number: string | null; expected_departure: string; guest: { full_name: string; nationality: string } };

export default function StaysPage({ stays }: { stays: Row[] }) {
    const { t } = useTranslation();
    const format = useFormatters();

    const columns: DataGridColumn<Row>[] = [
        { id: 'room', label: t('fo.stays.room'), value: (s) => s.room_number, rowHeader: true, cell: (s) => <Link className="font-medium underline-offset-2 hover:underline" href={`/front-office/stays/${s.id}`}>{s.room_number}</Link> },
        { id: 'guest', label: t('fo.stays.guest'), value: (s) => s.guest.full_name, searchText: (s) => `${s.guest.full_name} ${s.guest.nationality}`, cell: (s) => <>{s.guest.full_name} <span className="text-xs text-muted-foreground">({s.guest.nationality})</span></> },
        { id: 'departure', label: t('fo.stays.departure'), value: (s) => s.expected_departure, searchText: (s) => `${s.expected_departure} ${format.date(s.expected_departure)}`, cell: (s) => format.date(s.expected_departure) },
    ];

    return (
        <FrontOfficeShell description={t('fo.stays.description')} title={t('fo.stays.title')}>
            <DataGrid
                caption={t('fo.stays.title')}
                columns={columns}
                empty={<EmptyState title={t('fo.stays.empty')} />}
                getRowId={(s) => s.id}
                id="fo.stays"
                rows={stays}
            />
        </FrontOfficeShell>
    );
}
