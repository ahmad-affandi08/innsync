/** What the F&B setup screens receive from the server. Money is in minor units. */
export type Outlet = { id: string; code: string; name: string; kind: string; charge_scope: string; prices_include_charges: boolean; is_active: boolean; lock_version: number; tables?: number };
export type OutletsOverview = { outlets: Outlet[]; kinds: string[]; scopes: string[]; may: { manage: boolean } };
export type Table = { id: string; outlet_id: string; code: string; area: string | null; seats: number; is_active: boolean; lock_version: number };
export type TablesOverview = { outlet: Outlet; tables: Table[]; may: { manage: boolean } };

export type Category = { id: string; outlet_id: string; code: string; name: string; station: string; sort_order: number; is_active: boolean; lock_version: number };
export type Variant = { id: string | null; name: string; price_minor: number; is_active?: boolean };
export type MenuItem = {
    id: string; category_id: string; outlet_id: string; code: string; name: string; description: string | null; price_minor: number; station: string | null; effective_station: string;
    is_available: boolean; is_active: boolean; sort_order: number; lock_version: number; variants: (Variant & { id: string; is_active: boolean })[]; group_ids: string[];
};
export type Choice = { id: string | null; name: string; price_delta_minor: number; is_active?: boolean };
export type ModifierGroup = { id: string; code: string; name: string; min_select: number; max_select: number; is_active: boolean; lock_version: number; modifiers: (Choice & { id: string; is_active: boolean })[] };
export type MenuView = {
    currency: string; outlets: { id: string; code: string; name: string; is_active: boolean }[]; outlet: { id: string; code: string; name: string; prices_include_charges: boolean } | null;
    categories: Category[]; items: MenuItem[]; groups: ModifierGroup[]; stations: string[]; may: { manage: boolean; availability: boolean };
};

/** The floor of an outlet and a bill, as the server sends them. */
export type FloorTable = { id: string; code: string; area: string | null; seats: number; status: 'free' | 'occupied' | 'ordered'; bill_id: string | null; bill_number: string | null; subtotal_minor: number; opened_at: string | null };
export type FloorBill = { id: string; number: string; table: string | null; room: string | null; covers: number; lines: number; sent: boolean; subtotal_minor: number; opened_at: string };
export type Floor = {
    currency: string; outlets: { id: string; code: string; name: string; kind: string }[]; outlet: { id: string; code: string; name: string } | null;
    tables: FloorTable[]; bills: FloorBill[]; rooms: { id: string; number: string }[]; may: { operate: boolean };
};

export type BillLine = {
    id: string; line_no: number; item_name: string; variant_name: string | null; modifiers: { name: string; price_delta_minor: number }[]; quantity: number; note: string | null;
    unit_price_minor: number; modifiers_minor: number; line_total_minor: number; status: 'pending' | 'sent' | 'voided' | 'removed'; station: string; sent_at: string | null; void_reason: string | null;
};
export type OrderGroup = { id: string; name: string; min_select: number; max_select: number; modifiers: { id: string; name: string; price_delta_minor: number }[] };
export type OrderItem = { id: string; code: string; name: string; description: string | null; price_minor: number; is_available: boolean; variants: { id: string; name: string; price_minor: number }[]; groups: OrderGroup[] };
export type OrderCategory = { id: string; name: string; station: string; items: OrderItem[] };
export type BillApproval = { id: string; subject_type: string; subject_ref: string; status: string; consumed: boolean };
export type BillView = {
    currency: string;
    bill: { id: string; number: string; status: 'open' | 'settled' | 'cancelled'; covers: number; note: string | null; business_date: string; opened_at: string; table: string | null; room: string | null; lock_version: number; cancel_reason: string | null; lines: BillLine[] };
    outlet: { id: string; code: string; name: string; prices_include_charges: boolean };
    totals: { subtotal_minor: number; base_minor: number; service_charge_minor: number; tax_minor: number; total_minor: number; scheme_missing: boolean };
    menu: OrderCategory[]; approvals: BillApproval[]; may: { operate: boolean };
};
