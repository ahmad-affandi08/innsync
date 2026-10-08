import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { FormField } from '@/components/ui/form-field';
import { PasswordInput } from '@/components/ui/password-input';
import { AuthShell } from '@/modules/identity-access/components/auth-shell';
import { useTranslation } from '@/shared/i18n/i18n';

export default function ConfirmPasswordPage() {
    const { t } = useTranslation();
    const form = useForm({ password: '' });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/confirm-password', { onFinish: () => form.reset() });
    }

    return (
        <>
            <Head title={t('identity.confirmPassword.heading')} />
            <AuthShell
                title={t('identity.confirmPassword.title')}
                description={t('identity.confirmPassword.description')}
            >
                <form className="space-y-5" onSubmit={submit}>
                    <FormField field="password" error={form.errors.password} label={t('common.field.password')}>
                        <PasswordInput
                            autoComplete="current-password"
                            autoFocus
                            onChange={(event) => form.setData('password', event.target.value)}
                            required
                            value={form.data.password}
                        />
                    </FormField>
                    <Button className="w-full" loading={form.processing} type="submit">
                        {t('common.action.confirm')}
                    </Button>
                </form>
            </AuthShell>
        </>
    );
}
