<?php
/**
 * Croatian translations for the "commerce-eracuni" category.
 *
 * Keep the key set identical to `translations/en/commerce-eracuni.php` —
 * every string reachable through `|t('commerce-eracuni')` /
 * `Craft::t('commerce-eracuni', …)` in src/ must have an entry here.
 * Placeholders such as `{id}` must be preserved verbatim.
 */

return [
    // General / nav / permissions
    'e-Računi' => 'e-Računi',
    'e-Računi for Commerce' => 'e-Računi za Commerce',
    'Documents' => 'Dokumenti',
    'Settings' => 'Postavke',
    'Send, preview and retry e-računi documents' => 'Slanje, pregled i ponavljanje e-računi dokumenata',

    // Controller notices
    'Queued for e-računi.' => 'Stavljeno u red za e-računi.',
    'Queued for retry.' => 'Ponovno stavljeno u red.',
    'Connected.' => 'Povezano.',

    // Queue jobs
    'Sending order {id} to e-računi' => 'Slanje narudžbe {id} na e-računi',
    'Syncing e-računi delivery status' => 'Osvježavanje statusa dostave e-računa',

    // Invoice line descriptions (Documents service)
    'Shipping' => 'Dostava',
    'Discount' => 'Popust',

    // Order panel (CP order edit sidebar)
    'Notes' => 'Napomene',
    'Not sent yet.' => 'Još nije poslano.',
    'Send to e-računi' => 'Pošalji na e-računi',
    'Preview payload' => 'Pregled podataka',
    'Status' => 'Status',
    'Number' => 'Broj',
    'Treatment' => 'Tretman',
    'Delivery' => 'Dostava e-računa',
    'Open PDF' => 'Otvori PDF',

    // Documents index
    'Order' => 'Narudžba',
    'Updated' => 'Ažurirano',
    'Retry' => 'Pokušaj ponovno',
    'No documents yet.' => 'Još nema dokumenata.',
    'e-Računi documents' => 'e-Računi dokumenti',
    'All' => 'Sve',

    // Settings: Connection pane
    'Test connection' => 'Testiraj vezu',
    'API URL' => 'API URL',
    'Username' => 'Korisničko ime',
    'The API user e-mail, e.g. INFO@EXAMPLE.HR.' => 'E-mail API korisnika, npr. INFO@EXAMPLE.HR.',
    'Auth token' => 'Token za autentifikaciju',
    'TOKEN_SECRETKEY from e-računi API settings. Store it in an environment variable.' => 'TOKEN_SECRETKEY iz postavki e-računi API-ja. Spremite ga u varijablu okruženja.',
    'Business unit' => 'Poslovna jedinica',
    'Poslovna jedinica code as configured in e-računi.' => 'Šifra poslovne jedinice kako je postavljena u e-računima.',
    'Cash register code' => 'Šifra blagajne',
    'Numeric naplatni uređaj code used for fiscalised invoices.' => 'Brojčana šifra naplatnog uređaja za fiskalizirane račune.',
    'Partner code prefix' => 'Prefiks šifre partnera',
    'Prefix for B2C partner codes, e.g. WEB → WEB-123.' => 'Prefiks za šifre B2C partnera, npr. WEB → WEB-123.',
    'Seller country' => 'Država prodavatelja',
    'ISO 3166-1 alpha-2. Only HR is validated in this version.' => 'ISO 3166-1 alpha-2. U ovoj verziji validiran je samo HR.',

    // Settings: Delivery pane
    'Deliver B2B invoices over AS4' => 'Dostavi B2B račune preko AS4',
    'Deliver B2G invoices over FINA' => 'Dostavi B2G račune preko FINA-e',
    'B2G buyers (OIB)' => 'B2G kupci (OIB)',
    'Orders whose billing OIB is listed here are treated as public-sector buyers and sent through FINA.' => 'Narudžbe čiji je OIB za naplatu naveden ovdje smatraju se kupcima iz javnog sektora i šalju se preko FINA-e.',
    'Run `craft commerce-eracuni/sync/delivery-status` from cron (every 15 minutes) to update pending deliveries.' => 'Pokrenite `craft commerce-eracuni/sync/delivery-status` iz crona (svakih 15 minuta) za ažuriranje dostava na čekanju.',

    // Settings: Documents pane
    'Send automatically' => 'Šalji automatski',
    'Trigger order statuses' => 'Statusi narudžbe koji pokreću slanje',
    'An order reaching one of these statuses is sent to e-računi.' => 'Narudžba koja dosegne jedan od ovih statusa šalje se na e-računi.',
    'Require paid' => 'Zahtijevaj plaćeno',
    'Only send once the order is fully paid.' => 'Šalji tek kad je narudžba u potpunosti plaćena.',
    'Invoice date' => 'Datum računa',
    'Date of sending' => 'Datum slanja',
    'Order date' => 'Datum narudžbe',
    'Due days' => 'Rok plaćanja (dana)',
    'Record payments' => 'Bilježi plaćanja',
    'Add a payment record for orders that are already paid.' => 'Dodaj zapis o plaćanju za narudžbe koje su već plaćene.',

    // Settings: KPD pane
    'KPD field handle' => 'Handle polja za KPD',
    'Plain Text field on variants or products holding the KPD code.' => 'Plain Text polje na varijantama ili proizvodima koje sadrži KPD šifru.',
    'Default KPD for this product type' => 'Zadani KPD za ovaj tip proizvoda',
    'Global default KPD' => 'Globalni zadani KPD',
    'Shipping KPD' => 'KPD za dostavu',
    '532000 (postal and courier activities) is a suggestion; confirm with your accountant.' => '532000 (poštanske i kurirske djelatnosti) je prijedlog; potvrdite s knjigovođom.',
    'Address phone field handle' => 'Handle polja telefona na adresi',
    'Optional custom field on addresses used as buyerPhone.' => 'Neobavezno prilagođeno polje na adresama koje se koristi kao buyerPhone.',

    // Settings: Payments pane
    'Fiscalised methods (cash, cards, online wallets) produce an F1 fiscal invoice; bank transfer does not. Zero-total orders use “Other”.' => 'Fiskalizirani načini plaćanja (gotovina, kartice, online novčanici) generiraju fiskalizirani F1 račun; virman ne. Narudžbe s ukupnim iznosom nula koriste „Ostalo”.',
    'Gateway' => 'Pristupnik plaćanja',
    'Method' => 'Metoda',
    'Fiscalised' => 'Fiskalizirano',
    'Payment record method' => 'Metoda zapisa plaćanja',
    'Suggested, not yet saved' => 'Predloženo, još nije spremljeno',

    // Settings: VAT pane
    'Known VAT rates' => 'Poznate stope PDV-a',
    'Comma-separated percentages. Line rates derived from Commerce tax adjustments snap to the nearest.' => 'Postoci odvojeni zarezom. Stope stavki izvedene iz Commerce poreznih prilagodbi zaokružuju se na najbližu.',
    'Tax rate map' => 'Mapa poreznih stopa',
    'Commerce tax category handle to VAT percentage. Used only for lines Commerce reports no tax for.' => 'Handle Commerce porezne kategorije u postotak PDV-a. Koristi se samo za stavke za koje Commerce ne prijavljuje porez.',
    'Tax category handle' => 'Handle porezne kategorije',
    'Rate (%)' => 'Stopa (%)',
    'Default VAT rate' => 'Zadana stopa PDV-a',
    'Percentage used when a line has no Commerce tax and its tax category is not listed above.' => 'Postotak koji se koristi kad stavka nema Commerce porez, a njezina porezna kategorija nije navedena iznad.',
    'Treatment is decided per order from the billing country, organization and tax ID: domestic, EU B2B (reverse charge, type 16), EU B2C, third-country B2B (17) and third-country B2C (3).' => 'Tretman se određuje po narudžbi na temelju države za naplatu, organizacije i poreznog broja: domaći, EU B2B (prijenos porezne obveze, tip 16), EU B2C, B2B treće zemlje (17) i B2C treće zemlje (3).',

    // Settings tabs
    'Connection' => 'Veza',
    'VAT' => 'PDV',
    'Payments' => 'Plaćanja',
    'KPD' => 'KPD',
];
