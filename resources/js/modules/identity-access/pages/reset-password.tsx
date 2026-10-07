import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { AuthShell } from '@/modules/identity-access/components/auth-shell';
import { useTranslation } from '@/shared/i18n/i18n';

export default function ResetPasswordPage({ token, email }: { token: string; email: string }) {
    const { t } = useTranslation();
    const form = useForm({ token, email, password: '', password_confirmation: '' });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
    }

    return (
        <>
            <Head title={t('identity.reset.title')} />
            <AuthShell title={t('identity.reset.title')} description={t('identity.reset.description')}>
                <form className="space-y-5" onSubmit={submit}>
                    <FormField field="email" error={form.errors.email} label={t('common.field.email')}>
                        <Input autoComplete="username" name="email" onChange={(e) => form.setData('email', e.target.value)} required type="email" value={form.data.email} />
                    </FormField>
                    <FormField field="password" error={form.errors.password} label={t('identity.reset.password')}>
                        <Input autoComplete="new-password" autoFocus name="password" onChange={(e) => form.setData('password', e.target.value)} required type="password" value={form.data.password} />
                    </FormField>
                    <FormField field="password_confirmation" error={form.errors.password_confirmation} label={t('identity.reset.confirm')}>
                        <Input autoComplete="new-password" name="password_confirmation" onChange={(e) => form.setData('password_confirmation', e.target.value)} required type="password" value={form.data.password_confirmation} />
                    </FormField>
                    <Button className="w-full" loading={form.processing} type="submit">{t('identity.reset.save')}</Button>
                    <p className="text-center text-sm"><Link className="underline underline-offset-2" href="/forgot-password">{t('identity.reset.again')}</Link></p>
                </form>
            </AuthShell>
        </>
    );
}
