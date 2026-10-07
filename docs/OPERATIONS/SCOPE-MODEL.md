# Outlet and department scope (NFR-06, FR-DSH-022, FR-RPT-002)

This note records how the product reads a grant that is limited to an **outlet** or a **department**, so that the same reading is used by the dashboard and the reports. It follows the PRD: NFR-06 ("access control down to the feature and to the scope property/outlet/department"), section 3 ("the heads of department, with data limited to their department"), FR-DSH-022 ("the dashboard applies the scope by property, outlet, department and role; the person sees only the numbers that are allowed, without changing the source data") and the entity list ("Outlet: name, kind, tax rate, printer configuration; it hosts the Menu and the Bills").

It is a decision of the implementation, taken on 2026-10-07 on the owner's instruction, and it can be changed by a Change Request.

## What a scope is

- A role assignment already carries `scope_type` (`property`, `outlet` or `department`) and a `scope_id` that is a ULID (`user_role_assignments`, `AccessScope`). A grant at property scope covers everything; a grant at an outlet or a department covers only that resource.
- **Outlet** means an F&B outlet, the record of `fnb_outlets` that hosts a menu and the bills. Its `scope_id` is that record's id. The revenue of an outlet is what was posted with the source `pos_<outlet code in lower case>`.
- **Department** is one of the fixed list that finance, inventory and human resource already use (`front_office`, `housekeeping`, `laundry`, `fnb`, `kitchen`, `maintenance`, `hr`, `finance`, `purchasing`, `general`). A department is a code, not a record, so its `scope_id` is derived from the property and the code (`DepartmentScope::idFor`): stable, a valid ULID, and different in each property.

## What the dashboard does with it

A person sees a card when the permission it needs is granted at property scope (the whole card) or at a scope the card belongs to (the card limited to that scope). Nothing is computed from data the person may not see, and nothing is changed.

| Card | Permission | Property scope | Department scope | Outlet scope |
| --- | --- | --- | --- | --- |
| occupancy, movements, activity, arrivals | dashboard view | whole | only `front_office` | not shown |
| revenue | revenue view | whole | the buckets the department owns: rooms for `front_office`, laundry for `laundry`, the outlets' sales for `fnb`, the rest for `general` | the sales of that outlet only |
| outlet hours | dashboard view | whole | `fnb` shows every outlet | that outlet only |
| staff on duty | an HR permission | whole | the groups, leave and absences of that department | not shown |
| low stock | an inventory permission | whole | the departments of the scope | not shown |
| maintenance | a maintenance permission | whole | only `maintenance` | not shown |
| supplier spend, products | finance payables, revenue | whole | not shown | not shown |

The alerts are filtered the same way: each alert belongs to a department (the table in `DashboardService::ALERT_DEPARTMENT`), and an alert that belongs to none (a synchronisation failure) is for property scope only.

The response says which scope each card was limited to, so the screen can say so.

## What the reports do with it

A report takes optional filters for the date range, the outlet, the department and the person, as the PRD asks (FR-RPT-002). A filter is offered only where the report has that dimension (`ReportService::FILTERS`), and a filter a report does not take, or a value that is not of the property, is refused, not ignored. The filters narrow what a person who may see the whole report sees; they never widen it. The department of a sale is read as the dashboard reads it (the table above), and an outlet is an F&B outlet. A report is for those who hold its permission at property scope: the reading of a limited grant is done by the dashboard only.

## What is not done

- There is no screen to assign a role at an outlet or a department scope: roles are master data that the owner loads (PRD 24.1) and the identity module has no administration screen yet. The reading described here is tested by granting such roles directly.
- The department of a card that mixes departments (supplier spend, products) is not split.
