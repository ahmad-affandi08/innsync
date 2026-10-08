import { ArrowLeft, ArrowRight, Star, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/error-state';
import { useServerAction } from '@/shared/api/use-server-action';
import { useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

const MAX = 6;

type Props = { typeId: string; typeName: string; photos: string[]; open: boolean; onClose: (photos: string[]) => void };

/** The photos of a room type, as the guest sees them on the booking page: up to six, the first is the main one. */
export function RoomPhotosDialog({ onClose, open, photos: initial, typeId, typeName }: Props) {
    const { t } = useTranslation();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [photos, setPhotos] = useState(initial);
    const file = useRef<HTMLInputElement>(null);

    const base = `/property/room-types/${typeId}/photos`;
    const adopt = (r: { photos: string[] } | null) => { if (r !== null) setPhotos(r.photos); };

    async function upload(chosen: FileList | null) {
        if (chosen === null || chosen.length === 0) return;
        for (const f of Array.from(chosen).slice(0, MAX - photos.length)) {
            const body = new FormData();
            body.append('photo', f);
            const result = await action.run<{ photos: string[] }>(base, { body });
            if (result === null) break;
            adopt(result);
        }
        if (file.current !== null) file.current.value = '';
    }

    const move = (index: number, to: number) => {
        const next = [...photos];
        const [item] = next.splice(index, 1);
        next.splice(to, 0, item);
        void action.run<{ photos: string[] }>(`${base}/order`, { method: 'PUT', body: { ids: next } }).then(adopt);
    };

    return (
        <Dialog
            footer={<Button onClick={() => onClose(photos)} type="button">{t('prop.photos.done')}</Button>}
            onClose={() => onClose(photos)}
            open={open}
            title={t('prop.photos.title', { name: typeName })}
        >
            <div className="flex flex-col gap-3">
                <p className="text-sm text-muted-foreground">{t('prop.photos.hint', { max: MAX })}</p>
                {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                {action.fieldError('photo') !== undefined ? <Alert title={action.fieldError('photo') ?? ''} tone="danger" /> : null}
                {photos.length === 0 ? <p className="text-sm text-muted-foreground">{t('prop.photos.none')}</p> : (
                    <ul className="grid grid-cols-2 gap-3" data-testid="room-photos">
                        {photos.map((id, i) => (
                            <li className="flex flex-col gap-1.5" key={id}>
                                <div className="relative aspect-[4/3] overflow-hidden border border-border bg-surface-muted">
                                    <img alt={t('prop.photos.alt', { name: typeName, n: i + 1 })} className="size-full object-cover" loading="lazy" src={`${base}/${id}?size=thumb`} />
                                    {i === 0 ? <span className="absolute left-1 top-1 bg-foreground px-1.5 py-0.5 text-[10px] font-semibold uppercase text-background">{t('prop.photos.main')}</span> : null}
                                </div>
                                <div className="flex items-center justify-between gap-1">
                                    <div className="flex gap-1">
                                        <Button aria-label={t('prop.photos.earlier')} disabled={action.busy || i === 0} onClick={() => move(i, i - 1)} size="sm" type="button" variant="outline"><ArrowLeft aria-hidden="true" className="size-4" /></Button>
                                        <Button aria-label={t('prop.photos.later')} disabled={action.busy || i === photos.length - 1} onClick={() => move(i, i + 1)} size="sm" type="button" variant="outline"><ArrowRight aria-hidden="true" className="size-4" /></Button>
                                        {i > 0 ? <Button aria-label={t('prop.photos.makeMain')} disabled={action.busy} onClick={() => move(i, 0)} size="sm" type="button" variant="outline"><Star aria-hidden="true" className="size-4" /></Button> : null}
                                    </div>
                                    <Button aria-label={t('prop.photos.remove')} disabled={action.busy} onClick={() => void action.run<{ photos: string[] }>(`${base}/${id}`, { method: 'DELETE' }).then(adopt)} size="sm" type="button" variant="outline"><Trash2 aria-hidden="true" className="size-4" /></Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
                {photos.length < MAX ? (
                    <div>
                        <input accept="image/png,image/jpeg,image/webp" className="sr-only" id={`photo-${typeId}`} multiple onChange={(e) => void upload(e.target.files)} ref={file} type="file" />
                        <Button asChild loading={action.busy} variant="outline"><label className="cursor-pointer" htmlFor={`photo-${typeId}`}>{t('prop.photos.add')}</label></Button>
                    </div>
                ) : <p className="text-sm text-muted-foreground">{t('prop.photos.full', { max: MAX })}</p>}
            </div>
        </Dialog>
    );
}
