import { Head } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { FormField } from '@/components/ui/form-field';
import { DatePicker } from '@/components/ui/date-picker';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { GuestShell } from '@/modules/guest/components/guest-shell';
import { SignaturePad } from '@/modules/guest/components/signature-pad';
import type { CheckInView } from '@/modules/guest/lib/guest';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import type { MessageKey } from '@/locales/en/index';

type Form = {
    fullName: string; nationality: string; idType: string; idNumber: string; idValidUntil: string; visa: string; address: string; phone: string; email: string; adults: string; children: string;
    depositClaimed: boolean; depositReference: string; agree: boolean;
};

/** The page a self check-in link opens (FR-GST-001 to -007): the form before arrival, or where the registration stands. Light, one column, nothing to install. */
export default function CheckInPage({ token, view: first }: { token: string; view: CheckInView }) {
    const { locale, t } = useTranslation();
    const format = useFormatters();
    const action = useServerAction();
    const [view, setView] = useState<CheckInView>(first);
    const [photo, setPhoto] = useState<File | null>(null);
    const [signature, setSignature] = useState<string | null>(null);
    const [lookup, setLookup] = useState({ number: '', name: '' });
    const [form, setForm] = useState<Form>(() => ({
        fullName: first.state === 'form' ? first.reservation.guest_name : '', nationality: 'ID', idType: 'ktp', idNumber: '', idValidUntil: '', visa: '', address: '', phone: '', email: '',
        adults: first.state === 'form' ? String(first.reservation.adults) : '1', children: first.state === 'form' ? String(first.reservation.children) : '0', depositClaimed: false, depositReference: '', agree: false,
    }));
    const set = (patch: Partial<Form>) => setForm((f) => ({ ...f, ...patch }));
    const title = t('guest.checkin.title');

    async function find() {
        const done = await action.run<{ token: string }>(`/g/c/${token}/find`, { body: { reservation_number: lookup.number.trim(), name: lookup.name.trim() } });
        if (done !== null) window.location.assign(`/g/c/${done.token}`);
    }

    async function send() {
        if (view.state !== 'form') return;
        const body = new FormData();
        const text: Record<string, string> = {
            full_name: form.fullName.trim(), nationality: form.nationality.trim().toUpperCase(), id_type: form.idType, id_number: form.idNumber.trim(), id_valid_until: form.idValidUntil, visa_number: form.visa.trim(), address: form.address.trim(),
            phone: form.phone.trim(), email: form.email.trim(), adults: form.adults, children: form.children, notice_version: String(view.notice.version), locale, deposit_reference: form.depositReference.trim(),
        };
        for (const [k, v] of Object.entries(text)) body.set(k, v);
        if (form.agree) body.set('agree', '1');
        if (form.depositClaimed) body.set('deposit_claimed', '1');
        if (photo !== null) body.set('photo', photo);
        if (signature !== null) body.set('signature', signature);
        const done = await action.run<{ view: CheckInView }>(`/g/c/${token}`, { body });
        if (done !== null) setView(done.view);
    }

    const wrap = (children: React.ReactNode) => (
        <>
            <Head title={title} />
            <GuestShell hotel={view.hotel} plain title={title}>{children}</GuestShell>
        </>
    );

    if (view.state === 'lobby') {
        return wrap(
            <section className="flex flex-col gap-3 border border-border bg-surface p-3" data-testid="checkin-lobby">
                <p className="text-sm">{t('guest.checkin.lobbyIntro')}</p>
                {action.failure !== null ? <Alert title={action.failure.kind === 'conflict' ? t('guest.checkin.lobbyLocked') : t('guest.checkin.lobbyFailed')} tone="warning" /> : null}
                <FormField label={t('guest.checkin.reservationNumber')}><Input autoComplete="off" onChange={(e) => setLookup({ ...lookup, number: e.target.value })} value={lookup.number} /></FormField>
                <FormField label={t('guest.checkin.nameOnBooking')}><Input autoComplete="name" onChange={(e) => setLookup({ ...lookup, name: e.target.value })} value={lookup.name} /></FormField>
                <div><Button disabled={lookup.number.trim() === '' || lookup.name.trim() === ''} loading={action.busy} onClick={() => void find()} type="button">{t('guest.checkin.lobbyContinue')}</Button></div>
            </section>,
        );
    }

    if (view.state === 'waiting') {
        return wrap(<Alert title={t('guest.checkin.waiting', { time: format.instant(view.submitted_at) })} tone="success">{t('guest.checkin.waitingHint')}</Alert>);
    }

    if (view.state === 'rejected') {
        return wrap(<Alert title={view.reason === null ? t('guest.checkin.rejected') : t('guest.checkin.rejectedWhy', { reason: view.reason })} tone="warning">{t('guest.checkin.rejectedHint')}</Alert>);
    }

    if (view.state === 'too_early') {
        return wrap(<Alert title={t('guest.checkin.tooEarly', { date: format.date(view.opens_on, 'long') })} tone="info" />);
    }

    if (view.state === 'verified') {
        const note = view.key.note;

        return wrap(
            <section className="flex flex-col gap-3 border border-border bg-surface p-4" data-testid="checkin-verified">
                <p className="text-sm text-muted-foreground">{t('guest.checkin.verifiedIntro')}</p>
                <p className="text-sm">{t('guest.checkin.yourRoom')}</p>
                <p className="text-5xl font-semibold" data-testid="checkin-room">{view.room_number ?? '—'}</p>
                <p className="text-sm">{view.key[locale]}</p>
                {note !== null ? <p className="text-sm font-medium">{note}</p> : null}
                <p className="text-xs text-muted-foreground">{t('guest.checkin.stayDates', { arrival: format.date(view.arrival, 'long'), departure: format.date(view.departure, 'long') })}</p>
            </section>,
        );
    }

    if (view.state === 'unavailable') {
        return wrap(<Alert title={t('guest.checkin.unavailable')} tone="info" />);
    }

    const r = view.reservation;
    const due = view.deposit.due_minor;
    const body = locale === 'id' ? view.notice.body_id : view.notice.body_en;
    const ready = photo !== null && signature !== null && form.agree && form.fullName.trim() !== '' && form.idNumber.trim() !== '' && form.address.trim() !== '';

    return wrap(
        <form className="flex flex-col gap-4" data-testid="checkin-form" onSubmit={(e) => { e.preventDefault(); void send(); }}>
            <section className="flex flex-col gap-1 border border-border bg-surface p-3 text-sm">
                <p className="font-medium">{t('guest.checkin.booking', { number: r.number })}</p>
                <p>{t('guest.checkin.stayDates', { arrival: format.date(r.arrival, 'long'), departure: format.date(r.departure, 'long') })}</p>
                {r.room_type !== null ? <p className="text-muted-foreground">{r.room_type}</p> : null}
            </section>
            {action.error !== null ? <Alert title={action.failure?.kind === 'conflict' ? t('guest.checkin.conflict') : t('guest.checkin.fixFields')} tone="warning" /> : null}

            <section className="flex flex-col gap-3 border border-border bg-surface p-3">
                <h2 className="text-sm font-semibold">{t('guest.checkin.details')}</h2>
                <FormField error={action.fieldError('full_name')} label={t('guest.checkin.fullName')}><Input autoComplete="name" maxLength={150} onChange={(e) => set({ fullName: e.target.value })} value={form.fullName} /></FormField>
                <div className="grid gap-3 sm:grid-cols-2">
                    <FormField error={action.fieldError('nationality')} hint={t('guest.checkin.nationalityHint')} label={t('guest.checkin.nationality')}><Input maxLength={2} onChange={(e) => set({ nationality: e.target.value })} value={form.nationality} /></FormField>
                    <FormField error={action.fieldError('id_type')} label={t('guest.checkin.idType')}>
                        <Select onChange={(e) => set({ idType: e.target.value })} searchable={false} value={form.idType}>
                            {view.id_types.map((k) => <option key={k} value={k}>{t(`guest.checkin.idType.${k}` as MessageKey)}</option>)}
                        </Select>
                    </FormField>
                    <FormField error={action.fieldError('id_number')} label={t('guest.checkin.idNumber')}><Input autoComplete="off" maxLength={40} onChange={(e) => set({ idNumber: e.target.value })} value={form.idNumber} /></FormField>
                    <FormField error={action.fieldError('id_valid_until')} label={t('guest.checkin.idValidUntil')}><DatePicker onChange={(e) => set({ idValidUntil: e.target.value })} value={form.idValidUntil} /></FormField>
                    <FormField error={action.fieldError('visa_number')} label={t('guest.checkin.visa')}><Input maxLength={40} onChange={(e) => set({ visa: e.target.value })} value={form.visa} /></FormField>
                    <FormField error={action.fieldError('phone')} label={t('guest.checkin.phone')}><Input autoComplete="tel" maxLength={30} onChange={(e) => set({ phone: e.target.value })} type="tel" value={form.phone} /></FormField>
                </div>
                <FormField error={action.fieldError('address')} label={t('guest.checkin.address')}><Input autoComplete="street-address" maxLength={500} onChange={(e) => set({ address: e.target.value })} value={form.address} /></FormField>
                <FormField error={action.fieldError('email')} label={t('guest.checkin.email')}><Input autoComplete="email" maxLength={150} onChange={(e) => set({ email: e.target.value })} type="email" value={form.email} /></FormField>
                <div className="grid gap-3 sm:grid-cols-2">
                    <FormField error={action.fieldError('adults')} label={t('guest.checkin.adults')}><Input inputMode="numeric" max={r.max_adults ?? 40} min={1} onChange={(e) => set({ adults: e.target.value })} type="number" value={form.adults} /></FormField>
                    <FormField error={action.fieldError('children')} label={t('guest.checkin.children')}><Input inputMode="numeric" max={r.max_children ?? 40} min={0} onChange={(e) => set({ children: e.target.value })} type="number" value={form.children} /></FormField>
                </div>
            </section>

            <section className="flex flex-col gap-3 border border-border bg-surface p-3">
                <h2 className="text-sm font-semibold">{t('guest.checkin.idPhoto')}</h2>
                <p className="text-xs text-muted-foreground">{t('guest.checkin.idPhotoHint')}</p>
                <FormField error={action.fieldError('photo')} label={t('guest.checkin.idPhotoField')}><Input accept="image/jpeg,image/png" capture="environment" onChange={(e) => setPhoto(e.target.files?.[0] ?? null)} type="file" /></FormField>
            </section>

            <section className="flex flex-col gap-3 border border-border bg-surface p-3">
                <h2 className="text-sm font-semibold">{t('guest.checkin.signature')}</h2>
                <p className="text-xs text-muted-foreground">{t('guest.checkin.signatureHint')}</p>
                <SignaturePad onChange={setSignature} />
                {action.fieldError('signature') !== undefined ? <p className="text-sm text-destructive" role="alert">{action.fieldError('signature')}</p> : null}
            </section>

            {due > 0 ? (
                <section className="flex flex-col gap-3 border border-border bg-surface p-3" data-testid="checkin-deposit">
                    <h2 className="text-sm font-semibold">{t('guest.checkin.deposit')}</h2>
                    <p className="text-sm">{t('guest.checkin.depositDue', { amount: format.money(due, view.deposit.currency) })}</p>
                    <p className="text-xs text-muted-foreground">{view.deposit.instructions[locale]}</p>
                    <label className="flex items-center gap-2 text-sm"><Checkbox checked={form.depositClaimed} onCheckedChange={(v) => set({ depositClaimed: v === true })} />{t('guest.checkin.depositPaid')}</label>
                    {form.depositClaimed ? <FormField label={t('guest.checkin.depositReference')}><Input maxLength={60} onChange={(e) => set({ depositReference: e.target.value })} value={form.depositReference} /></FormField> : null}
                </section>
            ) : null}

            <section className="flex flex-col gap-3 border border-border bg-surface p-3" data-testid="checkin-notice">
                <h2 className="text-sm font-semibold">{t('guest.checkin.notice')}</h2>
                <p className="whitespace-pre-line text-sm">{body}</p>
                <label className="flex items-start gap-2 text-sm"><Checkbox checked={form.agree} className="mt-0.5" onCheckedChange={(v) => set({ agree: v === true })} />{t('guest.checkin.agree')}</label>
                {action.fieldError('agree') !== undefined ? <p className="text-sm text-destructive" role="alert">{action.fieldError('agree')}</p> : null}
            </section>

            <div><Button disabled={!ready} loading={action.busy} type="submit">{t('guest.checkin.send')}</Button></div>
        </form>,
    );
}
