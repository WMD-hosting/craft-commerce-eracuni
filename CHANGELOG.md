# Release Notes for e-Računi for Commerce

## 1.0.0 - 2026-09-21

### Added
- Sales invoices on e-računi.hr for Craft Commerce orders: fiscal Retail invoices for consumers, standard invoices for businesses, with VAT treatment resolved from the billing country and tax ID (domestic, EU reverse charge, third country).
- KPD classification per line from a product field, per-product-type defaults, and a shipping KPD.
- Payment method mapping per gateway with fiscalisation flags and an automatic fallback chain for rejected methods.
- PDF download, payment record for paid orders, AS4 delivery for B2B and FINA delivery for B2G buyers, with a delivery status sync command.
- Idempotent, queue-driven sending with per-install serialisation, resume after partial failures, and an order-edit panel with Preview, Send and Retry.
- Settings tabs (Connection, Documents, VAT, Payments, KPD, Delivery), a documents index, console commands (`status`, `order`, `backfill`, `retry`, `delivery-status`) and a Twig variable.
