Fixtures are anonymised request/response pairs recorded from mojwmd's production
e-računi integration (WMD obrt account, 3000+ invoices). They are the oracle:
the plugin's builders must reproduce `sent` for an equivalent order.
Regenerate with tests/tools/extract-fixtures.php; never edit `sent` payload
semantics by hand.

## Coverage

- `invoices/b2b-hr.json` — B2B, Croatian buyer, ok.
- `invoices/b2b-hr-error.json` — same shape, e-računi.hr rejected it (missing VAT ID).
- `invoices/b2c-retail-banktransfer.json` — Retail (fiscalised), BankTransfer, ok.
- `invoices/b2c-retail-paypal.json` — Retail (fiscalised), PayPal, ok.
- `partners/hr-oib.json` — partner with Croatian OIB (`personalID`), ok.
- `partners/hr-oib-error.json` — same shape, e-računi.hr rejected it (rate limited).
- `partners/b2c-no-id.json` — partner with no tax ID, ok.
- `partners/b2c-no-id-error.json` — same shape, e-računi.hr rejected it (missing address).

## What the parity test pins (and what it does not)

`InvoiceBuilderParityTest` reconstructs an order from a recorded `sent` payload and requires the
builder to emit the same payload. Because the fixture is both the input and the oracle, it pins
only the parts the reconstruction does not hand back:

**Pinned**

- The **key set** of the payload and of every item, after the volatile keys listed in the test
  (`VOLATILE_ROOT` / `VOLATILE_ITEM`: dates, reference, business unit, ids, buyer address fields,
  descriptions, and mojwmd's internal `_fallbackMethods` bookkeeping key) are removed. A key the
  plugin adds or drops fails the test.
- The **Retail vs. B2B shape**: `type`/`cashRegisterCode`/`buyer*` for Retail against
  `partnerID`/`buyerCode` for business treatments, and which of the two the chosen treatment
  produces.
- **Rate placement**: `price` + `vatPercentage` on Retail items against `netPrice` + `vatRate` on
  business items, and the presence or absence of `vatTransactionType`.
- Value-level agreement on everything that survives normalisation: quantities, units,
  `currencyCode`, `methodOfPayment`, `classificationCode`/`classificationOfProductsByActivity`.

**Not pinned**

- **Rate derivation.** Rates are read out of the fixture and fed straight back into the snapshot.
  The snapping maths itself is covered by `VatResolverTest`; `OrderSnapshotFactory`'s mapping of
  Commerce adjustments onto it (the shipping-rate rule, the `taxRateMap` fallback) needs a Craft
  test harness and has no unit coverage yet.
- **Buyer fields.** Every buyer address key is volatile, so treatment *inputs* (country,
  organization, tax ID) are supplied by the provider row rather than checked against the fixture;
  only the resulting shape is compared. `VatResolverTest` covers the treatment decision itself.
- **Payment-method mapping and the Retail fallback walk.** The provider hands the builder a
  payment map that already yields the fixture's `methodOfPayment`; the mapping rules live in
  `PaymentMethodMapTest` and the fallback walk in `InvoiceCreatorTest`.
- **Shipping and discount lines, totals tolerance, warnings.** No fixture carries them.

Rows whose fixture is not recorded are reported as incomplete tests, never filtered away.

## Known gaps

Not present in the source log and **not fabricated**, per the extraction brief:

- `invoices/b2c-retail-visa.json` — the mojwmd integration never sends a card-branded
  `methodOfPayment` (only `BankTransfer` and `PayPal` occur in the whole log, local
  and production copies both checked); `Visa` only ever appears inside a retail
  invoice's `_fallbackMethods` list, never as the chosen method.
- `invoices/b2b-eu-reverse-charge.json` and `invoices/b2c-third-country.json` — both
  depend on `Items[].vatTransactionType`, which never appears anywhere in the log.

If a later task needs these shapes, they must be sourced from a different WMD
account's e-računi log (or hand-built against the e-računi.hr API docs and marked
clearly as synthetic, not extracted).

## Extractor adaptation

The brief's `extract-fixtures.php` blocks() loop only pairs a `DATA SENT` block with
a following `RESPONSE FROM E-RAČUNI.HR ... END RESPONSE` block. In production, an
API error is instead logged as a single line (e.g. `Eracuni REST exception for
invoice 74218: ... HTTP 500: {"response": {"status": "error", ...}}`) with no such
block, so the literal brief script yields zero `*-error.json` fixtures. The script
adds `findInlineErrorResponse()`, which looks a short distance past a `sent`
block's end for that inline JSON tail (stopping at the next `DATA SENT` header so
an error can't be misattributed to a later, unrelated request) and uses it as the
response when no block-style response follows.

## Manual sanitisation beyond the automated anonymiser

The anonymiser's key/description rules missed a few real values, edited by hand
after generation (see task-2-report.md for the full list): a customer's `.dk`
domain inside an invoice line `description` (regex only covered `hr|com|eu|net`),
a real OIB copied into a partner response's `taxID` (the map only rewrites
`personalID`, not `taxID`), three partners' real street/city/postal code
(`Addresses[].street/city/postalCode` — at generation time the map only rewrote
`buyerStreet`/`PrimaryAddress_street`, not the nested `Addresses[]` shape; the
map now also covers `street`/`city`/`postalCode` for future runs, see "Fix
round 1" below), one WMD server IP inside an error `description` string, and
(fix round 1) two real `buyerPostalCode` values on the retail invoices that
survived the first pass.

Postal codes are redacted the same way as every other address field: retail
invoices' `buyerPostalCode` and partner payloads' `Addresses[].postalCode` are
both rewritten to the placeholder `10000` (Zagreb's own code, matching the
placeholder `city` of "Zagreb"), never left as the real value.

The extractor's `anonymise()` map/regex is best-effort, not a guarantee — after
every regeneration, re-read every fixture by eye per this section before
committing.
