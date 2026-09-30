import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { AuthShell } from '@/modules/identity-access/components/auth-shell';

type MfaSetupProps = {
    required: boolean;
    secret: string | null;
    provisioningUri: string | null;
    recoveryCodes: string[] | null;
};

export default function MfaSetupPage({
    provisioningUri,
    recoveryCodes,
    required,
    secret,
}: MfaSetupProps) {
    const form = useForm({ code: '' });

    function confirm(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/mfa/confirm');
    }

    return (
        <>
            <Head title="Set up two-factor authentication" />
            <AuthShell
                title="Set up two-factor authentication"
                description={required ? 'Your assigned role requires a second verification step.' : 'Protect your account with an authenticator app.'}
            >
                {recoveryCodes ? (
                    <div className="space-y-5">
                        <div className="border border-warning bg-surface-muted p-4 text-sm">
                            Save these recovery codes now. Each code can only be used once.
                        </div>
                        <ul className="grid grid-cols-2 gap-2 font-mono text-sm" aria-label="Recovery codes">
                            {recoveryCodes.map((code) => <li key={code}>{code}</li>)}
                        </ul>
                        <Button asChild className="w-full"><Link href="/properties/select">Continue</Link></Button>
                    </div>
                ) : secret && provisioningUri ? (
                    <form className="space-y-5" onSubmit={confirm}>
                        <div>
                            <p className="text-sm font-medium">Manual setup key</p>
                            <code className="mt-1 block break-all bg-surface-muted p-3 text-sm">{secret}</code>
                            <p className="mt-2 break-all text-xs text-muted-foreground">{provisioningUri}</p>
                        </div>
                        <div>
                            <label className="text-sm font-medium" htmlFor="code">Six-digit code</label>
                            <Input id="code" inputMode="numeric" onChange={(event) => form.setData('code', event.target.value)} required value={form.data.code} />
                            {form.errors.code && <p className="mt-1 text-sm text-danger">{form.errors.code}</p>}
                        </div>
                        <Button className="w-full" disabled={form.processing} type="submit">Confirm setup</Button>
                    </form>
                ) : (
                    <Button className="w-full" onClick={() => router.post('/mfa/setup')} type="button">
                        Generate setup key
                    </Button>
                )}
            </AuthShell>
        </>
    );
}
