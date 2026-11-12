import { useEffect, useRef, useState } from 'react';

export type SpaBooking = {
    id: string;
    number: string;
    room_id: string;
    room_name: string;
    treatment_id: string;
    treatment_name: string;
    minutes: number;
    therapist_id: string;
    therapist_name: string;
    date: string;
    start: string;
    end: string;
    guest_name: string;
    guest_room: string | null;
    reservation_id: string | null;
    status: 'booked' | 'accepted' | 'declined' | 'in_progress' | 'done' | 'cancelled' | 'no_show';
    reason: string | null;
    price_minor: number;
    service_charge_minor: number;
    tax_minor: number;
    total_minor: number;
    waive_service_charge: boolean;
    waive_tax: boolean;
    payment_method: string | null;
    charged_to_room: boolean;
    paid: boolean;
    commission_minor: number;
    commission_bp: number;
    started_at: string | null;
    ended_at: string | null;
    ends_at: string | null;
    lock_version: number;
};

/** The clock of the server, ticking every second: a phone whose clock is a few minutes off still counts a treatment down correctly. */
export function useServerClock(serverNow: string): number {
    const skew = useRef(0);
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        skew.current = new Date(serverNow).getTime() - Date.now();
        setNow(Date.now() + skew.current);
    }, [serverNow]);

    useEffect(() => {
        const tick = window.setInterval(() => setNow(Date.now() + skew.current), 1000);

        return () => window.clearInterval(tick);
    }, []);

    return now;
}

/** Looks again at the server now and then (`reload` is called), so a screen left open stays true. */
export function useRefresh(reload: () => void, everyMs: number): void {
    const latest = useRef(reload);
    latest.current = reload;

    useEffect(() => {
        const id = window.setInterval(() => latest.current(), everyMs);

        return () => window.clearInterval(id);
    }, [everyMs]);
}

/** Seconds left of a running treatment (0 when over). */
export function secondsLeft(b: Pick<SpaBooking, 'ends_at'>, now: number): number {
    return b.ends_at === null ? 0 : Math.max(0, Math.floor((new Date(b.ends_at).getTime() - now) / 1000));
}

/** 12:05 for 725 seconds; 1:02:03 from an hour. */
export function clock(seconds: number): string {
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;

    return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}` : `${m}:${String(s).padStart(2, '0')}`;
}

/** The part of the treatment that has run, 0 to 100. */
export function percentDone(b: Pick<SpaBooking, 'ends_at' | 'minutes'>, now: number): number {
    if (b.ends_at === null || b.minutes <= 0) return 0;
    const total = b.minutes * 60;

    return Math.min(100, Math.max(0, Math.round(((total - secondsLeft(b, now)) / total) * 100)));
}

/** What the therapist earns on a treatment that runs, for the minutes gone so far: a share of the full share, shown while it runs (the fixed amount is set when it is done). */
export function earnedSoFar(b: Pick<SpaBooking, 'price_minor' | 'commission_bp' | 'minutes' | 'ends_at'>, now: number): number {
    const full = Math.floor((b.price_minor * b.commission_bp + 5000) / 10000);

    return Math.floor((full * percentDone(b, now)) / 100);
}
