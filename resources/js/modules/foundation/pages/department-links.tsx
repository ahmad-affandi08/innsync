import { useState } from 'react';

import { Button } from '@/components/ui/button';
import type { MessageKey } from '@/locales/en/index';
import { PropertyShell } from '@/modules/property/components/property-shell';
import { useTranslation } from '@/shared/i18n/i18n';

type Department = { key: string; label: string; about: string; paths: { path: string; label: string }[] };
type Props = { base: string; departments: Department[]; guest: { rooms: string; booking: string } };

/** The address to give each department's staff, and the guest-facing addresses, in one place to copy from. */
export default function DepartmentLinksPage({ base, departments, guest }: Props) {
    const { t } = useTranslation();
    const [copied, setCopied] = useState<string | null>(null);

    async function copy(text: string) {
        try {
            await navigator.clipboard.writeText(text);
            setCopied(text);
            window.setTimeout(() => setCopied((c) => (c === text ? null : c)), 2000);
        } catch {
            setCopied(null);
        }
    }

    const row = (label: string, path: string) => {
        const url = `${base}${path}`;

        return (
            <li className="flex flex-wrap items-center gap-2 py-2" key={path}>
                <div className="min-w-0 flex-1 basis-56">
                    <p className="text-sm font-medium">{label}</p>
                    <code className="break-all text-xs text-muted-foreground" data-testid="dept-url">{url}</code>
                </div>
                <Button onClick={() => void copy(url)} size="sm" type="button" variant="outline">{t(copied === url ? 'dl.copied' : 'dl.copy')}</Button>
                <Button asChild size="sm" variant="outline"><a href={url} rel="noreferrer" target="_blank">{t('dl.open')}</a></Button>
            </li>
        );
    };

    return (
        <PropertyShell description={t('dl.description')} title={t('dl.title')}>
            <p className="max-w-3xl text-sm text-muted-foreground">{t('dl.how')}</p>

            <div className="grid gap-4 lg:grid-cols-2">
                {departments.map((d) => (
                    <section aria-labelledby={`dl-${d.key}`} className="flex flex-col gap-1 border border-border bg-surface p-4" key={d.key}>
                        <h2 className="text-base font-semibold" id={`dl-${d.key}`}>{t(d.label as MessageKey)}</h2>
                        <p className="text-sm text-muted-foreground">{t(d.about as MessageKey)}</p>
                        <ul className="divide-y divide-border">{d.paths.map((p) => row(t(p.label as MessageKey), p.path))}</ul>
                    </section>
                ))}

                <section aria-labelledby="dl-guest" className="flex flex-col gap-1 border border-border bg-surface p-4 lg:col-span-2">
                    <h2 className="text-base font-semibold" id="dl-guest">{t('dl.guest')}</h2>
                    <p className="text-sm text-muted-foreground">{t('dl.guestAbout')}</p>
                    <ul className="divide-y divide-border">
                        {row(t('dl.guest.rooms'), guest.rooms)}
                        {row(t('dl.guest.booking'), guest.booking)}
                    </ul>
                </section>
            </div>
        </PropertyShell>
    );
}
