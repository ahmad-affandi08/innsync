import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { AuthShell } from '@/modules/identity-access/components/auth-shell';

export default function MfaChallengePage() {
    const form = useForm({ code: '' });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/mfa/challenge', { onError: () => form.reset('code') });
    }

    return (
        <>
            <Head title="Two-factor verification" />
            <AuthShell
                title="Verify your sign-in"
                description="Enter the six-digit authenticator code or one unused recovery code."
            >
                <form className="space-y-5" onSubmit={submit}>
                    <div>
                        <label className="text-sm font-medium" htmlFor="code">Verification code</label>
                        <Input
                            autoComplete="one-time-code"
                            autoFocus
                            id="code"
                            inputMode="numeric"
                            onChange={(event) => form.setData('code', event.target.value)}
                            required
                            value={form.data.code}
                        />
                        {form.errors.code && <p className="mt-1 text-sm text-danger">{form.errors.code}</p>}
                    </div>
                    <Button className="w-full" disabled={form.processing} type="submit">
                        Verify
                    </Button>
                </form>
            </AuthShell>
        </>
    );
}
