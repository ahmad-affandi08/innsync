export type TicketStatus = 'new' | 'preparing' | 'ready' | 'served' | 'cancelled';
export type TicketAction = 'start' | 'ready' | 'serve';

export type TicketLine = { id: string; name: string; variant: string | null; modifiers: string[]; quantity: number; note: string | null; cancelled: boolean };

export type Ticket = {
    id: string; station: 'kitchen' | 'bar'; status: TicketStatus; bill_number: string; batch_number: number; outlet_code: string; place_kind: 'table' | 'room' | 'counter'; place: string | null;
    received_at: string; started_at: string | null; ready_at: string | null; served_at: string | null; waiting_seconds: number; is_late: boolean; lock_version: number; lines: TicketLine[];
};

export type Board = {
    station: 'kitchen' | 'bar'; stations: { key: 'kitchen' | 'bar'; open: number }[]; tickets: Ticket[]; served: Ticket[]; late_after_minutes: number; loaded_at: string; may: { settings: boolean };
};

export type SoldOutItem = { id: string; code: string; name: string; category: string; outlet: string; is_available: boolean };

export type KitchenSettings = { late_after_minutes: number; lock_version: number | null; is_baseline: boolean; may: { manage: boolean } };

export type BoardPageProps = { board: Board; sold_out: { items: SoldOutItem[] }; settings: KitchenSettings };
