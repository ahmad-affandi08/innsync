import { router } from '@inertiajs/react';
import { useState } from 'react';

import { AppFrame } from '@/components/layout/app-frame';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { apiRequest } from '@/shared/api/http';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { toFailure, type Failure } from '@/shared/lib/api-error';
import type { MessageKey } from '@/locales/en/index';

type Approval = {
    id: string;
    subject_type: string;
    subject_ref: string;
    reason: string;
    status: 'pending' | 'approved' | 'rejected' | 'cancelled';
    current_step: number;
    steps: { permission: string; approvals_required: number }[];
    amount_minor: number | null;
    currency: string | null;
    created_at: string;
};

type Action = { kind: 'approve' | 'reject' | 'cancel'; approval: Approval };

const MAX_REASON_LENGTH = 500;

const statusTone: Record<Approval['status'], StatusTone> = {
    pending: 'pending',
    approved: 'success',
    rejected: 'danger',
    cancelled: 'neutral',
};

function errorKey(failure: Failure): MessageKey {
    if (failure.kind === 'forbidden') return 'identity.approvals.error.forbidden';
    if (failure.kind === 'conflict') return 'identity.approvals.error.conflict';
    if (failure.kind === 'offline') return 'identity.approvals.error.offline';

    return 'identity.approvals.error.generic';
}

export default function ApprovalsPage({ pending, mine }: { pending: Approval[]; mine: Approval[] }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const [action, setAction] = useState<Action | null>(null);
    const [reason, setReason] = useState('');
    const [reasonMissing, setReasonMissing] = useState(false);
    const [busy, setBusy] = useState(false);
    const [failure, setFailure] = useState<Failure | null>(null);

    function close() {
        setAction(null);
        setReason('');
        setReasonMissing(false);
    }

    async function submit() {
        if (action === null) {
            return;
        }

        const trimmed = reason.trim();

        if (action.kind === 'reject' && trimmed === '') {
            setReasonMissing(true);

            return;
        }

        setBusy(true);
        setFailure(null);

        try {
            await apiRequest(`/approvals/${action.approval.id}/${action.kind}`, {
                method: 'POST',
                body: action.kind === 'reject' ? { reason: trimmed } : undefined,
            });
            close();
            router.reload({ only: ['pending', 'mine'] });
        } catch (error) {
            const failed = toFailure(error);

            // 423: the confirmation window lapsed. Confirming the password returns here.
            if (failed.status === 423) {
                window.location.assign('/approvals/confirm');

                return;
            }

            close();
            setFailure(failed);

            if (failed.kind === 'conflict') {
                router.reload({ only: ['pending', 'mine'] });
            }
        } finally {
            setBusy(false);
        }
    }

    function row(approval: Approval, actions: Action['kind'][]) {
        const step = approval.steps[approval.current_step];

        return (
            <li className="flex flex-col gap-3 py-4" key={approval.id}>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-sm font-medium">
                        {approval.subject_type} · {approval.subject_ref}
                    </p>
                    <StatusBadge label={t(`identity.approvals.status.${approval.status}`)} tone={statusTone[approval.status]} />
                </div>
                <dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-[max-content_1fr]">
                    <dt className="text-muted-foreground">{t('identity.approvals.reason')}</dt>
                    <dd className="break-words">{approval.reason}</dd>
                    {approval.amount_minor !== null && approval.currency !== null && (
                        <>
                            <dt className="text-muted-foreground">{t('identity.approvals.amount')}</dt>
                            <dd>{format.money(approval.amount_minor, approval.currency)}</dd>
                        </>
                    )}
                    <dt className="text-muted-foreground">{t('identity.approvals.requestedAt')}</dt>
                    <dd>{format.instant(approval.created_at)}</dd>
                </dl>
                {approval.status === 'pending' && step !== undefined && (
                    <p className="text-xs text-muted-foreground">
                        {t('identity.approvals.step', {
                            current: approval.current_step + 1,
                            total: approval.steps.length,
                            approvals: step.approvals_required,
                            permission: step.permission,
                        })}
                    </p>
                )}
                {approval.status === 'pending' && actions.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        {actions.includes('approve') && (
                            <Button onClick={() => setAction({ kind: 'approve', approval })} size="sm" type="button">
                                {t('identity.approvals.approve')}
                            </Button>
                        )}
                        {actions.includes('reject') && (
                            <Button onClick={() => setAction({ kind: 'reject', approval })} size="sm" type="button" variant="outline">
                                {t('identity.approvals.reject')}
                            </Button>
                        )}
                        {actions.includes('cancel') && (
                            <Button onClick={() => setAction({ kind: 'cancel', approval })} size="sm" type="button" variant="outline">
                                {t('identity.approvals.cancel')}
                            </Button>
                        )}
                    </div>
                )}
            </li>
        );
    }

    return (
        <>
            <AppFrame actions={<Button asChild variant="outline"><a href="/approvals/policies">{t('identity.approvalPolicies.title')}</a></Button>} description={t('identity.approvals.description')} title={t('identity.approvals.title')} wide={false}>
                    {failure !== null && (
                        <Alert
                            actions={
                                failure.kind === 'conflict' ? (
                                    <Button onClick={() => router.reload({ only: ['pending', 'mine'] })} size="sm" type="button" variant="outline">
                                        {t('identity.approvals.refresh')}
                                    </Button>
                                ) : undefined
                            }
                            title={t(errorKey(failure))}
                            tone="danger"
                        >
                            {failure.correlationId !== null && <span className="text-xs text-muted-foreground">{failure.correlationId}</span>}
                        </Alert>
                    )}

                    <div>
                        <h2 className="text-lg font-semibold">{t('identity.approvals.pending.heading')}</h2>
                        {pending.length === 0 ? (
                            <EmptyState title={t('identity.approvals.pending.empty')} />
                        ) : (
                            <ul className="mt-2 divide-y divide-border border-y border-border">{pending.map((a) => row(a, ['approve', 'reject']))}</ul>
                        )}
                    </div>

                    <div>
                        <h2 className="text-lg font-semibold">{t('identity.approvals.mine.heading')}</h2>
                        {mine.length === 0 ? (
                            <EmptyState title={t('identity.approvals.mine.empty')} />
                        ) : (
                            <ul className="mt-2 divide-y divide-border border-y border-border">{mine.map((a) => row(a, ['cancel']))}</ul>
                        )}
                    </div>
            </AppFrame>

            <ConfirmDialog
                cancelLabel={t('identity.approvals.back')}
                confirmLabel={t(`identity.approvals.${action?.kind ?? 'approve'}`)}
                consequence={t(`identity.approvals.${action?.kind ?? 'approve'}.consequence`)}
                destructive={action?.kind !== 'approve'}
                onCancel={close}
                onConfirm={() => void submit()}
                open={action !== null}
                pending={busy}
                title={t(`identity.approvals.${action?.kind ?? 'approve'}.title`)}
            >
                {action?.kind === 'reject' && (
                    <FormField field="reason" error={reasonMissing ? t('identity.approvals.reject.reasonRequired') : undefined} label={t('identity.approvals.reject.reason')}>
                        <Textarea maxLength={MAX_REASON_LENGTH} onChange={(event) => setReason(event.target.value)} required value={reason} />
                    </FormField>
                )}
            </ConfirmDialog>
        </>
    );
}
