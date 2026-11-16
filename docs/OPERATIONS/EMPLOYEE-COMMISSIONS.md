# Commissions of employees

Pages `/hr/commissions` (the manager: kinds, entries, reversal) and `/hr/my-commissions` (every employee: their own, since they began). Right `hr.commission.manage` (migration 161 creates it and gives it to the roles Administrator, General Manager, Resort Manager and HR Manager). Tables `hr_commission_types` and `hr_commission_entries`.

This is not the commission paid to travel agents and booking channels (`docs/OPERATIONS/COMMISSIONS.md`). The spa therapist's commission on a treatment is worked out by the spa (`docs/OPERATIONS/SPA.md`) and copied here automatically (below).

## How it works

1. **Kinds.** The manager adds the kinds of commission the hotel pays (a code of 2 to 20 letters or digits, and a name): sales, referral, upselling, whatever the hotel decides. A kind can be stopped and used again; a stopped kind takes no new entries but keeps its history.
2. **Entries.** The manager records what a person earned: the employee, the kind, the day it was earned (today or before), an amount above zero, and an optional note.
3. **Mistakes.** An entry is never changed or deleted (database trigger). A wrong one is **reversed**, with a reason, by an entry of the opposite amount dated today that points back to it; an entry can be reversed once, and a reversal cannot be reversed.
4. **The employee's page.** Every employee whose account is linked to their employee record sees the **total since the beginning**, the total by kind and the list of entries, reversals included. They see only their own.
5. Every step is in the audit trail (`commission_type.created`, `commission_type.updated`, `commission.recorded`, `commission.reversed`).

The spa therapist's phone page also shows the commission **since the beginning**, next to the commission of today.

## What is recorded by itself

When a spa treatment is finished, the spa tells Human Resource (event `frontoffice.spa.treatment_done`) and the commission the spa fixed is put on the therapist's own record under the kind **SPA** (made the first time), once per booking (`source_ref` is unique), entered by the Automation account. Nothing is worked out again. A therapist whose sign-in account is not linked to an employee record, or a treatment with no commission, adds nothing here; the spa report still counts it.

## Not decided here

- **A rate or a rule that works the amount out** (a percentage of sales, a tier): the manager types the amount. A rule belongs to the hotel's policy and is not guessed here.
- **Paying the commission out or adding it to the payslip**: it is reported; payroll takes it by hand.
