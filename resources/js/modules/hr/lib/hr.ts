export type EmployeeStatus = 'active' | 'offboarded';

export type Employee = {
    id: string; number: string; full_name: string; department: string; position: string; joined_on: string; contract_type: string; contract_end_on: string | null; supervisor_id: string | null; supervisor: string | null;
    user_id: string | null; account: string | null; phone: string | null; email: string | null; status: EmployeeStatus; offboarded_on: string | null; offboard_kind: string | null; lock_version: number;
};

export type EmployeeDetail = Employee & {
    subordinates: number; offboard_reason: string | null; offboarded_by: string | null; offboard_items: { item: string; returned: boolean; note: string | null }[];
    supervisors: { id: string; number: string; name: string }[]; may: { edit: boolean; offboard: boolean; documents: boolean };
};

export type HrWarning = { type: 'contract_end' | 'document' | 'missing'; status: 'expired' | 'expiring' | 'missing'; employee_id: string; number: string; name: string; department: string; kind: string; title: string | null; date: string | null; days: number | null };

export type HrSettings = { warn_days: number; required_kinds: string[]; is_baseline: boolean; lock_version: number | null };

export type HrOverview = {
    employees: Employee[]; counts: Record<EmployeeStatus, number>; warnings: HrWarning[]; settings: HrSettings; departments: string[]; contracts: string[]; offboard_kinds: string[]; document_kinds: string[];
    accounts: { id: string; name: string }[]; business_date: string; may: { view: boolean; manage: boolean; documents: boolean };
};

export type HrDocument = { id: string; kind: string; title: string; issued_on: string | null; valid_until: string | null; current: boolean; expired: boolean; by: string | null; at: string };

export type ShiftPattern = { id: string; code: string; name: string; off: boolean; starts_at: string | null; ends_at: string | null; starts2_at: string | null; ends2_at: string | null; minutes: number; active: boolean; lock_version: number };

export type RosterOverview = {
    from: string; to: string; days: string[]; department: string | null; business_date: string;
    employees: { id: string; number: string; name: string; department: string; position: string; joined_on: string; contract_end_on: string | null }[];
    cells: Record<string, Record<string, { pattern_id: string; code: string; off: boolean }>>;
    leave: Record<string, Record<string, string>>;
    patterns: ShiftPattern[]; minimums: { department: string; pattern_id: string; minimum: number }[];
    shortages: { date: string; department: string; pattern_id: string; code: string; have: number; need: number }[];
    departments: string[]; may: { view: boolean; roster: boolean };
};

export type AttendanceStatus = 'upcoming' | 'not_in' | 'on_duty' | 'present' | 'missing_out' | 'absent';

export type AttendanceRecord = { id: string; in_at: string; in_method: 'mobile' | 'manual'; in_distance_m: number | null; has_in_photo: boolean; out_at: string | null; out_method: 'mobile' | 'manual' | null; out_distance_m: number | null; has_out_photo: boolean; manual_reason: string | null; lock_version: number };

export type AttendanceEvaluation = { status: AttendanceStatus; late_minutes: number; early_minutes: number; extra_minutes: number; overtime_minutes: number; unapproved_minutes: number; overtime_granted: number; worked_minutes: number | null; planned_start: string; planned_end: string };

export type AttendanceRow = AttendanceEvaluation & {
    employee: { id: string; number: string; name: string; department: string };
    shift: { code: string; date: string; starts_at: string; ends_at: string; starts2_at: string | null; ends2_at: string | null };
    record: AttendanceRecord | null;
};

export type AttendanceSummaryRow = { employee: { id: string; number: string; name: string; department: string }; scheduled: number; present: number; late_days: number; late_minutes: number; early_days: number; early_minutes: number; absent: number; extra_minutes: number; overtime_minutes: number; unapproved_minutes: number; worked_minutes: number };

export type AttendanceSettings = { latitude: number | null; longitude: number | null; radius_m: number; require_selfie: boolean; late_grace: number; early_grace: number; extra_after: number; geofence: boolean; is_baseline: boolean; lock_version: number | null };

export type AttendanceMe = {
    employee: { id: string; number: string; name: string; department: string }; active: boolean; may_clock_in: boolean; may_clock_out: boolean;
    shift: (AttendanceEvaluation & { date: string; code: string; starts_at: string; ends_at: string; starts2_at: string | null; ends2_at: string | null; record: AttendanceRecord | null }) | null;
};

export type AttendanceOverview = {
    now: string; today: string; settings: AttendanceSettings; me: AttendanceMe | null; may: { manage: boolean }; departments: string[];
    day: { date: string; rows: AttendanceRow[] } | null; summary: { from: string; to: string; department: string | null; rows: AttendanceSummaryRow[] } | null;
};

export type OvertimeStatus = 'pending_approval' | 'approved' | 'rejected' | 'cancelled';

export type OvertimeRequest = {
    id: string; employee: { id: string; number: string; name: string; department: string }; work_date: string; minutes: number; reason: string; status: OvertimeStatus; approved_at: string | null; lock_version: number;
    approval: { id: string; status: string; consumed: boolean } | null; may_cancel: boolean;
};

export type OvertimeOverview = { requests: OvertimeRequest[] };

export type CorrectionStatus = 'pending_approval' | 'applied' | 'rejected' | 'cancelled';

export type AttendanceCorrection = {
    id: string; employee: { id: string; number: string; name: string; department: string }; work_date: string; status: CorrectionStatus; reason: string;
    old: { in_at: string | null; out_at: string | null }; new: { in_at: string | null; out_at: string | null }; applied_at: string | null; created_at: string; approval: { id: string; status: string; consumed: boolean } | null;
};

export type CorrectionOverview = { corrections: AttendanceCorrection[]; days_back: number };

export type LeaveKind = { id: string; code: string; name: string; deducts_balance: boolean; entitlement_days: number; eligible_after_months: number; evidence_after_days: number | null; paid: boolean; active: boolean; lock_version: number };

export type LeaveStatus = 'pending_approval' | 'approved' | 'rejected' | 'cancelled';

export type LeaveRequest = {
    id: string; employee: { id: string; number: string; name: string; department: string }; type: { code: string; name: string; deducts_balance: boolean }; from_date: string; to_date: string; days: number; reason: string; status: LeaveStatus;
    has_evidence: boolean; approval: { id: string; status: string; consumed: boolean } | null; may: { release: boolean; cancel: boolean };
};

export type LeaveBalanceItem = { type_id: string; code: string; name: string; eligible_on: string; entitlement: number; adjusted: number; taken: number; pending: number; remaining: number };

export type LeaveBalance = { employee: { id: string; number: string; name: string; department: string }; year: number; items: LeaveBalanceItem[] };

export type LeaveOverview = {
    year: number; years: number[]; today: string; types: LeaveKind[]; may: { manage: boolean };
    mine: { employee: { id: string; number: string; name: string; department: string }; active: boolean; balances: LeaveBalanceItem[]; requests: LeaveRequest[] } | null;
    requests: LeaveRequest[] | null; balances: LeaveBalance[] | null; employees: { id: string; number: string; name: string; department: string }[] | null;
    adjustments: { id: string; employee: { id: string; number: string; name: string }; type_code: string; days: number; reason: string; created_at: string }[] | null;
};
