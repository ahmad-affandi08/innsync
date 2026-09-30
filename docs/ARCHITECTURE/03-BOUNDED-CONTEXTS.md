# Bounded Context Map

| Context | Owns | Notes |
| --- | --- | --- |
| Identity & Access | users, roles, permission scopes, sessions, approvals | Supporting context; property/outlet/department scope |
| Property Configuration | property, departments, outlets, rooms, room types, rate plans, tax/service-charge config | Effective-dated sensitive configuration |
| Front Office | availability, reservations, stays, guest profile reference, folio orchestration, night audit | Core hotel context |
| Housekeeping | room servicing, assignment, inspection, checklist, lost & found, amenity/linen operational movement | Owns housekeeping state dimension |
| Laundry | guest laundry workflow and laundry-side linen processing | Charge generation coordinated with Front Office |
| F&B Sales | POS bills, cashier shifts, room charge, minibar, room service | Financial posting + offline critical |
| Kitchen | KDS tickets, recipes, production/waste, availability | Emits inventory consumption facts |
| Inventory & Purchasing | item/UOM, warehouses, stock ledger, stock count, PR/PO/receipt/return | Stock ledger is authoritative stock truth |
| Maintenance | work orders, assets, preventive maintenance, room OOO/OOS maintenance facts | Front Office consumes sellability effect |
| Human Resource | employee, roster, attendance, leave, performance, payroll basis, service-charge allocation input | HR calculations must remain traceable |
| Finance | revenue/cost postings, AR/AP, settlements, tax/service-charge liabilities, management P&L | Operational finance, not statutory GL unless CR adds it |
| Reporting & Dashboard | projections, KPI/read models, scheduled reports | Read-only from source contexts |
| Guest Experience | self check-in, QR menu/session, service request, guest bill view | Public attack surface; strong session scoping |

## Context interaction

- Commands go to the context that owns the aggregate.
- Other contexts consume published facts/events or explicit application contracts.
- No context may update another context's tables directly.
- Dashboard/Reporting is never the system of record.
