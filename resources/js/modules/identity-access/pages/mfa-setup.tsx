import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { AuthShell } from '@/modules/identity-access/components/auth-shell';
import { useTranslation } from '@/shared/i18n/i18n';

type MfaSetupProps = {
    required: boolean;
    secret: string | null;
    provisioningUri: string | null;
    recoveryCodes: string[] | null;
};

export default function MfaSetupPage({
    provisioningUri,
    recoveryCodes,
    required,
    secret,
}: MfaSetupProps) {
    const { t } = useTranslation();
    const form = useForm({ code: '' });

    function confirm(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/mfa/confirm');
    }

    return (
        <>
            <Head title={t('identity.mfa.setup.title')} />
            <AuthShell
                title={t('identity.mfa.setup.title')}
                description={required ? t('identity.mfa.setup.descriptionRequired') : t('identity.mfa.setup.descriptionOptional')}
            >
                {recoveryCodes ? (
                    <div className="space-y-5">
                        <div className="border border-warning bg-surface-muted p-4 text-sm" role="status">
                            {t('identity.mfa.setup.saveCodes')}
                        </div>
                        <ul className="grid grid-cols-2 gap-2 font-mono text-sm" aria-label={t('identity.mfa.setup.recoveryCodes')}>
                            {recoveryCodes.map((code) => <li key={code}>{code}</li>)}
                        </ul>
                        <Button asChild className="w-full"><Link href="/properties/select">{t('common.action.continue')}</Link></Button>
                    </div>
                ) : secret && provisioningUri ? (
                    <form className="space-y-5" onSubmit={confirm}>
                        <div>
                            <p className="text-sm font-medium">{t('identity.mfa.setup.manualKey')}</p>
                            <code className="mt-1 block break-all bg-surface-muted p-3 text-sm">{secret}</code>
                            <p className="mt-2 break-all text-xs text-muted-foreground">{provisioningUri}</p>
                        </div>
                        <FormField error={form.errors.code} label={t('identity.mfa.setup.sixDigitCode')}>
                            <Input inputMode="numeric" onChange={(event) => form.setData('code', event.target.value)} required value={form.data.code} />
                        </FormField>
                        <Button className="w-full" loading={form.processing} type="submit">{t('identity.mfa.setup.confirm')}</Button>
                    </form>
                ) : (
                    <Button className="w-full" onClick={() => router.post('/mfa/setup')} type="button">
                        {t('identity.mfa.setup.generate')}
                    </Button>
                )}
            </AuthShell>
        </>
    );
}
