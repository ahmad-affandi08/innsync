import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { useServerAction } from '@/shared/api/use-server-action';
import { useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Report = {
    status: 'applied' | 'validated' | 'rejected';
    dry_run: boolean;
    errors: { file: string; line: number; message: string }[];
    totals: { types: number; rooms: number; rooms_per_type: Record<string, number> };
};

/** Opens the room master from two CSV files: check first (nothing changes), then import. Every bad row is listed with its file and line. */
export function RoomImportDialog({ onClose, open }: { onClose: () => void; open: boolean }) {
    const { t } = useTranslation();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [types, setTypes] = useState<File | null>(null);
    const [rooms, setRooms] = useState<File | null>(null);
    const [report, setReport] = useState<Report | null>(null);

    function reset() {
        setTypes(null);
        setRooms(null);
        setReport(null);
        action.clear();
    }

    function close() {
        const applied = report?.status === 'applied';
        reset();
        onClose();
        if (applied) window.location.reload();
    }

    async function run(dryRun: boolean) {
        if (types === null || rooms === null) return;
        const body = new FormData();
        body.append('types', types);
        body.append('rooms', rooms);
        body.append('dry_run', dryRun ? '1' : '0');
        const result = await action.run<Report>('/property/rooms/import', { body });
        if (result !== null) setReport(result);
    }

    const ready = types !== null && rooms !== null;
    const checked = report !== null && report.dry_run && report.status === 'validated';
    const applied = report?.status === 'applied';

    return (
        <Dialog
            footer={<>
                <Button disabled={action.busy} onClick={close} type="button" variant="outline">{applied ? t('prop.import.close') : t('ui.dialog.cancel')}</Button>
                {!applied ? <Button disabled={!ready} loading={action.busy && !checked} onClick={() => void run(true)} type="button" variant={checked ? 'outline' : 'default'}>{t('prop.import.check')}</Button> : null}
                {checked ? <Button loading={action.busy} onClick={() => void run(false)} type="button">{t('prop.import.apply')}</Button> : null}
            </>}
            onClose={close}
            open={open}
            title={t('prop.import.title')}
        >
            <div className="flex flex-col gap-3">
                <p className="text-sm text-muted-foreground">{t('prop.import.hint')}</p>
                <div className="flex flex-wrap gap-2">
                    <Button asChild size="sm" variant="outline"><a href="/property/rooms/import/template/types">{t('prop.import.templateTypes')}</a></Button>
                    <Button asChild size="sm" variant="outline"><a href="/property/rooms/import/template/rooms">{t('prop.import.templateRooms')}</a></Button>
                </div>
                {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                <FormField error={action.fieldError('types')} label={t('prop.import.fileTypes')}>
                    <Input accept=".csv,text/csv,text/plain" disabled={applied} onChange={(e) => { setTypes((e.target as HTMLInputElement).files?.[0] ?? null); setReport(null); }} type="file" />
                </FormField>
                <FormField error={action.fieldError('rooms')} label={t('prop.import.fileRooms')}>
                    <Input accept=".csv,text/csv,text/plain" disabled={applied} onChange={(e) => { setRooms((e.target as HTMLInputElement).files?.[0] ?? null); setReport(null); }} type="file" />
                </FormField>

                {report !== null && report.status === 'rejected' ? (
                    <Alert title={t('prop.import.rejected', { count: report.errors.length })} tone="danger">
                        <ul className="mt-2 max-h-48 list-disc space-y-1 overflow-y-auto pl-5 text-sm">
                            {report.errors.slice(0, 100).map((e, i) => <li key={i}>{e.line > 0 ? t('prop.import.row', { file: e.file, line: e.line, message: e.message }) : e.message}</li>)}
                        </ul>
                    </Alert>
                ) : null}
                {checked ? <Alert title={t('prop.import.ok', { types: report.totals.types, rooms: report.totals.rooms })} tone="success">{t('prop.import.okHint')}</Alert> : null}
                {applied ? <Alert title={t('prop.import.done', { types: report.totals.types, rooms: report.totals.rooms })} tone="success" /> : null}
            </div>
        </Dialog>
    );
}
