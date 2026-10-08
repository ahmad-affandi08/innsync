import { Link } from '@inertiajs/react';

import { Alert } from '@/components/ui/alert';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import type { MessageKey } from '@/locales/en/index';
import { PropertyShell } from '@/modules/property/components/property-shell';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Check = { name: string; status: 'ok' | 'degraded' | 'down'; summary: string };
type Run = { status: string; finished_at: string | null } | null;
type Props = {
    status: 'ok' | 'degraded' | 'down';
    checks: Check[];
    backup: { last: Run; verify: Run };
    environment: { version: string; mail_delivers: boolean; debug_off: boolean; production: boolean; properties: { held: number; limit: number } };
};

const TONE: Record<Check['status'], StatusTone> = { ok: 'success', degraded: 'warning', down: 'danger' };

/** Is the system healthy and being backed up, in words an owner can read. Read only: it shows what the monitor and the backup log already record. */
export default function SystemStatusPage({ status, checks, backup, environment }: Props) {
    const { t } = useTranslation();
    const format = useFormatters();
    const known = (key: string) => t(key as MessageKey) !== key;
    const run = (r: Run, none: MessageKey, ok: MessageKey, failed: MessageKey) => {
        if (r === null || r.finished_at === null) return <Alert title={t(none)} tone="warning" />;

        return r.status === 'succeeded'
            ? <Alert title={t(ok, { when: format.instant(r.finished_at) })} tone="success" />
            : <Alert title={t(failed, { when: format.instant(r.finished_at) })} tone="danger">{t('sys.failedHint')}</Alert>;
    };

    return (
        <PropertyShell description={t('sys.description')} title={t('sys.title')}>
            <Alert title={t(`sys.overall.${status}` as MessageKey)} tone={status === 'ok' ? 'success' : status === 'degraded' ? 'warning' : 'danger'} />

            <section aria-labelledby="sys-backup" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="sys-backup">{t('sys.backup.title')}</h2>
                <p className="text-sm text-muted-foreground">{t('sys.backup.hint')}</p>
                {run(backup.last, 'sys.backup.none', 'sys.backup.ok', 'sys.backup.failed')}
                {run(backup.verify, 'sys.verify.none', 'sys.verify.ok', 'sys.verify.failed')}
            </section>

            <section aria-labelledby="sys-checks" className="flex flex-col gap-2">
                <h2 className="text-lg font-semibold" id="sys-checks">{t('sys.checks.title')}</h2>
                <ul className="divide-y divide-border border-y border-border">
                    {checks.map((c) => (
                        <li className="flex flex-wrap items-start justify-between gap-3 py-3" data-testid={`check-${c.name}`} key={c.name}>
                            <div className="min-w-0">
                                <p className="text-sm font-semibold">{known(`sys.check.${c.name}`) ? t(`sys.check.${c.name}` as MessageKey) : c.name}</p>
                                <p className="text-sm text-muted-foreground">{c.summary}</p>
                            </div>
                            <StatusBadge label={t(`sys.state.${c.status}` as MessageKey)} tone={TONE[c.status]} />
                        </li>
                    ))}
                </ul>
            </section>

            <section aria-labelledby="sys-env" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="sys-env">{t('sys.env.title')}</h2>
                {!environment.mail_delivers ? <Alert title={t('sys.env.mailTitle')} tone="warning">{t('sys.env.mailHint')} <Link className="font-medium underline underline-offset-2" href="/property/messaging">{t('msg.nav')}</Link></Alert> : <Alert title={t('sys.env.mailOk')} tone="success" />}
                {environment.production && !environment.debug_off ? <Alert title={t('sys.env.debugTitle')} tone="danger">{t('sys.env.debugHint')}</Alert> : null}
                <p className="text-sm text-muted-foreground">{t('sys.env.version', { version: environment.version })}</p>
                <p className="text-sm text-muted-foreground" data-testid="license-properties">{environment.properties.limit === 0 ? t('sys.license.unlimited', { held: environment.properties.held }) : t('sys.license.limited', { held: environment.properties.held, limit: environment.properties.limit })}</p>
            </section>
        </PropertyShell>
    );
}
