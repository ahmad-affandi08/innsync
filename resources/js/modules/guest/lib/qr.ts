import qrcode from 'qrcode-generator';

import type { MessageKey } from '@/locales/en/index';

/** An SVG QR code made in the browser: the token never leaves the page to be drawn. */
export function qrSvg(url: string): string {
    const code = qrcode(0, 'M');
    code.addData(url);
    code.make();

    return code.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
}

/** "Room 101" and "Table M1 · Restaurant" are kept as the codes were made; shown as "Kamar 101" and "Meja M1 · Restaurant" in the language of the person. Any other label is shown as it is. */
export function qrLabel(label: string, t: (key: MessageKey, values?: Record<string, string | number>) => string): string {
    const m = /^(Room|Table) (.+)$/.exec(label);

    return m === null ? label : t((m[1] === 'Room' ? 'guest.qr.roomLabel' : 'guest.qr.tableLabel') as MessageKey, { name: m[2] ?? '' });
}
