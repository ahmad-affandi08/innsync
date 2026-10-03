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

export type KitchenSettings = { late_after_minutes: number; stock_location_id: string | null; lock_version: number | null; is_baseline: boolean; locations: { id: string; code: string; name: string; kind: string }[]; may: { manage: boolean } };

export type BoardPageProps = { board: Board; sold_out: { items: SoldOutItem[] }; settings: KitchenSettings };

export type RecipeDish = {
    id: string; code: string; name: string; category: string; outlet: string; price_minor: number; version: number | null; effective_from: string | null; ingredients: number;
    cost_minor: number | null; cost_complete: boolean; food_cost_bp: number | null; scheduled: { version: number; effective_from: string } | null;
};

export type RecipeOverview = { currency: string; dishes: RecipeDish[]; business_date: string; stock_location_id: string | null; may: { manage: boolean } };

export type Consumption = { id: string; bill_number: string; item_name: string; portions: number; version: number; business_date: string; ingredient: string; unit: string; quantity_milli: number; at: string };

export type RecipeLine = { item_id: string; code: string; name: string; unit: string; quantity_milli: number; waste_bp: number };

export type RecipeVersion = { id: string; version: number; effective_from: string; yield_portions: number; reason: string; created_at: string; cost_minor: number; cost_complete: boolean; scheduled: boolean; lines: RecipeLine[] };

export type Ingredient = { id: string; code: string; name: string; base_unit: string; units: string[] };

export type RecipeShow = {
    currency: string; business_date: string; dish: { id: string; code: string; name: string; category: string; outlet: string; price_minor: number }; versions: RecipeVersion[]; ingredients: Ingredient[]; may: { manage: boolean };
};

export type RecipesPageProps = { overview: RecipeOverview; consumed: { consumptions: Consumption[] } };

/** "0.5" → 500: at most three decimals, above zero. */
export function parseMilli(text: string): number | null {
    const m = /^(\d{1,9})(?:[.,](\d{1,3}))?$/.exec(text.trim());

    if (m === null) return null;
    const value = Number(m[1]) * 1000 + Number((m[2] ?? '').padEnd(3, '0'));

    return value > 0 && value <= 100_000_000 ? value : null;
}

export function formatMilli(milli: number): string {
    const whole = Math.floor(milli / 1000);
    const frac = String(milli % 1000).padStart(3, '0').replace(/0+$/, '');

    return frac === '' ? String(whole) : `${whole}.${frac}`;
}

/** "5" or "2.5" percent → basis points; null when it is not between 0 and 50. */
export function parsePercentBp(text: string): number | null {
    const t = text.trim() === '' ? '0' : text.trim();
    const m = /^(\d{1,2})(?:[.,](\d{1,2}))?$/.exec(t);

    if (m === null) return null;
    const bp = Number(m[1]) * 100 + Number((m[2] ?? '').padEnd(2, '0'));

    return bp <= 5000 ? bp : null;
}

export function formatBp(bp: number): string {
    return `${(bp / 100).toFixed(bp % 100 === 0 ? 0 : bp % 10 === 0 ? 1 : 2)}`;
}
