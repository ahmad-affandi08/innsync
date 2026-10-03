export type Priority = 'urgent' | 'high' | 'normal' | 'low';
export type Status = 'open' | 'assigned' | 'in_progress' | 'on_hold' | 'done' | 'cancelled';

export type WorkOrderSummary = {
    id: string; number: string; title: string; category: string; department: string; room_id: string | null; room: string | null; area: string | null; priority: Priority; status: Status; hold_reason: string | null;
    reported_at: string; reported_by: string | null; due_at: string; assigned_to: string | null; assigned_name: string | null; overdue: boolean; minutes_left: number | null; lock_version: number; off_sale: boolean;
};

export type Overview = {
    work_orders: WorkOrderSummary[]; counts: Record<Status, number>; overdue: number; me: string; rooms: { id: string; number: string }[]; technicians: { id: string; name: string }[];
    escalations: Escalation[]; escalation: { warn: number; escalate: number; night_from: number; night_to: number };
    assets: { id: string; number: string; name: string }[]; categories: string[]; departments: string[]; priorities: Priority[]; hold_reasons: string[]; sla: Record<Priority, number>; sla_is_baseline: boolean; sla_lock_version: number | null; business_date: string;
    may: { report: boolean; manage: boolean; perform: boolean };
};

export type WorkOrderDetail = {
    work_order: WorkOrderSummary & {
        description: string | null; hold_note: string | null; done_note: string | null; cancel_reason: string | null; has_report_photo: boolean; has_done_photo: boolean; block: { id: string; kind: string } | null; asset: { id: string; number: string; name: string } | null; preventive: boolean;
        done_by: string | null; done_at: string | null; started_at: string | null;
    };
    events: { kind: string; note: string | null; by: string | null; at: string }[];
    may: { assign: boolean; prioritize: boolean; cancel: boolean; start: boolean; hold: boolean; resume: boolean; complete: boolean; block: boolean; release: boolean };
    technicians: { id: string; name: string }[]; business_date: string; oversold_nights?: string[];
};

export type Escalation = { id: string; work_order_id: string; number: string; title: string; priority: Priority; place: string | null; level: 1 | 2; target: 'supervisor' | 'mod'; shift: 'day' | 'night'; raised_at: string; overdue: boolean; assigned: boolean };

export type MaintenanceReport = {
    from: string; to: string; reported: number; by_status: Partial<Record<Status, number>>; by_department: Record<string, number>;
    completion: { done: number; average_hours: number | null; on_time: number; on_time_percent: number | null; by_priority: { priority: Priority; done: number; average_hours: number | null }[] };
    repeat_rooms: { room: string; count: number; categories: string[] }[]; unsellable: { total_days: number; rooms: { room: string; days: number }[] };
};

export type AssetSummary = {
    id: string; number: string; name: string; category: string; serial: string | null; room_id: string | null; room: string | null; area: string | null; acquired_on: string; warranty_until: string | null;
    warranty: 'valid' | 'ending' | 'expired' | null; meter_unit: string | null; reading: number | null; status: 'active' | 'retired'; next_due_on: string | null;
};

export type AssetsOverview = {
    assets: AssetSummary[]; rooms: { id: string; number: string }[]; categories: string[]; meter_units: string[]; business_date: string; warranty_flag_days: number; may: { manage: boolean; read: boolean };
};

export type Plan = {
    id: string; title: string; description: string | null; category: string; priority: Priority; trigger: 'calendar' | 'meter'; interval: number; lead_days: number; next_due_on: string | null; next_meter: number | null;
    last_done_on: string | null; active: boolean; lock_version: number; due: boolean;
};

export type AssetDetail = {
    asset: AssetSummary & { notes: string | null; retired_reason: string | null; retired_on: string | null; lock_version: number };
    repairs: { id: string; number: string; title: string; status: Status; priority: Priority; reported_at: string; preventive: boolean }[];
    readings: { reading: number; read_on: string; by: string | null }[]; plans: Plan[]; may: { manage: boolean; read_meter: boolean }; business_date: string;
};
