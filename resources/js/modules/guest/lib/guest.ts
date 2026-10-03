/** What the guest pages receive from the server. Money is in minor units. */
export type GuestGroup = { id: string; name: string; min_select: number; max_select: number; modifiers: { id: string; name: string; price_delta_minor: number }[] };
export type GuestItem = { id: string; code: string; name: string; description: string | null; price_minor: number; is_available: boolean; variants: { id: string; name: string; price_minor: number }[]; groups: GuestGroup[] };
export type GuestMenu = {
    hotel: string; currency: string; kind: 'room' | 'table'; label: string; verified: boolean; locked: boolean; guest_name: string | null; can_order: boolean; needs_proof: boolean; available: boolean;
    menu: { id: string; name: string; station: string; items: GuestItem[] }[]; payments: ('qris' | 'room' | 'later')[];
};
export type GuestOrder = {
    id: string; bill_number: string; placed_at: string; payment: 'qris' | 'room' | 'later'; room_charge: 'none' | 'pending' | 'verified' | 'rejected'; note: string | null; bill_status: string; delivery: string | null;
    subtotal_minor: number; total_minor: number | null; currency: string | null; in_progress: boolean; lines: { name: string; variant: string | null; quantity: number; status: string; total_minor: number }[];
};
export type GuestOrders = { hotel: string; kind: 'room' | 'table'; label: string; orders: GuestOrder[] };

export type QrPoint = { id: string; kind: 'room' | 'table'; label: string; is_active: boolean; rotated_at: string | null; lock_version: number };
export type QrOverview = { points: QrPoint[]; missing: number; room_outlets: { id: string; name: string }[] };
export type QueueOrder = {
    id: string; bill_id: string; bill_number: string; kind: 'room' | 'table'; label: string; guest_name: string | null; room_number: string | null; placed_at: string; payment: 'qris' | 'room' | 'later';
    room_charge: 'none' | 'pending' | 'verified' | 'rejected'; verify_note: string | null; subtotal_minor: number; note: string | null; bill_status: string; lines: number; lock_version: number;
};
export type QueueOverview = { orders: QueueOrder[]; pending: number };

export type GuestHelp = {
    hotel: string; label: string; verified: boolean; locked: boolean; categories: string[];
    requests: { id: string; kind: 'request' | 'complaint'; category: string | null; title: string; number: string; status: 'open' | 'in_progress' | 'done' | 'cancelled'; resolution: string | null; sent_at: string }[];
};
export type GuestBill = {
    hotel: string; label: string; verified: boolean; locked: boolean; departure?: string | null;
    bill: { currency: string; outlets: { outlet: string; lines: { date: string; description: string; total_minor: number }[]; total_minor: number }[]; payments: { date: string; method: string; amount_minor: number }[]; total_minor: number; paid_minor: number; balance_minor: number } | null;
};
export type GuestSurvey = { hotel: string; label: string; verified: boolean; locked: boolean; open: boolean; answered: boolean; departure: string | null };
export type SurveyRow = { id: string; overall: number; room: number | null; service: number | null; food: number | null; value: number | null; comment: string | null; complaint_opened: boolean; at: string };
export type SurveyOverview = { days: number; count: number; averages: Record<'overall' | 'room_rating' | 'service_rating' | 'food_rating' | 'value_rating', number | null>; surveys: SurveyRow[] };

export type CheckInView =
    | { hotel: string; state: 'unavailable'; expires_at: string }
    | { hotel: string; state: 'lobby'; expires_at: string }
    | { hotel: string; state: 'too_early'; expires_at: string; opens_on: string; arrival: string }
    | { hotel: string; state: 'waiting'; expires_at: string; submitted_at: string }
    | { hotel: string; state: 'rejected'; expires_at: string; reason: string | null }
    | { hotel: string; state: 'verified'; expires_at: string; room_number: string | null; arrival: string; departure: string; key: { id: string; en: string; note: string | null } }
    | {
        hotel: string; state: 'form'; expires_at: string;
        reservation: { number: string; guest_name: string; arrival: string; departure: string; nights: number; adults: number; children: number; max_adults: number | null; max_children: number | null; room_type: string | null };
        deposit: { currency: string; required_minor: number; held_minor: number; due_minor: number; instructions: { id: string; en: string } };
        notice: { version: number; body_id: string; body_en: string };
        id_types: string[];
    };
export type CheckInArrival = {
    reservation_id: string; number: string; guest_name: string; arrival: string; departure: string; room_type: string | null; has_stay: boolean;
    checkin: { id: string; status: 'submitted' | 'verified' | 'rejected' } | null;
    link: { id: string; token: string; expires_at: string; lock_version: number } | null;
};
export type CheckInOverview = { arrivals: CheckInArrival[]; lobby: { id: string; token: string; expires_at: string } | null; queue_count: number };
export type CheckInRow = {
    id: string; status: 'submitted' | 'verified' | 'rejected'; reservation_id: string; number: string; guest_name: string; arrival: string; departure: string; room_type: string | null; adults: number; children: number;
    submitted_at: string; decided_at: string | null; room_number: string | null; reject_reason: string | null;
    deposit: { currency: string; required_minor: number; held_minor: number; claimed_minor: number | null; reference: string | null };
};
export type CheckInQueue = { waiting: CheckInRow[]; verified: CheckInRow[]; rejected: CheckInRow[]; may_read_identity: boolean };
export type CheckInDetail = CheckInRow & {
    data: { full_name: string; nationality: string; id_type: string; id_number: string; id_valid_until: string | null; visa_number: string | null; address: string; phone: string | null; email: string | null } | null;
    consent: { version: number; at: string; locale: string }; rooms: { id: string; number: string; floor: string | null; ready: boolean }[]; has_photo: boolean; has_signature: boolean; lock_version: number;
};
export type PrivacyNotice = { version: number; body_id: string; body_en: string; digest: string };
