# Testing and UAT Task Plan

Use the PRD testing layers as release work, not post-development cleanup.

## UAT scenario families

- Front Office: reservation → arrival → check-in → room move/stay-over → folio split/transfer → payment/deposit/refund → checkout → night audit.
- Housekeeping/Laundry: departure room → cleaning → inspection → ready; laundry pickup → item verification → processing → folio charge → delivery.
- F&B/Kitchen/Inventory: order → KDS → recipe consumption → payment/room charge → cashier close → stock reconciliation.
- Purchasing/Finance: PR → approval → PO → receipt → stock/AP → payment → reporting.
- Maintenance: report → assignment → OOO/OOS impact → parts use → completion evidence → room sellability restore.
- HR: roster → attendance → exception → payroll basis → service-charge simulation/approval.
- Guest: session → self check-in/order/service request/payment → expiry/security negative cases.

## NFR gates

Offline/sync, duplicate retry, concurrency, security/IDOR, performance, backup/restore, provider outage/unknown payment, scheduled jobs, and shared-hosting deployment all receive evidence.
