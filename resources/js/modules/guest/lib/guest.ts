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
