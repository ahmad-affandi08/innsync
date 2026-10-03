export type Priority = 'urgent' | 'high' | 'normal' | 'low';
export type Status = 'open' | 'assigned' | 'in_progress' | 'on_hold' | 'done' | 'cancelled';

export type WorkOrderSummary = {
    id: string; number: string; title: string; category: string; department: string; room_id: string | null; room: string | null; area: string | null; priority: Priority; status: Status; hold_reason: string | null;
    reported_at: string; reported_by: string | null; due_at: string; assigned_to: string | null; assigned_name: string | null; overdue: boolean; minutes_left: number | null; lock_version: number; off_sale: boolean;
};

export type Overview = {
    work_orders: WorkOrderSummary[]; counts: Record<Status, number>; overdue: number; me: string; rooms: { id: string; number: string }[]; technicians: { id: string; name: string }[];
    categories: string[]; departments: string[]; priorities: Priority[]; hold_reasons: string[]; sla: Record<Priority, number>; sla_is_baseline: boolean; sla_lock_version: number | null; business_date: string;
    may: { report: boolean; manage: boolean; perform: boolean };
};

export type WorkOrderDetail = {
    work_order: WorkOrderSummary & {
        description: string | null; hold_note: string | null; done_note: string | null; cancel_reason: string | null; has_report_photo: boolean; has_done_photo: boolean; block: { id: string; kind: string } | null;
        done_by: string | null; done_at: string | null; started_at: string | null;
    };
    events: { kind: string; note: string | null; by: string | null; at: string }[];
    may: { assign: boolean; prioritize: boolean; cancel: boolean; start: boolean; hold: boolean; resume: boolean; complete: boolean; block: boolean; release: boolean };
    technicians: { id: string; name: string }[]; business_date: string; oversold_nights?: string[];
};
