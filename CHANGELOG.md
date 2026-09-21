# Changelog

All notable changes to this project will be documented in this file.

## 1.0.0 - Unreleased

### Added

- Order → e-računi invoice pipeline: builds an invoice from a Commerce order (buyer, line items,
  VAT treatment, payment method, KPD codes) and sends it through the e-računi REST API.
- Automatic fiscalisation (F1) for cash-like payment methods, decided per gateway via a
  configurable payment-method mapping.
- Per-order VAT treatment (domestic, EU B2B reverse charge, EU B2C, third-country B2B/B2C) derived
  from the buyer's billing country, organisation, and tax ID.
- KPD code resolution from a variant/product field, with per-product-type and global defaults, and
  a separate shipping KPD.
- AS4 (B2B) and FINA (B2G) e-invoice delivery, with a delivery-status sync job/console command.
- Control panel: order edit sidebar panel (send/preview), documents index with retry, a settings
  page (Connection, Documents, VAT, Payments, KPD, Delivery tabs), and a test-connection action.
- `craft commerce-eracuni/sync/*` console commands: `status`, `order`, `backfill`, `retry`,
  `delivery-status`.
- `craft.commerceEracuni.documentForOrder()` Twig variable.
- English and Croatian translations for all control panel and Twig-facing strings.
