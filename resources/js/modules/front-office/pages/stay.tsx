import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Guest = {
    full_name: string; nationality: string; id_type: string; id_number: string; id_valid_until: string | null; visa_number: string | null; address: string | null; identity_visible: boolean;
};
type Stay = {
    id: string; reservation_id: string; room_number: string | null; status: string; adults: number; children: number; checked_in_date: string; expected_departure: string;
    checked_out_date: string | null; has_id_photo: boolean; lock_version: number; guest: Guest;
};

export default function StayPage({ reservation, stay: s }: { reservation: { number: string }; stay: Stay }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [file, setFile] = useState<File | null>(null);
    const [pickerKey, setPickerKey] = useState(0);
    const [confirming, setConfirming] = useState(false);
    const [saved, setSaved] = useState<'photo' | 'out' | null>(null);
    const inHouse = s.status === 'in_house';
    const reload = ['stay'];

    async function upload() {
        if (file === null) return;
        const body = new FormData();
        body.set('photo', file);
        const done = await action.run(`/front-office/stays/${s.id}/id-photo`, { body, reload });
        if (done !== null) {
            setSaved('photo');
            setFile(null);
            setPickerKey((k) => k + 1);
        }
    }

    async function checkOut() {
        const done = await action.run(`/front-office/stays/${s.id}/check-out`, { body: { lock_version: s.lock_version }, reload });
        setConfirming(false);
        if (done !== null) setSaved('out');
    }

    const g = s.guest;

    return (
        <FrontOfficeShell description={t('fo.stay.description', { number: reservation.number })} title={t('fo.stay.title', { room: s.room_number ?? '', guest: g.full_name })}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <Button asChild size="sm" variant="outline"><Link href={`/front-office/reservations/${s.reservation_id}`}>{t('fo.stay.reservation')}</Link></Button>
                <StatusBadge label={t(`fo.stay.status.${s.status}` as 'fo.stay.status.in_house')} tone={inHouse ? 'success' : 'neutral'} />
            </div>

            {action.error !== null && !confirming ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            {saved === 'out' ? <Alert title={t('fo.stay.checkOutDone')} tone="success" /> : null}

            <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[max-content_1fr]">
                <dt className="text-muted-foreground">{t('fo.stay.checkedIn')}</dt><dd>{format.date(s.checked_in_date, 'long')}</dd>
                <dt className="text-muted-foreground">{t('fo.stay.expected')}</dt><dd>{format.date(s.expected_departure, 'long')}</dd>
                {s.checked_out_date !== null && <><dt className="text-muted-foreground">{t('fo.stay.checkedOut')}</dt><dd>{format.date(s.checked_out_date, 'long')}</dd></>}
                <dt className="text-muted-foreground">{t('fo.res.guests', { adults: s.adults, children: s.children })}</dt><dd />
            </dl>

            <section aria-labelledby="reg-h" className="flex flex-col gap-2">
                <h2 className="text-lg font-semibold" id="reg-h">{t('fo.stay.identity')}</h2>
                <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[max-content_1fr]">
                    <dt className="text-muted-foreground">{t('fo.checkin.fullName')}</dt><dd>{g.full_name}</dd>
                    <dt className="text-muted-foreground">{t('fo.checkin.nationality')}</dt><dd>{g.nationality}</dd>
                    <dt className="text-muted-foreground">{t('fo.checkin.idType')}</dt><dd>{t(`fo.checkin.idType.${g.id_type}` as 'fo.checkin.idType.ktp')}</dd>
                    <dt className="text-muted-foreground">{t('fo.checkin.idNumber')}</dt><dd className="font-mono">{g.id_number}</dd>
                    <dt className="text-muted-foreground">{t('fo.checkin.idValidUntil')}</dt><dd>{g.id_valid_until === null ? '—' : format.date(g.id_valid_until)}</dd>
                    {g.visa_number !== null && <><dt className="text-muted-foreground">{t('fo.checkin.visa')}</dt><dd className="font-mono">{g.visa_number}</dd></>}
                    {g.address !== null && <><dt className="text-muted-foreground">{t('fo.checkin.address')}</dt><dd className="break-words">{g.address}</dd></>}
                </dl>
                {!g.identity_visible ? <p className="text-xs text-muted-foreground">{t('fo.stay.identityHidden')}</p> : null}
            </section>

            <section aria-labelledby="photo-h" className="flex flex-col gap-2">
                <h2 className="text-lg font-semibold" id="photo-h">{t('fo.stay.photo')}</h2>
                <p className="text-xs text-muted-foreground">{t('fo.stay.photoNote')}</p>
                {saved === 'photo' ? <Alert title={t('fo.stay.photoSaved')} tone="success" /> : null}
                {s.has_id_photo ? (
                    g.identity_visible ? <div><Button asChild size="sm" variant="outline"><a href={`/front-office/stays/${s.id}/id-photo`} rel="noreferrer" target="_blank">{t('fo.stay.photoView')}</a></Button></div> : null
                ) : <p className="text-sm text-muted-foreground">{t('fo.stay.photoNone')}</p>}
                {inHouse && (
                    <div className="flex flex-wrap items-end gap-2">
                        <FormField error={action.fieldError('photo')} label={t('fo.stay.photoChoose')}><Input accept="image/jpeg,image/png" capture="environment" key={pickerKey} onChange={(e) => setFile(e.target.files?.[0] ?? null)} type="file" /></FormField>
                        <Button disabled={file === null} loading={action.busy} onClick={() => void upload()} type="button" variant="outline">{s.has_id_photo ? t('fo.stay.photoReplace') : t('fo.stay.photoUpload')}</Button>
                    </div>
                )}
            </section>

            {inHouse && <div><Button onClick={() => { action.clear(); setConfirming(true); }} type="button">{t('fo.stay.checkOut')}</Button></div>}

            <ConfirmDialog
                cancelLabel={t('fo.action.back')}
                confirmLabel={t('fo.stay.checkOut')}
                consequence={t('fo.stay.checkOutConsequence')}
                destructive
                onCancel={() => setConfirming(false)}
                onConfirm={() => void checkOut()}
                open={confirming}
                pending={action.busy}
                title={t('fo.stay.checkOut')}
            >
                {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            </ConfirmDialog>
        </FrontOfficeShell>
    );
}
