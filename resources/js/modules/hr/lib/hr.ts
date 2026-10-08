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

export type AttendanceSettings = { latitude: number | null; longitude: number | null; radius_m: number; require_selfie: boolean; late_grace: number; early_grace: number; extra_after: number; face_mode: 'off' | 'flag' | 'require'; geofence: boolean; is_baseline: boolean; lock_version: number | null };

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

export type PerformanceRow = {
    employee: { id: string; number: string; name: string; department: string; position: string };
    scheduled: number; present: number; late_days: number; late_minutes: number; absent: number; punctuality: number | null; attendance: number | null;
    overtime_minutes: number; unapproved_minutes: number; sop_items: number; sop_by: Record<string, number>; complaints: { total: number; serious: number; resolved: number }; linked: boolean;
};

export type PerformanceSource = { source: string; runs: number; complete: number; items: number; done: number; percent: number };

export type PerformanceOverview = { from: string; to: string; department: string | null; departments: string[]; board: PerformanceRow[]; sources: PerformanceSource[] };

export type PayKind = 'basic' | 'fixed_allowance' | 'variable_allowance' | 'meal' | 'transport';

export type PayComponent = { id: string; code: string; name: string; kind: PayKind; basis: 'monthly' | 'attendance' | 'per_day'; taxable: boolean; social_base: boolean; active: boolean; lock_version: number };

export type PayProfile = { ptkp_status: string; has_npwp: boolean; in_health: boolean; in_employment: boolean; lock_version: number | null };

export type PayPerson = {
    id: string; number: string; name: string; department: string; position: string; pay: Record<string, { amount_minor: number; effective_from: string }>; monthly_minor: number; per_day_minor: number; profile: PayProfile | null;
};

export type PayrollSettings = {
    health_employee_bp: number; health_employer_bp: number; health_cap_minor: number; jht_employee_bp: number; jht_employer_bp: number; jp_employee_bp: number; jp_employer_bp: number; jp_cap_minor: number;
    jkk_employer_bp: number; jkm_employer_bp: number; job_cost_bp: number; job_cost_cap_year_minor: number; no_npwp_surcharge_bp: number; ptkp: Record<string, number>; brackets: { upto_minor: number | null; rate_bp: number }[];
    overtime_divisor: number; overtime_first_x100: number; overtime_next_x100: number; absence_divisor: number; late_minute_deduction_minor: number; is_baseline: boolean; lock_version: number | null;
};

export type PayrollOverview = {
    currency: string; today: string; earliest: string; components: PayComponent[]; employees: PayPerson[]; statuses: string[]; settings: PayrollSettings; selected: string | null;
    history: { component_id: string; amount_minor: number; effective_from: string; reason: string }[];
};

export type PayrollRunStatus = 'draft' | 'calculated' | 'reviewed' | 'approved' | 'paid' | 'locked';

export type PayrollLine = {
    id: string; employee: { id: string; number: string; name: string; department: string; position: string }; ptkp_status: string; scheduled_days: number; present_days: number; absent_days: number; unpaid_leave_days: number; late_minutes: number; overtime_minutes: number;
    gross_minor: number; tax_minor: number; employee_social_minor: number; employer_social_minor: number; other_deductions_minor: number; net_minor: number;
    items: { code: string; label: string; kind: 'earning' | 'deduction' | 'employer'; amount_minor: number }[]; warnings: string[];
};

export type PayrollRun = {
    id: string; number: string; period: string; status: PayrollRunStatus; employees: number; gross_minor: number; deductions_minor: number; net_minor: number; tax_minor: number; employee_social_minor: number; employer_social_minor: number; revision: number; lock_version: number;
    approval: { id: string; status: string; consumed: boolean } | null; paid_reference: string | null; may: { calculate: boolean; review: boolean; approve: boolean; reopen: boolean; discard: boolean; lock: boolean };
};

export type PayrollAdjustment = { id: string; employee: { id: string; number: string; name: string }; amount_minor: number; taxable: boolean; label: string; reason: string; source_period: string | null; status: 'open' | 'applied' | 'cancelled'; lock_version: number };

export type PayrollRunOverview = {
    currency: string; today: string; period: string; runs: PayrollRun[]; run: (PayrollRun & { lines: PayrollLine[] }) | null; adjustments: PayrollAdjustment[]; without_pay: { id: string; number: string; name: string }[]; employees: { id: string; number: string; name: string }[];
};

export type ServiceChargeSettings = { staff_share_bp: number; reserve_bp: number; default_points_x100: number; points: { position: string; points_x100: number }[]; is_baseline: boolean; lock_version: number | null };

export type ServiceChargeLine = { id: string; employee: { id: string; number: string; name: string; position: string }; points_x100: number; scheduled_days: number; present_days: number; attendance_bp: number; share_minor: number; default_points: boolean };

