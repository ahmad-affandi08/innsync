import { useEffect, useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { PropertyShell } from '@/modules/property/components/property-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type State = { has_logo: boolean; version: string | null };

const MAX_BYTES = 512 * 1024;

/** The property's own logo: shown in the header and at the top of every printed document. */
export default function BrandingPage(initial: State) {
    const { t } = useTranslation();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [state, setState] = useState<State>(initial);
    const [file, setFile] = useState<File | null>(null);
    const [preview, setPreview] = useState<string | null>(null);
    const [tooBig, setTooBig] = useState(false);

    useEffect(() => {
        if (file === null) {
            setPreview(null);
            return undefined;
        }

        const url = URL.createObjectURL(file);
        setPreview(url);

        return () => URL.revokeObjectURL(url);
    }, [file]);

    function choose(next: File | null) {
        setTooBig(next !== null && next.size > MAX_BYTES);
        setFile(next !== null && next.size <= MAX_BYTES ? next : null);
        action.clear();
    }

    async function save() {
        if (file === null) return;
        const body = new FormData();
        body.append('logo', file);
        const done = await action.run<State>('/property/branding/logo', { body });
        if (done !== null) window.location.reload();
    }

    async function remove() {
        const done = await action.run<State>('/property/branding/logo', { method: 'DELETE' });
        if (done !== null) {
            setState(done);
            window.location.reload();
        }
    }

    return (
        <PropertyShell description={t('brand.description')} title={t('brand.title')}>
            <section aria-labelledby="brand-current" className="flex flex-col gap-4 border border-border bg-surface p-4 sm:p-6">
                <h2 className="text-lg font-semibold" id="brand-current">{t('brand.current')}</h2>
                {state.has_logo ? (
                    <div className="flex flex-wrap items-center gap-4">
                        <img alt={t('brand.current')} className="max-h-20 max-w-[16rem] border border-border bg-white p-2 object-contain" data-testid="current-logo" src={`/property/logo?v=${state.version ?? ''}`} />
                        <Button disabled={action.busy} onClick={() => void remove()} type="button" variant="outline">{t('brand.remove')}</Button>
                    </div>
                ) : <p className="text-sm text-muted-foreground">{t('brand.none')}</p>}
            </section>

            <section aria-labelledby="brand-upload" className="flex flex-col gap-4 border border-border bg-surface p-4 sm:p-6">
                <h2 className="text-lg font-semibold" id="brand-upload">{t(state.has_logo ? 'brand.replace' : 'brand.upload')}</h2>
                <p className="text-sm text-muted-foreground">{t('brand.rules')}</p>
                {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                <FormField error={tooBig ? t('brand.tooBig') : action.fieldError('logo')} label={t('brand.file')}>
                    <Input accept="image/png,image/jpeg,image/webp,image/svg+xml,.svg" onChange={(e) => choose((e.target as HTMLInputElement).files?.[0] ?? null)} type="file" />
                </FormField>
                {preview !== null ? (
                    <div className="flex flex-col gap-2">
                        <p className="text-sm font-medium">{t('brand.preview')}</p>
                        <img alt="" className="max-h-20 max-w-[16rem] border border-border bg-white p-2 object-contain" src={preview} />
                    </div>
                ) : null}
                <div><Button disabled={file === null} loading={action.busy} onClick={() => void save()} type="button">{t('brand.save')}</Button></div>
            </section>

            <Alert title={t('brand.where.title')} tone="info">{t('brand.where.body')}</Alert>
        </PropertyShell>
    );
}
