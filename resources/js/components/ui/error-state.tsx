import type { ReactNode } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Illustration, type IllustrationName } from '@/components/ui/illustration';
import { toFailure, type Failure, type FailureKind } from '@/shared/lib/api-error';

/** Copy for one failure kind; supplied by the screen until the i18n framework (TASK-FND-013) exists. */
export type FailureCopy = {
    title: string;
    description?: string;
};

export type ErrorStateProps = {
    error: unknown;
    /** Copy per failure kind the screen can meet; every other kind uses `fallback`. */
    copy: Partial<Record<FailureKind, FailureCopy>>;
    fallback: FailureCopy;
    retryLabel: string;
    onRetry?: () => void;
    /** Conflicts offer refresh/review rather than a blind retry. */
    refreshLabel?: string;
    onRefresh?: () => void;
    signInLabel?: string;
    signInHref?: string;
    /** Prefix shown before the correlation id for support (e.g. "Reference"). */
    referenceLabel: string;
    children?: ReactNode;
};

function toneFor(kind: FailureKind): 'danger' | 'warning' | 'info' {
    if (kind === 'conflict' || kind === 'offline' || kind === 'rate-limited') {
        return 'warning'
    }

    if (kind === 'session-expired' || kind === 'unauthenticated') {
        return 'info'
    }

    return 'danger'
}

function pictureFor(kind: FailureKind): IllustrationName {
    if (kind === 'forbidden') {
        return 'forbidden'
    }

    if (kind === 'offline') {
        return 'offline'
    }

    if (kind === 'session-expired' || kind === 'unauthenticated' || kind === 'rate-limited') {
        return 'session-expired'
    }

    return kind === 'conflict' ? 'warning' : 'error'
}

/**
 * Maps a failed request to its dedicated state. Conflict, forbidden,
 * session-expired and offline are never rendered as a generic error
 * (docs/DESIGN/05-FORMS-VALIDATION.md, 07-STATES-FEEDBACK.md). Only the
 * correlation id is shown for support; server messages and exception text are
 * never rendered.
 */
function ErrorState(props: ErrorStateProps) {
    const failure: Failure = toFailure(props.error);
    const copy = props.copy[failure.kind] ?? props.fallback;
    const actions: ReactNode[] = [];

    if (
        (failure.kind === 'session-expired' || failure.kind === 'unauthenticated') &&
        props.signInHref &&
        props.signInLabel
    ) {
        actions.push(
            <Button asChild key="sign-in" size="sm">
                <a href={props.signInHref}>{props.signInLabel}</a>
            </Button>,
        );
    } else if (failure.kind === 'conflict' && props.onRefresh && props.refreshLabel) {
        actions.push(
            <Button key="refresh" onClick={props.onRefresh} size="sm" variant="outline">
                {props.refreshLabel}
            </Button>,
        );
    } else if (failure.retryable && props.onRetry) {
        actions.push(
            <Button key="retry" onClick={props.onRetry} size="sm" variant="outline">
                {props.retryLabel}
            </Button>,
        );
    }

    return (
        <div className="flex items-start gap-3">
            <Illustration className="hidden w-14 shrink-0 sm:block" name={pictureFor(failure.kind)} />
            <div className="min-w-0 flex-1">
                <Alert actions={actions.length > 0 ? actions : undefined} title={copy.title} tone={toneFor(failure.kind)}>
                    {copy.description ? <p>{copy.description}</p> : null}
                    {props.children}
                    {failure.correlationId ? (
                        <p className="mt-1 text-xs">
                            {props.referenceLabel}:{' '}
                            <code className="font-mono">{failure.correlationId}</code>
                        </p>
                    ) : null}
                </Alert>
            </div>
        </div>
    );
}

export { ErrorState };
