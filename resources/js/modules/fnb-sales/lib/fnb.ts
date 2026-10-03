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
