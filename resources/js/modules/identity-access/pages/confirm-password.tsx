import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { AuthShell } from '@/modules/identity-access/components/auth-shell';

export default function ConfirmPasswordPage() {
    const form = useForm({ password: '' });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/confirm-password', { onFinish: () => form.reset() });
    }

    return (
        <>
            <Head title="Confirm password" />
            <AuthShell title="Confirm your password" description="This sensitive action requires recent password verification.">
                <form className="space-y-5" onSubmit={submit}>
                    <div>
                        <label className="text-sm font-medium" htmlFor="password">Password</label>
                        <Input autoComplete="current-password" autoFocus id="password" onChange={(event) => form.setData('password', event.target.value)} required type="password" value={form.data.password} />
                        {form.errors.password && <p className="mt-1 text-sm text-danger">{form.errors.password}</p>}
                    </div>
                    <Button className="w-full" disabled={form.processing} type="submit">Confirm</Button>
                </form>
            </AuthShell>
        </>
    );
}
