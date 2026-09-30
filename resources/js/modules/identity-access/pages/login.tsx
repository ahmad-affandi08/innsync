import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { AuthShell } from '@/modules/identity-access/components/auth-shell';

export default function LoginPage() {
    const form = useForm({ email: '', password: '' });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    }

    return (
        <>
            <Head title="Sign in" />
            <AuthShell
                title="Sign in"
                description="Use your staff account. Repeated failed attempts temporarily lock the account."
            >
                <form className="space-y-5" onSubmit={submit}>
                    <div>
                        <label className="text-sm font-medium" htmlFor="email">Email</label>
                        <Input
                            autoComplete="username"
                            autoFocus
                            id="email"
                            name="email"
                            onChange={(event) => form.setData('email', event.target.value)}
                            required
                            type="email"
                            value={form.data.email}
                        />
                        {form.errors.email && <p className="mt-1 text-sm text-danger">{form.errors.email}</p>}
                    </div>
                    <div>
                        <label className="text-sm font-medium" htmlFor="password">Password</label>
                        <Input
                            autoComplete="current-password"
                            id="password"
                            name="password"
                            onChange={(event) => form.setData('password', event.target.value)}
                            required
                            type="password"
                            value={form.data.password}
                        />
                    </div>
                    <Button className="w-full" disabled={form.processing} type="submit">
                        {form.processing ? 'Signing in…' : 'Sign in'}
                    </Button>
                </form>
            </AuthShell>
        </>
    );
}
