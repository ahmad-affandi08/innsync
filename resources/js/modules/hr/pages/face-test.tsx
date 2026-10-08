import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { HrShell } from '@/modules/hr/components/hr-shell';
import { useTranslation } from '@/shared/i18n/i18n';
import { descriptorOf } from '@/shared/lib/face';

type Slot = { state: 'empty' | 'reading' | 'found' | 'none' | 'error'; face: number[] | null };

const distance = (a: number[], b: number[]) => Math.sqrt(a.reduce((sum, x, i) => sum + (x - (b[i] ?? 0)) ** 2, 0));

/** Two photos, one number: how far apart the face reader thinks the two faces are, against the distance the hotel allows. Nothing is sent to the server or kept. */
export default function FaceTestPage({ maxDistance }: { maxDistance: number }) {
    const { t } = useTranslation();
    const [slots, setSlots] = useState<Slot[]>([{ state: 'empty', face: null }, { state: 'empty', face: null }]);
    const [key, setKey] = useState(0);

    async function read(index: number, file: File | undefined) {
        const set = (state: Slot['state'], face: number[] | null) => setSlots((all) => all.map((s, i) => (i === index ? { state, face } : s)));

        if (file === undefined) return set('empty', null);

        set('reading', null);

        try {
            const face = await descriptorOf(file);

            set(face === null ? 'none' : 'found', face);
        } catch {
            set('error', null);
        }
    }

    const [a, b] = slots;
    const gap = a?.face != null && b?.face != null ? distance(a.face, b.face) : null;

    return (
        <HrShell description={t('hr.facetest.description')} title={t('hr.facetest.title')}>
            <p className="max-w-3xl text-sm text-muted-foreground">{t('hr.facetest.how')}</p>
            <div className="grid gap-4 sm:grid-cols-2">
                {slots.map((slot, i) => (
                    <FormField field={`photo${i}`} key={`${key}-${i}`} label={t('hr.face.photo', { n: i + 1 })}>
                        <div className="flex flex-col gap-1">
                            <Input accept="image/*" capture="user" onChange={(e) => void read(i, e.target.files?.[0])} type="file" />
                            <span className="text-xs text-muted-foreground">{t(`hr.face.shot.${slot.state}` as 'hr.face.shot.empty')}</span>
                        </div>
                    </FormField>
                ))}
            </div>
            {gap !== null ? (
                <div className="flex flex-col gap-2" data-testid="face-test-result">
                    <p className="text-3xl font-semibold tabular-nums">{gap.toFixed(3)}</p>
                    <Alert title={t(gap <= maxDistance ? 'hr.facetest.same' : 'hr.facetest.different', { max: maxDistance.toFixed(2) })} tone={gap <= maxDistance ? 'success' : 'warning'}>{t('hr.facetest.hint')}</Alert>
                </div>
            ) : null}
            <div className="flex flex-wrap gap-2">
                <Button onClick={() => { setSlots([{ state: 'empty', face: null }, { state: 'empty', face: null }]); setKey((k) => k + 1); }} type="button" variant="outline">{t('hr.facetest.again')}</Button>
                <Button asChild variant="outline"><Link href="/hr/face">{t('hr.facetest.back')}</Link></Button>
            </div>
        </HrShell>
    );
}
