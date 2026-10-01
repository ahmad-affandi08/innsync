import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { AuthShell } from '@/modules/identity-access/components/auth-shell';
import { useTranslation } from '@/shared/i18n/i18n';

export default function MfaChallengePage() {
    const { t } = useTranslation();
    const form = useForm({ code: '' });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/mfa/challenge', { onError: () => form.reset('code') });
    }

    return (
        <>
            <Head title={t('identity.mfa.challenge.heading')} />
            <AuthShell
                title={t('identity.mfa.challenge.title')}
                description={t('identity.mfa.challenge.description')}
            >
                <form className="space-y-5" onSubmit={submit}>
                    <FormField error={form.errors.code} label={t('identity.mfa.challenge.code')}>
                        <Input
                            autoComplete="one-time-code"
                            autoFocus
                            inputMode="numeric"
                            onChange={(event) => form.setData('code', event.target.value)}
                            required
                            value={form.data.code}
                        />
                    </FormField>
                    <Button className="w-full" loading={form.processing} type="submit">
                        {t('identity.mfa.challenge.submit')}
                    </Button>
                </form>
            </AuthShell>
        </>
    );
}
