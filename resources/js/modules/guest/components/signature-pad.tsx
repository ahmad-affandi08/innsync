import { useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { useTranslation } from '@/shared/i18n/i18n';

/** A box to sign in with a finger or a pen. Tells the page the signature as a PNG data URL, or null while the box is empty. */
export function SignaturePad({ onChange }: { onChange: (png: string | null) => void }) {
    const { t } = useTranslation();
    const canvas = useRef<HTMLCanvasElement | null>(null);
    const drawing = useRef(false);
    const [inked, setInked] = useState(false);

    useEffect(() => {
        const ctx = canvas.current?.getContext('2d');
        if (ctx === null || ctx === undefined) return;
        ctx.lineWidth = 2.5;
        ctx.lineCap = 'round';
        ctx.strokeStyle = '#111827';
    }, []);

    function point(event: React.PointerEvent<HTMLCanvasElement>) {
        const rect = event.currentTarget.getBoundingClientRect();

        return { x: ((event.clientX - rect.left) * event.currentTarget.width) / rect.width, y: ((event.clientY - rect.top) * event.currentTarget.height) / rect.height };
    }

    function down(event: React.PointerEvent<HTMLCanvasElement>) {
        const ctx = event.currentTarget.getContext('2d');
        if (ctx === null) return;
        event.currentTarget.setPointerCapture(event.pointerId);
        drawing.current = true;
        const p = point(event);
        ctx.beginPath();
        ctx.moveTo(p.x, p.y);
    }

    function move(event: React.PointerEvent<HTMLCanvasElement>) {
        if (!drawing.current) return;
        const ctx = event.currentTarget.getContext('2d');
        if (ctx === null) return;
        const p = point(event);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
        setInked(true);
    }

    function up() {
        drawing.current = false;
        if (canvas.current !== null && inked) onChange(canvas.current.toDataURL('image/png'));
    }

    function clear() {
        const el = canvas.current;
        el?.getContext('2d')?.clearRect(0, 0, el.width, el.height);
        setInked(false);
        onChange(null);
    }

    return (
        <div className="flex flex-col gap-2">
            <canvas aria-label={t('guest.checkin.signatureArea')} className="h-40 w-full touch-none border border-border bg-white" data-testid="signature-pad" height={160} onPointerDown={down} onPointerMove={move} onPointerUp={up} ref={canvas} width={640} />
            <div><Button disabled={!inked} onClick={clear} size="sm" type="button" variant="outline">{t('guest.checkin.clearSignature')}</Button></div>
        </div>
    );
}
