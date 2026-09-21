<?php
declare(strict_types=1);

/**
 * One-off: turn mojwmd's e-računi error_log into anonymised JSON fixtures.
 * Usage: php extract-fixtures.php /path/to/error_log ../fixtures
 * Not shipped (export-ignore). Re-run whenever a new case is needed.
 */

[$_, $logPath, $outDir] = $argv + [null, null, null];
if (!$logPath || !$outDir || !is_file($logPath)) {
    fwrite(STDERR, "usage: extract-fixtures.php <error_log> <out-dir>\n");
    exit(1);
}

$lines = file($logPath, FILE_IGNORE_NEW_LINES);
$prefix = '/^\[[^\]]+\] /';

/** @return array<int, array{type:string, json:array, endLine:int}> */
function blocks(array $lines, string $prefix): array
{
    $out = [];
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        $line = preg_replace($prefix, '', $lines[$i]);
        $type = match (true) {
            str_contains($line, '=== SALES INVOICE - DATA SENT') => 'invoice.sent',
            str_contains($line, '=== SALES INVOICE - RESPONSE') => 'invoice.response',
            str_contains($line, '=== PARTNER CREATE - DATA SENT') => 'partner.sent',
            str_contains($line, '=== PARTNER CREATE - RESPONSE') => 'partner.response',
            default => null,
        };
        if ($type === null) {
            continue;
        }
        $buf = [];
        for ($j = $i + 1; $j < $n; $j++) {
            $raw = $lines[$j];
            if (str_contains($raw, '=== END ')) {
                $i = $j;
                break;
            }
            $buf[] = preg_replace($prefix, '', $raw);
        }
        $json = json_decode(implode("\n", $buf), true);
        if (is_array($json)) {
            $out[] = ['type' => $type, 'json' => $json, 'endLine' => $i];
        }
    }
    return $out;
}

/**
 * Adaptation (not in the original brief script): production error responses are NOT always
 * logged as a "=== RESPONSE FROM E-RAČUNI.HR ===" ... "=== END RESPONSE ===" block. When the
 * e-računi API rejects a request, the code instead logs a single line such as:
 *   "Eracuni REST exception for invoice 74218: Eracuni REST HTTP 500: {"response": {"status": "error", ...}}"
 * with no matching "DATA SENT"/"RESPONSE" pair, so the brief's exact blocks() loop silently
 * skips every real error case (confirmed: 0 *-error.json without this). This helper looks a
 * short distance past a "sent" block's end for that inline JSON tail and decodes it, stopping
 * at the next DATA SENT header so an error is never misattributed to an unrelated later block.
 */
function findInlineErrorResponse(array $lines, string $prefix, int $fromLine, int $maxLookahead = 30): ?array
{
    $n = count($lines);
    $limit = min($n, $fromLine + $maxLookahead);
    for ($i = $fromLine; $i < $limit; $i++) {
        $line = preg_replace($prefix, '', $lines[$i]);
        if (str_contains($line, 'DATA SENT TO E-RAČUNI.HR')) {
            break; // next request started; stop looking, this sent block truly has no response
        }
        if (preg_match('/(\{"response":\s*\{"status":\s*"error".*\})\s*$/', $line, $m)) {
            $json = json_decode($m[1], true);
            if (is_array($json)) {
                return $json;
            }
        }
    }
    return null;
}

function anonymise(array $a): array
{
    $map = [
        'buyerName' => 'Test Kupac', 'buyerStreet' => 'Ulica 1', 'buyerCity' => 'Zagreb',
        'buyerEMail' => 'kupac@example.com', 'buyerPhone' => '+385990000000',
        'firstName' => 'Test', 'lastName' => 'Kupac', 'companyName' => 'Tvrtka d.o.o.',
        'eMail' => 'kupac@example.com', 'PrimaryAddress_street' => 'Ulica 1',
        'personalID' => '12345678903', 'vatID' => 'SI12345678', 'buyerTaxNumber' => 'SI12345678',
        'partnerCode' => 'B2B-12345678903', 'buyerCode' => 'B2B-12345678903',
    ];
    array_walk_recursive($a, function (&$v, $k) use ($map) {
        if (array_key_exists($k, $map)) {
            $v = $map[$k];
        }
        if ($k === 'description' && is_string($v)) {
            $v = preg_replace('/\b[\w.-]+\.(hr|com|eu|net)\b/i', 'example.com', $v);
        }
    });
    return $a;
}

function classify(array $inv): string
{
    $type = $inv['type'] ?? 'B2B';
    $method = $inv['methodOfPayment'] ?? 'BankTransfer';
    $vtt = $inv['Items'][0]['vatTransactionType'] ?? null;
    if ($type === 'Retail') {
        return 'b2c-retail-' . strtolower($method);
    }
    return match ($vtt) { '16' => 'b2b-eu-reverse-charge', '17' => 'b2b-third-country', '3' => 'b2c-third-country', default => 'b2b-hr' };
}

$blocks = blocks($lines, $prefix);
@mkdir("$outDir/invoices", 0775, true);
@mkdir("$outDir/partners", 0775, true);
$seen = [];
for ($i = 0; $i < count($blocks); $i++) {
    $b = $blocks[$i];
    if (!str_ends_with($b['type'], '.sent')) {
        continue;
    }
    $resp = ($blocks[$i + 1]['type'] ?? '') === str_replace('.sent', '.response', $b['type']) ? $blocks[$i + 1]['json'] : null;
    $resp ??= findInlineErrorResponse($lines, $prefix, $b['endLine'] + 1); // see findInlineErrorResponse() doc
    if ($resp === null) {
        continue;
    }
    $kind = explode('.', $b['type'])[0];
    $name = $kind === 'invoice' ? classify($b['json']) : (isset($b['json']['vatID']) ? 'eu-vat' : (isset($b['json']['personalID']) ? 'hr-oib' : 'b2c-no-id'));
    $status = ($resp['response']['status'] ?? 'unknown');
    $name .= $status === 'ok' ? '' : '-error';
    if (isset($seen[$kind . $name])) {
        continue; // first occurrence of each shape is enough
    }
    $seen[$kind . $name] = true;
    $fixture = ['kind' => $kind, 'sent' => anonymise($b['json']), 'response' => anonymise($resp)];
    file_put_contents("$outDir/{$kind}s/$name.json", json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    echo "wrote {$kind}s/$name.json\n";
}
