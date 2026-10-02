import { router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { AppFrame } from '@/components/layout/app-frame';
import { Button } from '@/components/ui/button';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Session = {
    id: string;
    device: string;
    ipAddress: string | null;
    lastActivity: number;
    current: boolean;
};

export default function SessionsPage({ sessions }: { sessions: Session[] }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const passwordForm = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    function updatePassword(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        passwordForm.put('/account/password', {
            onSuccess: () => passwordForm.reset(),
        });
    }

    return (
        <>
            <AppFrame description={t('identity.sessions.description')} title={t('identity.sessions.title')} wide={false}>
                    <ul className="mt-6 divide-y divide-border border-y border-border">
                        {sessions.map((session) => (
                            <li className="flex flex-wrap items-center justify-between gap-4 py-4" key={session.id}>
                                <div>
                                    <p className="text-sm font-medium">{session.device}{session.current ? ` — ${t('identity.sessions.current')}` : ''}</p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {t('identity.sessions.detail', {
                                            ip: session.ipAddress ?? t('common.unavailable'),
                                            lastActive: format.epochSeconds(session.lastActivity),
                                        })}
                                    </p>
                                </div>
                                {!session.current && <Button onClick={() => router.delete(`/account/sessions/${session.id}`)} type="button" variant="destructive">{t('identity.sessions.revoke')}</Button>}
                            </li>
                        ))}
                    </ul>
                    <Button className="mt-6" onClick={() => router.delete('/account/sessions/others')} type="button" variant="outline">{t('identity.sessions.revokeOthers')}</Button>

                    <form className="mt-10 max-w-md space-y-4 border-t border-border pt-6" onSubmit={updatePassword}>
                        <div>
                            <h2 className="text-lg font-semibold">{t('identity.password.heading')}</h2>
                            <p className="mt-1 text-sm text-muted-foreground">{t('identity.password.description')}</p>
                        </div>
                        <FormField field="current_password" error={passwordForm.errors.current_password} label={t('identity.password.current')}>
                            <Input autoComplete="current-password" onChange={(event) => passwordForm.setData('current_password', event.target.value)} required type="password" value={passwordForm.data.current_password} />
                        </FormField>
                        <FormField field="password" error={passwordForm.errors.password} label={t('identity.password.new')}>
                            <Input autoComplete="new-password" onChange={(event) => passwordForm.setData('password', event.target.value)} required type="password" value={passwordForm.data.password} />
                        </FormField>
                        <FormField field="password_confirmation" label={t('identity.password.confirmNew')}>
                            <Input autoComplete="new-password" onChange={(event) => passwordForm.setData('password_confirmation', event.target.value)} required type="password" value={passwordForm.data.password_confirmation} />
                        </FormField>
                        <Button loading={passwordForm.processing} type="submit">{t('identity.password.update')}</Button>
                    </form>
            </AppFrame>
        </>
    );
}
