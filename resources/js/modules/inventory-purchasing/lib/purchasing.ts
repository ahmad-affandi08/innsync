import type { StatusTone } from '@/components/ui/status-badge'

export type ItemChoice = { id: string; code: string; name: string; base_unit: string; units: string[]; suggested_cost_minor?: Record<string, number | null> }

export type Standing = {
    policy: string; period: string; department: string; budget_minor: number | null; committed_minor: number; extra_minor: number; remaining_minor: number | null; over: boolean;
}

export type ApprovalSummary = {
    id: string; status: string; consumed: boolean;
    steps: { permission: string; approvals_required: number }[];
    decisions: { step: number; approver_id: string; decision: string; reason: string | null; decided_at: string }[];
}

export const REQUEST_TONE: Record<string, StatusTone> = { draft: 'neutral', pending_approval: 'pending', approved: 'success', rejected: 'danger', cancelled: 'neutral', ordered: 'info' }
export const REQUEST_STATUSES = ['draft', 'pending_approval', 'approved', 'rejected', 'cancelled', 'ordered'] as const
export const URGENCY_TONE: Record<string, StatusTone> = { low: 'neutral', normal: 'info', high: 'warning', urgent: 'danger' }
export const ORDER_TONE: Record<string, StatusTone> = {
    draft: 'neutral', pending_approval: 'pending', approved: 'info', issued: 'info', partially_received: 'warning', received: 'success', closed: 'neutral', cancelled: 'neutral',
}
export const ORDER_STATUSES = ['draft', 'pending_approval', 'approved', 'issued', 'partially_received', 'received', 'closed', 'cancelled'] as const

let sequence = 0

/** A stable key for a line a person is still editing, so removing one never re-uses another's inputs. */
export const nextLineKey = (): string => `line-${++sequence}`
