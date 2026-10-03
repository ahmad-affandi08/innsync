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
