<?php
/**
 * English source-language translations for the "commerce-eracuni" category.
 *
 * Craft's `|t('commerce-eracuni')` / `Craft::t('commerce-eracuni', …)` calls
 * resolve against this file for the `en` site. Every key maps to itself
 * since the source strings are already English — except a handful of
 * Croatian domain terms (e.g. "e-Računi") that are intentionally left
 * untranslated; see AGENTS.md: "content/handles may be Croatian — don't
 * 'fix' them".
 */

return [
    // General / nav / permissions
    'e-Računi' => 'e-Računi',
    'e-Računi for Commerce' => 'e-Računi for Commerce',
    'Documents' => 'Documents',
    'Settings' => 'Settings',
    'Send, preview and retry e-računi documents' => 'Send, preview and retry e-računi documents',

    // Controller notices
    'Queued for e-računi.' => 'Queued for e-računi.',
    'Queued for retry.' => 'Queued for retry.',
    'Connected.' => 'Connected.',

    // Queue jobs
    'Sending order {id} to e-računi' => 'Sending order {id} to e-računi',
    'Syncing e-računi delivery status' => 'Syncing e-računi delivery status',

    // Invoice line descriptions (Documents service)
    'Shipping' => 'Shipping',
    'Discount' => 'Discount',

    // Order panel (CP order edit sidebar)
    'Notes' => 'Notes',
    'Not sent yet.' => 'Not sent yet.',
    'Send to e-računi' => 'Send to e-računi',
    'Preview payload' => 'Preview payload',
    'Status' => 'Status',
    'Number' => 'Number',
    'Treatment' => 'Treatment',
    'Delivery' => 'Delivery',
    'Open PDF' => 'Open PDF',

    // Documents index
    'Order' => 'Order',
    'Updated' => 'Updated',
    'Retry' => 'Retry',
    'No documents yet.' => 'No documents yet.',
    'e-Računi documents' => 'e-Računi documents',
    'All' => 'All',
    'Sent' => 'Sent',
    'Failed' => 'Failed',
    'Pending' => 'Pending',

    // Settings: Connection pane
    'Test connection' => 'Test connection',
    'API URL' => 'API URL',
    'Username' => 'Username',
    'The API user e-mail, e.g. INFO@EXAMPLE.HR.' => 'The API user e-mail, e.g. INFO@EXAMPLE.HR.',
    'Auth token' => 'Auth token',
    'TOKEN_SECRETKEY from e-računi API settings. Store it in an environment variable.' => 'TOKEN_SECRETKEY from e-računi API settings. Store it in an environment variable.',
    'Business unit' => 'Business unit',
    'Poslovna jedinica code as configured in e-računi.' => 'Poslovna jedinica code as configured in e-računi.',
    'Cash register code' => 'Cash register code',
    'Numeric naplatni uređaj code used for fiscalised invoices.' => 'Numeric naplatni uređaj code used for fiscalised invoices.',
    'Partner code prefix' => 'Partner code prefix',
    'Prefix for B2C partner codes, e.g. WEB → WEB-123.' => 'Prefix for B2C partner codes, e.g. WEB → WEB-123.',
    'Seller country' => 'Seller country',
    'ISO 3166-1 alpha-2. Only HR is validated in this version.' => 'ISO 3166-1 alpha-2. Only HR is validated in this version.',

    // Settings: Delivery pane
    'Deliver B2B invoices over AS4' => 'Deliver B2B invoices over AS4',
    'Deliver B2G invoices over FINA' => 'Deliver B2G invoices over FINA',
    'B2G buyers (OIB)' => 'B2G buyers (OIB)',
    'Orders whose billing OIB is listed here are treated as public-sector buyers and sent through FINA.' => 'Orders whose billing OIB is listed here are treated as public-sector buyers and sent through FINA.',
    'Run `craft commerce-eracuni/sync/delivery-status` from cron (every 15 minutes) to update pending deliveries.' => 'Run `craft commerce-eracuni/sync/delivery-status` from cron (every 15 minutes) to update pending deliveries.',

    // Settings: Documents pane
    'Send automatically' => 'Send automatically',
    'Trigger order statuses' => 'Trigger order statuses',
    'An order reaching one of these statuses is sent to e-računi.' => 'An order reaching one of these statuses is sent to e-računi.',
    'Require paid' => 'Require paid',
    'Only send once the order is fully paid.' => 'Only send once the order is fully paid.',
    'Invoice date' => 'Invoice date',
    'Date of sending' => 'Date of sending',
    'Order date' => 'Order date',
    'Due days' => 'Due days',
    'Record payments' => 'Record payments',
    'Add a payment record for orders that are already paid.' => 'Add a payment record for orders that are already paid.',

    // Settings: KPD pane
    'KPD field handle' => 'KPD field handle',
    'Plain Text field on variants or products holding the KPD code.' => 'Plain Text field on variants or products holding the KPD code.',
    'Default KPD for this product type' => 'Default KPD for this product type',
    'Global default KPD' => 'Global default KPD',
    'Shipping KPD' => 'Shipping KPD',
    '532000 (postal and courier activities) is a suggestion; confirm with your accountant.' => '532000 (postal and courier activities) is a suggestion; confirm with your accountant.',
    'Address phone field handle' => 'Address phone field handle',
    'Optional custom field on addresses used as buyerPhone.' => 'Optional custom field on addresses used as buyerPhone.',

    // Settings: Payments pane
    'Fiscalised methods (cash, cards, online wallets) produce an F1 fiscal invoice; bank transfer does not. Zero-total orders use “Other”.' => 'Fiscalised methods (cash, cards, online wallets) produce an F1 fiscal invoice; bank transfer does not. Zero-total orders use “Other”.',
    'Gateway' => 'Gateway',
    'Method' => 'Method',
    'Fiscalised' => 'Fiscalised',
    'Payment record method' => 'Payment record method',
    'Suggested, not yet saved' => 'Suggested, not yet saved',

    // Settings: VAT pane
    'Known VAT rates' => 'Known VAT rates',
    'Comma-separated percentages. Line rates derived from Commerce tax adjustments snap to the nearest.' => 'Comma-separated percentages. Line rates derived from Commerce tax adjustments snap to the nearest.',
    'Tax rate map' => 'Tax rate map',
    'Commerce tax category handle to VAT percentage. Used only for lines Commerce reports no tax for.' => 'Commerce tax category handle to VAT percentage. Used only for lines Commerce reports no tax for.',
    'Tax category handle' => 'Tax category handle',
    'Rate (%)' => 'Rate (%)',
    'Default VAT rate' => 'Default VAT rate',
    'Percentage used when a line has no Commerce tax and its tax category is not listed above.' => 'Percentage used when a line has no Commerce tax and its tax category is not listed above.',
    'Treatment is decided per order from the billing country, organization and tax ID: domestic, EU B2B (reverse charge, type 16), EU B2C, third-country B2B (17) and third-country B2C (3).' => 'Treatment is decided per order from the billing country, organization and tax ID: domestic, EU B2B (reverse charge, type 16), EU B2C, third-country B2B (17) and third-country B2C (3).',

    // Settings tabs
    'Connection' => 'Connection',
    'VAT' => 'VAT',
    'Payments' => 'Payments',
    'KPD' => 'KPD',
];
