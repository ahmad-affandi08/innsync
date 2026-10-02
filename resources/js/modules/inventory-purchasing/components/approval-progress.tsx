import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import type { ApprovalSummary } from '@/modules/inventory-purchasing/lib/purchasing';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import type { MessageKey } from '@/locales/en/index';

const TONE: Record<string, StatusTone> = { pending: 'pending', approved: 'success', rejected: 'danger', cancelled: 'neutral', expired: 'neutral' };

/** Where an approval stands: each step with the approvals it has out of those it needs, and every decision with its reason. */
export function ApprovalProgress({ approval }: { approval: ApprovalSummary }) {
    const { t } = useTranslation();
    const format = useFormatters();

    return (
        <div className="flex flex-col gap-3 text-sm" data-testid="approval-progress">
            <p className="flex flex-wrap items-center gap-2">
                <StatusBadge label={t(`inv.po.apr.status.${approval.status}` as MessageKey)} tone={TONE[approval.status] ?? 'neutral'} />
                {approval.status === 'approved' && !approval.consumed ? <span className="text-muted-foreground">{t('inv.po.apr.readyToRelease')}</span> : null}
                {approval.status === 'pending' ? <span className="text-muted-foreground">{t('inv.po.apr.waiting')}</span> : null}
            </p>
            <ol className="flex flex-col gap-1">
                {approval.steps.map((step, i) => {
                    const given = approval.decisions.filter((d) => d.step === i && d.decision === 'approve').length;

                    return (
                        <li className="flex flex-wrap items-center justify-between gap-2 border border-border bg-surface px-3 py-2" key={`${step.permission}-${i}`}>
                            <span>{t('inv.po.apr.step', { number: i + 1, total: approval.steps.length })} <span className="text-muted-foreground">· {step.permission}</span></span>
                            <span className="font-medium">{t('inv.po.apr.progress', { given, needed: step.approvals_required })}</span>
                        </li>
                    );
                })}
            </ol>
            {approval.decisions.length > 0 ? (
                <ul className="flex flex-col gap-1 text-muted-foreground">
                    {approval.decisions.map((d, i) => (
                        <li key={`${d.step}-${d.decided_at}-${i}`}>
                            {t(d.decision === 'approve' ? 'inv.po.apr.approvedAt' : 'inv.po.apr.rejectedAt', { when: format.instant(d.decided_at) })}{d.reason ? ` · ${d.reason}` : ''}
                        </li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
}
