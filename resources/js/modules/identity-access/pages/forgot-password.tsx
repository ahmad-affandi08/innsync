import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { AuthShell } from '@/modules/identity-access/components/auth-shell';
import { useTranslation } from '@/shared/i18n/i18n';

export default function ForgotPasswordPage() {
    const { t } = useTranslation();
    const status = usePage<{ status?: string | null }>().props.status;
    const form = useForm({ email: '' });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/forgot-password');
    }

    return (
        <>
            <Head title={t('identity.forgot.title')} />
            <AuthShell title={t('identity.forgot.title')} description={t('identity.forgot.description')}>
                <form className="space-y-5" onSubmit={submit}>
                    {status ? <p className="border border-border bg-surface-muted p-3 text-sm" role="status">{status}</p> : null}
                    <FormField field="email" error={form.errors.email} label={t('common.field.email')}>
                        <Input autoComplete="username" autoFocus name="email" onChange={(e) => form.setData('email', e.target.value)} required type="email" value={form.data.email} />
                    </FormField>
                    <Button className="w-full" loading={form.processing} type="submit">{t('identity.forgot.send')}</Button>
                    <p className="text-center text-sm"><Link className="underline underline-offset-2" href="/login">{t('identity.forgot.back')}</Link></p>
                </form>
            </AuthShell>
        </>
    );
}
