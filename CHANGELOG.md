# Release Notes for e-Računi for Commerce

## 1.0.2 - 2026-10-23

### Fixed
- An invoice whose create may have been booked in e-računi is no longer sent again by Retry, the order panel, `sync/retry`, `sync/order` or auto-send. That covers a create that broke off without an answer, an answer that is neither an explicit error nor an `ok` with a document ID, and a worker that died mid-create (the row is flagged before the request leaves). Staff resend only after checking e-računi, with **Verified not booked, send again** or `sync/order <id> --verified-attempt=<n>`; the confirmation applies to the attempt they checked and no later one.
- On update, rows that failed this way before are flagged from their error text, and so are failed rows with no document ID that were tried more than once (1.0.1 re-sent them, and a later error could replace the text) and pending rows with no document ID left over from a worker that died. Check those in e-računi before sending them again.
- The order panel's buttons were forms nested in Commerce's order form, so saving an order could post the panel's send action instead. The panel now sends with JavaScript and has no form fields of its own.
- `sync/backfill` no longer queues every completed order when none of the trigger statuses exists. It resolves the statuses in every store to ids first, warns about missing ones, and queues nothing when none resolve.

### Changed
- `Documents::queue()`, `send()` and `claim()` take an optional `?int $verifiedAttempts` third argument (the staff override); code that overrides `queue()` must add it to its signature.

### Added
- `Documents::isOutcomeUnknown()`, `Documents::triggerStatusIds()`, `Document::$outcomeUnknown` and `Document::$canVerifyNotBooked`.
- `sync/status` reports outcome-unknown documents and trigger statuses missing from Commerce.

## 1.0.1 - 2026-09-21

### Fixed
- Settings page footer cleanup.

## 1.0.0 - 2026-09-21

### Added
- Sales invoices on e-računi.hr for Craft Commerce orders: fiscal Retail invoices for consumers, standard invoices for businesses, with VAT treatment resolved from the billing country and tax ID (domestic, EU reverse charge, third country).
- KPD classification per line from a product field, per-product-type defaults, and a shipping KPD.
- Payment method mapping per gateway with fiscalisation flags and an automatic fallback chain for rejected methods.
- PDF download, payment record for paid orders, AS4 delivery for B2B and FINA delivery for B2G buyers, with a delivery status sync command.
- Idempotent, queue-driven sending with per-install serialisation, resume after partial failures, and an order-edit panel with Preview, Send and Retry.
- Settings tabs (Connection, Documents, VAT, Payments, KPD, Delivery), a documents index, console commands (`status`, `order`, `backfill`, `retry`, `delivery-status`) and a Twig variable.
