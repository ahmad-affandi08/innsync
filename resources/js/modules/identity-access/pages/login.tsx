import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { AuthShell } from '@/modules/identity-access/components/auth-shell';
import { useTranslation } from '@/shared/i18n/i18n';

export default function LoginPage() {
    const { t } = useTranslation();
    const status = usePage<{ status?: string | null }>().props.status;
    const form = useForm({ email: '', password: '' });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    }

    return (
        <>
            <Head title={t('identity.login.title')} />
            <AuthShell
                title={t('identity.login.title')}
                description={t('identity.login.description')}
            >
                <form className="space-y-5" onSubmit={submit}>
                    {status ? <p className="border border-border bg-surface-muted p-3 text-sm" role="status">{status}</p> : null}
                    <FormField field="email" error={form.errors.email} label={t('common.field.email')}>
                        <Input
                            autoComplete="username"
                            autoFocus
                            name="email"
                            onChange={(event) => form.setData('email', event.target.value)}
                            required
                            type="email"
                            value={form.data.email}
                        />
                    </FormField>
                    <FormField field="password" error={form.errors.password} label={t('common.field.password')}>
                        <Input
                            autoComplete="current-password"
                            name="password"
                            onChange={(event) => form.setData('password', event.target.value)}
                            required
                            type="password"
                            value={form.data.password}
                        />
                    </FormField>
                    <Button className="w-full" loading={form.processing} type="submit">
                        {form.processing ? t('common.status.signingIn') : t('identity.login.title')}
                    </Button>
                    <p className="text-center text-sm"><Link className="underline underline-offset-2" href="/forgot-password">{t('identity.forgot.link')}</Link></p>
                </form>
            </AuthShell>
        </>
    );
}
