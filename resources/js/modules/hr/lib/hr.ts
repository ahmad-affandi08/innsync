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
    patterns: ShiftPattern[]; minimums: { department: string; pattern_id: string; minimum: number }[];
    shortages: { date: string; department: string; pattern_id: string; code: string; have: number; need: number }[];
    departments: string[]; may: { view: boolean; roster: boolean };
};
