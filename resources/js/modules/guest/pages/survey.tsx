import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { GuestShell } from '@/modules/guest/components/guest-shell';
import { StayProof } from '@/modules/guest/components/stay-proof';
import type { GuestSurvey } from '@/modules/guest/lib/guest';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import type { MessageKey } from '@/locales/en/index';

const FIELDS = ['overall', 'room_rating', 'service_rating', 'food_rating', 'value_rating'] as const;
type Ratings = Record<(typeof FIELDS)[number], number | null>;

/** A short survey near the day of departure: five ratings from 1 to 5 and a comment. */
export default function SurveyPage({ view }: { view: GuestSurvey }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const action = useServerAction();
    const [ratings, setRatings] = useState<Ratings>({ overall: null, room_rating: null, service_rating: null, food_rating: null, value_rating: null });
    const [comment, setComment] = useState('');

    async function send() {
        const done = await action.run('/g/survey', { body: { ...ratings, comment: comment.trim() === '' ? null : comment.trim() } });
        if (done !== null) router.reload({ only: ['view'] });
    }

    return (
        <>
            <Head title={t('guest.survey.title')} />
            <GuestShell hotel={view.hotel} subtitle={view.label} title={t('guest.survey.title')}>
                {!view.verified ? (
                    <>
                        <Alert title={t('guest.help.needsProof')} tone="info" />
                        <StayProof locked={view.locked} onDone={() => router.reload({ only: ['view'] })} />
                    </>
                ) : view.answered ? <Alert title={t('guest.survey.thanks')} tone="success" /> : !view.open ? (
                    <Alert title={view.departure === null ? t('guest.survey.closed') : t('guest.survey.notYet', { date: format.date(view.departure) })} tone="info" />
                ) : (
                    <section className="flex flex-col gap-4 border border-border bg-surface p-3" data-testid="guest-survey">
                        <p className="text-sm">{t('guest.survey.intro')}</p>
                        {FIELDS.map((f) => (
                            <fieldset className="flex flex-col gap-1" key={f}>
                                <legend className="text-sm font-medium">{t(`guest.survey.${f}` as MessageKey)}</legend>
                                <div className="flex gap-2">
                                    {[1, 2, 3, 4, 5].map((n) => (
                                        <label className="flex flex-col items-center gap-1 border border-border px-3 py-2 text-sm has-[:checked]:bg-accent/20" key={n}>
                                            <input checked={ratings[f] === n} name={f} onChange={() => setRatings({ ...ratings, [f]: n })} type="radio" value={n} />
                                            {n}
                                        </label>
                                    ))}
                                </div>
                            </fieldset>
                        ))}
                        <FormField label={t('guest.survey.comment')}><Input maxLength={500} onChange={(e) => setComment(e.target.value)} value={comment} /></FormField>
                        <div><Button disabled={ratings.overall === null} loading={action.busy} onClick={() => void send()} type="button">{t('guest.survey.send')}</Button></div>
                    </section>
                )}
            </GuestShell>
        </>
    );
}
