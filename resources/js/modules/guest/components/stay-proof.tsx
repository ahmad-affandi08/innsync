import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { useServerAction } from '@/shared/api/use-server-action';
import { useTranslation } from '@/shared/i18n/i18n';

/** Asks the guest for the room number and a name, and tells the page when the stay is confirmed. A wrong answer says the same thing whichever half was wrong. */
export function StayProof({ locked, onDone }: { locked: boolean; onDone: () => void }) {
    const { t } = useTranslation();
    const action = useServerAction();
    const [room, setRoom] = useState('');
    const [surname, setSurname] = useState('');
    const [failed, setFailed] = useState(false);

    async function prove() {
        setFailed(false);
        const done = await action.run('/g/verify', { body: { room_number: room.trim(), surname: surname.trim() } });

        if (done === null) {
            setFailed(true);

            return;
        }

        onDone();
    }

    return (
        <div className="flex flex-col gap-3 border border-border bg-surface p-3" data-testid="guest-proof">
            <p className="text-sm font-medium">{t('guest.proof.title')}</p>
            <p className="text-xs text-muted-foreground">{t('guest.proof.hint')}</p>
            {failed ? <Alert title={locked ? t('guest.proof.locked') : t('guest.proof.failed')} tone="warning" /> : null}
            <div className="grid gap-3 sm:grid-cols-2">
                <FormField label={t('guest.proof.room')}><Input autoComplete="off" onChange={(e) => setRoom(e.target.value)} value={room} /></FormField>
                <FormField label={t('guest.proof.name')}><Input autoComplete="off" onChange={(e) => setSurname(e.target.value)} value={surname} /></FormField>
            </div>
            <div><Button disabled={room.trim() === '' || surname.trim() === ''} loading={action.busy} onClick={() => void prove()} type="button">{t('guest.proof.confirm')}</Button></div>
        </div>
    );
}
