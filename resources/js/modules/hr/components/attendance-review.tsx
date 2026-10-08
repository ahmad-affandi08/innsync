import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import type { MessageKey } from '@/locales/en/index';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

export type ReviewItem = {
    attendance_id: string;
    side: 'in' | 'out';
    employee_id: string;
    employee_name: string;
    employee_number: string;
    work_date: string;
    at: string;
    flags: string[];
    strong: boolean;
    has_photo: boolean;
};

/** Clock-ins that look unusual, for a supervisor to answer once: fine, or questioned with a note. No face is read; the selfie is a picture a person looks at. */
export function AttendanceReview({ items }: { items: ReviewItem[] }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [ask, setAsk] = useState<{ item: ReviewItem; note: string } | null>(null);

    async function answer(item: ReviewItem, decision: 'ok' | 'questioned', note: string | null) {
        const done = await action.run(`/hr/attendance/${item.attendance_id}/review`, { body: { side: item.side, decision, note }, reload: ['review'] });
        if (done !== null) setAsk(null);
    }

    return (
        <section aria-labelledby="att-review-h" className="flex flex-col gap-3 border border-border bg-surface p-4" data-testid="att-review">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-lg font-semibold" id="att-review-h">{t('hr.att.review.title')}</h2>
                {items.length > 0 ? <StatusBadge label={t('hr.att.review.count', { count: items.length })} tone="warning" /> : null}
            </div>
            <p className="text-sm text-muted-foreground">{t('hr.att.review.hint')}</p>
            {action.error !== null && ask === null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            {items.length === 0 ? <p className="text-sm">{t('hr.att.review.none')}</p> : (
                <ul className="divide-y divide-border border-y border-border">
                    {items.map((i) => (
                        <li className="flex flex-col gap-2 py-3" data-testid={`review-${i.attendance_id}-${i.side}`} key={`${i.attendance_id}-${i.side}`}>
                            <p className="text-sm font-semibold">{i.employee_name} <span className="font-normal text-muted-foreground">· {i.employee_number}</span></p>
                            <p className="text-sm text-muted-foreground">{t(i.side === 'in' ? 'hr.att.review.in' : 'hr.att.review.out', { when: format.instant(i.at) })}</p>
                            <ul className="flex flex-col gap-1">
                                {i.flags.map((f) => (
                                    <li className="flex flex-wrap items-baseline gap-2 text-sm" key={f}>
                                        <StatusBadge label={t(`hr.att.flag.${f}` as MessageKey)} tone={f === 'shared_device' || f === 'reused_photo' || f === 'face_mismatch' ? 'danger' : 'warning'} />
                                        <span className="text-muted-foreground">{t(`hr.att.flag.${f}.hint` as MessageKey)}</span>
                                    </li>
                                ))}
                            </ul>
                            <div className="flex flex-wrap gap-2">
                                {i.has_photo ? <Button asChild size="sm" variant="outline"><a href={`/hr/attendance/${i.attendance_id}/photo/${i.side}`} rel="noopener" target="_blank">{t('hr.att.review.selfie')}</a></Button> : null}
                                <Button disabled={action.busy} onClick={() => void answer(i, 'ok', null)} size="sm" type="button" variant="outline">{t('hr.att.review.fine')}</Button>
                                <Button disabled={action.busy} onClick={() => { action.clear(); setAsk({ item: i, note: '' }); }} size="sm" type="button">{t('hr.att.review.question')}</Button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setAsk(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => ask !== null && void answer(ask.item, 'questioned', ask.note)} type="button">{t('hr.att.review.questionSubmit')}</Button>
                </>}
                onClose={() => setAsk(null)}
                open={ask !== null}
                title={ask === null ? '' : t('hr.att.review.questionTitle', { name: ask.item.employee_name })}
            >
                {ask !== null && (
                    <div className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">{t('hr.att.review.questionHint')}</p>
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <FormField error={action.fieldError('note')} field="note" label={t('hr.att.review.note')}>
                            <Input maxLength={300} onChange={(e) => setAsk({ ...ask, note: e.target.value })} value={ask.note} />
                        </FormField>
                    </div>
                )}
            </Dialog>
        </section>
    );
}
