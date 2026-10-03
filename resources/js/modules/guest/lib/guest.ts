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
