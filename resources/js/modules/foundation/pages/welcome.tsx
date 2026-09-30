import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';

import { Button } from '@/components/ui/button';

type WelcomePageProps = {
    appVersion: string;
    userName: string;
    activePropertyId: string;
};

export default function WelcomePage({ activePropertyId, appVersion, userName }: WelcomePageProps) {
    return (
        <>
            <Head title="Engineering Foundation" />

            <main className="min-h-screen bg-surface-muted px-4 py-10 sm:px-6 lg:px-8">
                <section className="mx-auto max-w-4xl border border-border bg-surface shadow-sm">
                    <header className="border-b border-border px-6 py-5 sm:px-8">
                        <p className="text-sm font-semibold tracking-wide text-primary">
                            InnSYnc
                        </p>
                        <h1 className="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">
                            Welcome, {userName}
                        </h1>
                        <p className="mt-2 max-w-2xl text-sm leading-6 text-muted-foreground">
                            Your authenticated session and property scope are active.
                        </p>
                    </header>

                    <div className="grid gap-6 px-6 py-6 sm:grid-cols-[1fr_auto] sm:items-end sm:px-8">
                        <div>
                            <div className="flex items-center gap-2 text-sm font-medium text-success">
                                <CheckCircle2 aria-hidden="true" className="size-4" />
                                Bootstrap verification available
                            </div>
                            <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                                <div>
                                    <dt className="text-muted-foreground">Task</dt>
                                    <dd className="font-medium">TASK-FND-001</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">Version</dt>
                                    <dd className="font-medium">{appVersion}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">Property scope</dt>
                                    <dd className="font-mono text-xs font-medium">{activePropertyId}</dd>
                                </div>
                            </dl>
                        </div>

                        <div className="flex gap-2">
                            <Button asChild variant="outline"><Link href="/account/sessions">Sessions</Link></Button>
                            <Button onClick={() => router.post('/logout')} type="button" variant="outline">Sign out</Button>
                        </div>
                    </div>
                </section>
            </main>
        </>
    );
}
