import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { TimeInput } from '@/components/ui/time-input';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Reminder = { id: string; due_on: string; due_time: string | null; text: string; status: string; reservation_id: string | null; reservation_number: string | null; guest_name: string | null; room_number: string | null; created_by_name: string | null; done_by_name: string | null; done_at: string | null };
type Props = { reminders: { today: string; open: Reminder[]; done: Reminder[]; may_write: boolean } };

/** What the desk must remember: a wake-up call, extra towels, a guest to call back. Open ones are listed by day and time; whoever is on duty ticks them off. */
export default function RemindersPage({ reminders }: Props) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState({ due_on: reminders.today, due_time: '', text: '' });

    async function add() {
        const done = await action.run('/front-office/reminders', { body: { ...form, due_time: form.due_time === '' ? null : form.due_time }, reload: ['reminders', 'shell'] });
        if (done !== null) setForm({ ...form, due_time: '', text: '' });
    }

    const run = (path: string) => action.run(path, { reload: ['reminders', 'shell'] });
    const due = (r: Reminder) => r.due_on <= reminders.today;

    const item = (r: Reminder, done: boolean) => (
        <li className="flex flex-wrap items-start justify-between gap-3 py-3" data-testid={`reminder-${r.id}`} key={r.id}>
            <div className="min-w-0 flex-1">
                <p className={done ? 'text-muted-foreground line-through' : 'font-medium'}>{r.text}</p>
                <p className="text-xs text-muted-foreground">
                    {format.date(r.due_on)}{r.due_time !== null ? ` · ${r.due_time}` : ''}
                    {r.room_number !== null ? ` · ${t('fo.rem.room', { room: r.room_number })}` : ''}
                    {r.reservation_id !== null ? <> · <Link className="underline underline-offset-2" href={`/front-office/reservations/${r.reservation_id}`}>{r.guest_name} {r.reservation_number}</Link></> : null}
                    {done && r.done_by_name !== null ? ` · ${t('fo.rem.doneBy', { name: r.done_by_name })}` : r.created_by_name !== null ? ` · ${r.created_by_name}` : ''}
                </p>
            </div>
            <div className="flex items-center gap-2">
                {!done && due(r) ? <StatusBadge label={r.due_on < reminders.today ? t('fo.rem.late') : t('fo.rem.today')} tone={r.due_on < reminders.today ? 'danger' : 'warning'} /> : null}
                {reminders.may_write ? <Button disabled={action.busy} onClick={() => void run(`/front-office/reminders/${r.id}/${done ? 'reopen' : 'done'}`)} size="sm" type="button" variant="outline">{t(done ? 'fo.rem.reopen' : 'fo.rem.done')}</Button> : null}
            </div>
        </li>
    );

    return (
        <FrontOfficeShell description={t('fo.rem.description')} title={t('fo.rem.title')} wide>
            {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}

            {reminders.may_write ? (
                <form className="grid gap-3 border border-border bg-surface p-4 sm:grid-cols-[minmax(0,1fr)_10rem_8rem_auto] sm:items-end" onSubmit={(e) => { e.preventDefault(); void add(); }}>
                    <FormField error={action.fieldError('text')} label={t('fo.rem.text')}><Input maxLength={300} onChange={(e) => setForm({ ...form, text: e.target.value })} placeholder={t('fo.rem.placeholder')} value={form.text} /></FormField>
                    <FormField error={action.fieldError('due_on')} label={t('fo.rem.day')}><DatePicker min={reminders.today} onChange={(e) => setForm({ ...form, due_on: e.target.value })} value={form.due_on} /></FormField>
                    <FormField error={action.fieldError('due_time')} label={t('fo.rem.time')}><TimeInput onChange={(e) => setForm({ ...form, due_time: e.target.value })} value={form.due_time} /></FormField>
                    <Button disabled={form.text.trim() === ''} loading={action.busy} type="submit">{t('fo.rem.add')}</Button>
                </form>
            ) : null}

            <section aria-labelledby="rem-open" className="flex flex-col gap-2">
                <h2 className="text-lg font-semibold" id="rem-open">{t('fo.rem.open', { count: reminders.open.length })}</h2>
                {reminders.open.length === 0 ? <EmptyState title={t('fo.rem.empty')} /> : <ul className="divide-y divide-border border-y border-border">{reminders.open.map((r) => item(r, false))}</ul>}
            </section>

            {reminders.done.length > 0 ? (
                <section aria-labelledby="rem-done" className="flex flex-col gap-2">
                    <h2 className="text-lg font-semibold" id="rem-done">{t('fo.rem.recentDone')}</h2>
                    <ul className="divide-y divide-border border-y border-border">{reminders.done.map((r) => item(r, true))}</ul>
                </section>
            ) : null}
        </FrontOfficeShell>
    );
}
