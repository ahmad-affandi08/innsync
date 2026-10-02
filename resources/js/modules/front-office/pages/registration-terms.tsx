import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

/** The house terms printed on every registration card (FR-FO-017); each save is a new version. */
export default function RegistrationTermsPage({ catalogue }: { catalogue: { terms: { version: number; body: string } | null; may_edit: boolean } }) {
    const { t } = useTranslation();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [body, setBody] = useState(catalogue.terms?.body ?? '');
    const [reason, setReason] = useState('');

    async function save() {
        const done = await action.run('/front-office/registration-terms', { body: { body, reason: reason.trim() }, reload: ['catalogue'] });
        if (done !== null) setReason('');
    }

    return (
        <FrontOfficeShell description={t('fo.regterms.description')} title={t('fo.regterms.title')}>
            <div><Button asChild size="sm" variant="outline"><Link href="/front-office/stays">{t('fo.nav.stays')}</Link></Button></div>
            {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            <p className="text-sm" data-testid="terms-version">{catalogue.terms === null ? t('fo.regterms.none') : t('fo.regterms.version', { n: catalogue.terms.version })}</p>
            {catalogue.may_edit ? (
                <section className="flex max-w-2xl flex-col gap-3">
                    <FormField error={action.fieldError('body')} hint={t('fo.regterms.hint')} label={t('fo.regterms.body')}><Textarea maxLength={4000} onChange={(e) => setBody(e.target.value)} rows={12} value={body} /></FormField>
                    <FormField error={action.fieldError('reason')} label={t('fo.regterms.reason')}><Input maxLength={300} onChange={(e) => setReason(e.target.value)} value={reason} /></FormField>
                    <div><Button disabled={body.trim() === '' || reason.trim() === ''} loading={action.busy} onClick={() => void save()} type="button">{t('fo.regterms.save')}</Button></div>
                </section>
            ) : catalogue.terms !== null ? <p className="whitespace-pre-line text-sm">{catalogue.terms.body}</p> : null}
        </FrontOfficeShell>
    );
}