export type ServiceChargeDistribution = {
    id: string; number: string; period: string; status: 'draft' | 'approved'; days_booked: number; collected_minor: number; pool_minor: number; reserve_minor: number; distributed_minor: number; residue_minor: number; staff_share_bp: number; reserve_bp: number;
    sources: { outlet: string; minor: number }[]; lock_version: number; month_over: boolean; approval: { id: string; status: string; consumed: boolean } | null; may: { calculate: boolean; approve: boolean; discard: boolean };
};

export type ServiceChargeOverview = {
    currency: string; today: string; period: string; settings: ServiceChargeSettings; unlisted_positions: string[]; distributions: ServiceChargeDistribution[]; selected: (ServiceChargeDistribution & { lines: ServiceChargeLine[] }) | null;
};

export type SwapStatus = 'awaiting_partner' | 'awaiting_supervisor' | 'approved' | 'rejected' | 'declined' | 'cancelled';

export type ShiftSwap = {
    id: string; date: string; status: SwapStatus; reason: string; decision_note: string | null; lock_version: number;
    requester: { id: string; number: string; name: string; shift: string }; partner: { id: string; number: string; name: string; shift: string }; may: { respond: boolean; decide: boolean; cancel: boolean };
};

export type SwapOverview = { today: string; linked: boolean; me: string | null; may: { decide: boolean }; colleagues: { id: string; number: string; name: string }[]; mine: ShiftSwap[]; to_decide: ShiftSwap[] };

export type PortalOverview = {
    linked: boolean; today: string; currency?: string; employee?: { id: string; number: string; name: string; department: string; position: string; joined_on: string };
    schedule?: { date: string; code: string; is_off: boolean; starts_at: string | null; ends_at: string | null; starts2_at: string | null; ends2_at: string | null }[];
    attendance?: { date: string; code: string; status: AttendanceStatus; in_at: string | null; out_at: string | null; late_minutes: number; early_minutes: number; overtime_minutes: number }[];
    leave?: { year: number; balances: LeaveBalanceItem[]; pending: number };
    payslips?: { run_id: string; period: string; number: string; gross_minor: number; net_minor: number }[];
    swaps?: { open: number; to_answer: number; to_decide: number };
    announcements?: { unread: number; to_confirm: number };
    conduct?: { active_warnings: number };
};

export type ConductKind = 'verbal' | 'sp1' | 'sp2' | 'sp3' | 'award';

export type ConductRecord = {
    id: string; employee: { id: string; number: string; name: string; department: string }; kind: ConductKind; issued_on: string; valid_until: string | null; state: 'active' | 'expired' | 'award' | 'revoked'; reason: string;
    has_letter: boolean; revoke_reason: string | null; lock_version: number; may: { revoke: boolean };
};

export type ConductOverview = {
    today: string; kinds: ConductKind[]; may: { manage: boolean }; linked: boolean; mine: ConductRecord[]; records: ConductRecord[] | null; employees: { id: string; number: string; name: string; department: string }[] | null;
    default_months: Record<string, number>; selected: string | null;
};

export type Announcement = {
    id: string; kind: 'announcement' | 'policy'; title: string; body: string; audience: string; requires_ack: boolean; expires_on: string | null; has_document: boolean; status: 'published' | 'withdrawn'; published_at: string; lock_version: number;
};

export type AnnouncementOverview = {
    today: string; linked: boolean; may: { manage: boolean }; kinds: string[]; departments: string[];
    feed: (Announcement & { read: boolean; acknowledged: boolean })[];
    board: (Announcement & { audience_size: number; read: number; acknowledged: number; pending: { id: string; number: string; name: string }[]; withdraw_reason: string | null })[] | null;
};

export type AppraisalStatus = 'draft' | 'signed_appraiser' | 'completed' | 'cancelled';

export type AppraisalForm = { id: string; name: string; criteria: { key: string; label: string; weight: number }[]; active: boolean; lock_version: number };

export type Appraisal = {
    id: string; number: string; employee: { id: string; number: string; name: string; department: string; position: string }; form_name: string; criteria: { key: string; label: string; weight: number }[]; period_label: string; period_start: string; period_end: string; status: AppraisalStatus;
    scores: Record<string, number>; comment: string | null; overall_x100: number | null; rating: string | null; metrics: { scheduled: number; present: number; late_days: number; late_minutes: number; absent: number; punctuality: number | null; attendance: number | null; overtime_minutes: number; sop_items: number; sop_by: Record<string, number>; complaints: { total: number; serious: number; resolved: number } } | null;
    appraiser_signed_at: string | null; appraiser_hash: string | null; employee_agrees: boolean | null; employee_comment: string | null; employee_signed_at: string | null; employee_hash: string | null; cancel_reason: string | null; lock_version: number;
    may: { edit: boolean; sign_appraiser: boolean; sign_employee: boolean; cancel: boolean };
};

export type AppraisalOverview = {
    today: string; linked: boolean; may: { manage: boolean; appraise: boolean }; mine: Appraisal[]; team: Appraisal[]; forms: AppraisalForm[]; employees: { id: string; number: string; name: string; department: string }[];
};
