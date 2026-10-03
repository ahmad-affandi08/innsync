import { Link } from '@inertiajs/react';
import qrcode from 'qrcode-generator';
import { useMemo } from 'react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { GuestStaffShell } from '@/modules/guest/components/guest-staff-shell';
import { useTranslation } from '@/shared/i18n/i18n';

type Code = { id: string; kind: 'room' | 'table'; label: string; token: string };

/** An SVG QR code made in the browser: the token never leaves the page to be drawn. */
function qrSvg(url: string): string {
    const code = qrcode(0, 'M');
    code.addData(url);
    code.make();

    return code.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
}

/** The codes of the rooms and tables on a sheet to print and cut out. Opening this page is audited, because it shows every token. */
export default function QrPrintPage({ codes }: { codes: Code[] }) {
    const { t } = useTranslation();
    const origin = typeof window === 'undefined' ? '' : window.location.origin;
    const svgs = useMemo(() => codes.map((c) => qrSvg(`${origin}/g/${c.token}`)), [codes, origin]);

    return (
        <GuestStaffShell
            actions={<><Button onClick={() => window.print()} type="button">{t('guest.qr.printNow')}</Button><Button asChild variant="outline"><Link href="/guest/qr">{t('guest.qr.back')}</Link></Button></>}
            description={t('guest.qr.printDescription')}
            title={t('guest.qr.printTitle')}
            wide
        >
            {codes.length === 0 ? <EmptyState illustration="checklist" title={t('guest.qr.none')} /> : (
                <ul className="grid grid-cols-2 gap-4 sm:grid-cols-3 print:grid-cols-3" data-testid="qr-sheet">
                    {codes.map((c, i) => (
                        <li className="flex break-inside-avoid flex-col items-center gap-2 border border-border p-3 text-center" key={c.id}>
                            <div aria-label={c.label} className="w-full max-w-[9rem]" dangerouslySetInnerHTML={{ __html: svgs[i] }} role="img" />
                            <p className="text-sm font-semibold">{c.label}</p>
                            <p className="text-xs text-muted-foreground">{t('guest.qr.scan')}</p>
                        </li>
                    ))}
                </ul>
            )}
        </GuestStaffShell>
    );
}
