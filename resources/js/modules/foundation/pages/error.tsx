import { Head, Link, usePage } from '@inertiajs/react';

import { AppFrame } from '@/components/layout/app-frame';
import { Button } from '@/components/ui/button';
import { Illustration, type IllustrationName } from '@/components/ui/illustration';
import type { MessageKey } from '@/locales/en/index';
import { AuthShell } from '@/modules/identity-access/components/auth-shell';
import { useTranslation } from '@/shared/i18n/i18n';

type ErrorPageProps = { status: number; message: string };

const PICTURE: Record<number, IllustrationName> = { 403: 'forbidden', 404: 'empty', 409: 'warning', 422: 'warning' };

/** What a person sees when a screen they opened is not theirs, is gone, or cannot be shown: a plain page with the way out, in the frame they were in. */
export default function ErrorPage({ message, status }: ErrorPageProps) {
    const { t } = useTranslation();
    const signedIn = (usePage().props as { auth?: unknown }).auth != null;
    const known = [403, 404, 409, 422].includes(status);
    const title = t((known ? `err.page.${status}.title` : 'err.page.other.title') as MessageKey);
    const body = (
        <div className="flex flex-col items-center gap-4 py-8 text-center">
            <Illustration className="w-48" name={PICTURE[status] ?? 'error'} />
            <p className="max-w-prose text-sm text-muted-foreground">{message}</p>
            {status === 403 ? <p className="max-w-prose text-sm text-muted-foreground">{t('err.page.hint')}</p> : null}
            <div className="flex flex-wrap justify-center gap-2">
                <Button onClick={() => window.history.back()} type="button" variant="outline">{t('err.page.back')}</Button>
                <Button asChild><Link href="/">{t('err.page.home')}</Link></Button>
            </div>
        </div>
    );

    return (
        <>
            <Head title={title} />
            {signedIn ? <AppFrame title={title}>{body}</AppFrame> : <AuthShell description={message} title={title}><div className="flex justify-center"><Illustration className="w-40" name={PICTURE[status] ?? 'error'} /></div></AuthShell>}
        </>
    );
}
