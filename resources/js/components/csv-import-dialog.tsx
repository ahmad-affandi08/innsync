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
    count: number;
    errors: { line: number; code: string; message: string }[];
};

type Props = {
    /** Page address that takes the file (`POST`) and gives the example file (`GET <endpoint>/template`). */
    endpoint: string;
    /** Already translated: what the dialog is about, e.g. "Import staff". */
    title: string;
    /** Already translated: the columns this file needs and the rules for them. */
    hint: string;
    open: boolean;
    onClose: () => void;
};

/** One CSV file, checked first (nothing changes), then imported; every refused row is listed with its line. Used wherever a list can be filled from a spreadsheet. */
export function CsvImportDialog({ endpoint, hint, onClose, open, title }: Props) {
    const { t } = useTranslation();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [file, setFile] = useState<File | null>(null);
    const [report, setReport] = useState<Report | null>(null);

    function close() {
        const applied = report?.status === 'applied';
        setFile(null);
        setReport(null);
        action.clear();
        onClose();
        if (applied) window.location.reload();
    }

    async function run(dryRun: boolean) {
        if (file === null) return;
        const body = new FormData();
        body.append('file', file);
        body.append('dry_run', dryRun ? '1' : '0');
        const result = await action.run<Report>(endpoint, { body });
        if (result !== null) setReport(result);
    }

    function describe(e: Report['errors'][number]): string {
        const known = t(`import.code.${e.code}` as 'import.code.empty_cell', { name: e.message });
        const text = e.code === 'refused' ? e.message : known;

        return e.line > 0 ? t('import.row', { line: e.line, message: text }) : text;
    }

    const checked = report !== null && report.dry_run && report.status === 'validated';
    const applied = report?.status === 'applied';

    return (
        <Dialog
            footer={<>
                <Button disabled={action.busy} onClick={close} type="button" variant="outline">{applied ? t('prop.import.close') : t('ui.dialog.cancel')}</Button>
                {!applied ? <Button disabled={file === null} loading={action.busy && !checked} onClick={() => void run(true)} type="button" variant={checked ? 'outline' : 'default'}>{t('import.check')}</Button> : null}
                {checked ? <Button loading={action.busy} onClick={() => void run(false)} type="button">{t('prop.import.apply')}</Button> : null}
            </>}
            onClose={close}
            open={open}
            title={title}
        >
            <div className="flex flex-col gap-3">
                <p className="text-sm text-muted-foreground">{hint}</p>
                <div><Button asChild size="sm" variant="outline"><a href={`${endpoint}/template`}>{t('import.template')}</a></Button></div>
                {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                <FormField error={action.fieldError('file')} label={t('import.file')}>
                    <Input accept=".csv,text/csv,text/plain" disabled={applied} onChange={(e) => { setFile((e.target as HTMLInputElement).files?.[0] ?? null); setReport(null); }} type="file" />
                </FormField>
                {report !== null && report.status === 'rejected' ? (
                    <Alert title={t('import.rejected', { count: report.errors.length })} tone="danger">
                        <ul className="mt-2 max-h-48 list-disc space-y-1 overflow-y-auto pl-5 text-sm">
                            {report.errors.slice(0, 100).map((e, i) => <li key={i}>{describe(e)}</li>)}
                        </ul>
                    </Alert>
                ) : null}
                {checked ? <Alert title={t('import.ok', { count: report.count })} tone="success">{t('prop.import.okHint')}</Alert> : null}
                {applied ? <Alert title={t('import.done', { count: report.count })} tone="success" /> : null}
            </div>
        </Dialog>
    );
}
