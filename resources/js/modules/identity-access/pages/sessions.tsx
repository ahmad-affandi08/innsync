import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Session = {
    id: string;
    device: string;
    ipAddress: string | null;
    lastActivity: number;
    current: boolean;
};

export default function SessionsPage({ sessions }: { sessions: Session[] }) {
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
            <Head title="Active sessions" />
            <main className="min-h-screen bg-surface-muted px-4 py-10">
                <section className="mx-auto max-w-3xl border border-border bg-surface p-6 shadow-panel sm:p-8">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h1 className="text-2xl font-semibold">Active sessions</h1>
                            <p className="mt-1 text-sm text-muted-foreground">Review and revoke devices that should no longer have access.</p>
                        </div>
                        <Button asChild variant="outline"><Link href="/">Back</Link></Button>
                    </div>
                    <ul className="mt-6 divide-y divide-border border-y border-border">
                        {sessions.map((session) => (
                            <li className="flex flex-wrap items-center justify-between gap-4 py-4" key={session.id}>
                                <div>
                                    <p className="text-sm font-medium">{session.device}{session.current ? ' — Current session' : ''}</p>
                                    <p className="mt-1 text-xs text-muted-foreground">IP {session.ipAddress ?? 'Unavailable'} · Last active {new Date(session.lastActivity * 1000).toLocaleString()}</p>
                                </div>
                                {!session.current && <Button onClick={() => router.delete(`/account/sessions/${session.id}`)} type="button" variant="destructive">Revoke</Button>}
                            </li>
                        ))}
                    </ul>
                    <Button className="mt-6" onClick={() => router.delete('/account/sessions/others')} type="button" variant="outline">Revoke all other sessions</Button>

                    <form className="mt-10 max-w-md space-y-4 border-t border-border pt-6" onSubmit={updatePassword}>
                        <div>
                            <h2 className="text-lg font-semibold">Change password</h2>
                            <p className="mt-1 text-sm text-muted-foreground">Changing your password revokes every other active session.</p>
                        </div>
                        <div>
                            <label className="text-sm font-medium" htmlFor="current_password">Current password</label>
                            <Input id="current_password" onChange={(event) => passwordForm.setData('current_password', event.target.value)} required type="password" value={passwordForm.data.current_password} />
                            {passwordForm.errors.current_password && <p className="mt-1 text-sm text-danger">{passwordForm.errors.current_password}</p>}
                        </div>
                        <div>
                            <label className="text-sm font-medium" htmlFor="new_password">New password</label>
                            <Input id="new_password" onChange={(event) => passwordForm.setData('password', event.target.value)} required type="password" value={passwordForm.data.password} />
                            {passwordForm.errors.password && <p className="mt-1 text-sm text-danger">{passwordForm.errors.password}</p>}
                        </div>
                        <div>
                            <label className="text-sm font-medium" htmlFor="password_confirmation">Confirm new password</label>
                            <Input id="password_confirmation" onChange={(event) => passwordForm.setData('password_confirmation', event.target.value)} required type="password" value={passwordForm.data.password_confirmation} />
                        </div>
                        <Button disabled={passwordForm.processing} type="submit">Update password</Button>
                    </form>
                </section>
            </main>
        </>
    );
}
