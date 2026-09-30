# Phase 2 — Revenue and Inventory (Months 5–8)

PRD scope: F&B POS, Kitchen/KDS, minibar/room service, Inventory & Purchasing, Maintenance, Finance initial capability.

Order around transactional dependencies: item/UOM/warehouse and outlet masters → POS bill/payment/shift → KDS/recipe consumption → stock ledger/transfers/count → PR/PO/receiving → maintenance parts/assets → finance settlement/AP/reconciliation → reports/UAT.

Critical gate: no duplicate financial/stock posting under retry/offline scenarios; POS/stock/payment can be reconciled end-to-end.
