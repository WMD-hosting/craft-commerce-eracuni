<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\tests\core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use wmd\commerceeracuni\core\BuilderConfig;
use wmd\commerceeracuni\core\Buyer;
use wmd\commerceeracuni\core\InvoiceBuilder;
use wmd\commerceeracuni\core\Line;
use wmd\commerceeracuni\core\OrderSnapshot;
use wmd\commerceeracuni\tests\Support\Fixtures;

/**
 * mojwmd is the oracle: for each recorded payload, rebuild an equivalent order and require the
 * same structure. Volatile fields (dates, reference, business unit, descriptions, ids) are normalised.
 *
 * `_fallbackMethods` is an internal mojwmd bookkeeping key recorded on Retail payloads (the list of
 * payment methods tried before the one that stuck); the plugin must never emit it, so it is stripped
 * before comparison rather than asserted against.
 */
final class InvoiceBuilderParityTest extends TestCase
{
    private const VOLATILE_ROOT = ['date', 'dueDate', 'reference', 'businessUnit', 'partnerID', 'buyerCode', 'cashRegisterCode', 'buyerName', 'buyerStreet', 'buyerPostalCode', 'buyerCity', 'buyerEMail', 'buyerPhone', 'orderReference', '_fallbackMethods'];
    private const VOLATILE_ITEM = ['description'];

    #[DataProvider('fixtures')]
    public function testPayloadMatchesRecorded(string $name, string $countryCode, ?string $org, ?string $taxId, string $gateway): void
    {
        $sent = Fixtures::invoice($name)['sent'];
        $retail = ($sent['type'] ?? '') === 'Retail';
        $lines = [];
        $total = 0.0;
        foreach ($sent['Items'] as $it) {
            $rate = (float) ($retail ? $it['vatPercentage'] : $it['vatRate']);
            $gross = $retail ? (float) $it['price'] : round((float) $it['netPrice'] * (1 + $rate / 100), 2);
            $net = $retail ? round($gross / (1 + $rate / 100), 2) : (float) $it['netPrice'];
            $lines[] = new Line($it['description'], (float) $it['quantity'], $it['unit'] ?? 'kom', $gross, $net, $rate, $it['classificationCode'] ?? null);
            $total += round($gross * (float) $it['quantity'], 2);
        }
        $buyer = new Buyer('Test', 'Kupac', $org, $taxId, 'Ulica 1', '10000', 'Zagreb', $countryCode, 'kupac@example.com', null);
        $snap = new OrderSnapshot(1, 'REF', '2026-01-27', null, false, $sent['currencyCode'], $gateway, round($total, 2), 0.0, 0.0, $lines, $buyer);
        $config = new BuilderConfig(sellerCountry: 'HR', businessUnit: 'WMD', today: '2026-01-27',
            paymentMap: [$gateway => ['method' => $sent['methodOfPayment'], 'fiscalised' => true, 'paymentMethodForInvoice' => 'Card']]);

        $built = InvoiceBuilder::build($snap, $config, $sent['partnerID'] ?? null, $sent['buyerCode'] ?? null)->payload;

        self::assertSame($this->normalise($sent), $this->normalise($built), "Payload diverges from mojwmd fixture $name");
    }

    public static function fixtures(): array
    {
        // name, buyer country, organization, taxId, gateway handle. Extend as fixtures are added.
        $rows = [
            ['b2c-retail-banktransfer', 'HR', null, null, 'uplatnica'],
            ['b2b-hr', 'HR', 'Tvrtka d.o.o.', '12345678903', 'uplatnica'],
            ['b2c-retail-visa', 'HR', null, null, 'wsPay'],
            ['b2b-eu-reverse-charge', 'SI', 'Tvrtka d.o.o.', 'SI12345678', 'uplatnica'],
            ['b2c-third-country', 'US', null, null, 'wsPay'],
            // buyerCountry is real (non-anonymised) data on this fixture — see tests/fixtures/README.md —
            // and is not in VOLATILE_ROOT, so the row must use the buyer's actual country (DK) to reproduce
            // the recorded payload byte-for-byte. EU_B2C for a Danish buyer resolves to the same Retail
            // shape as domestic B2C (no vatTransactionType either way), so this exercises that path too.
            ['b2c-retail-paypal', 'DK', null, null, 'paypal'],
        ];
        return array_filter($rows, fn($r) => is_file(dirname(__DIR__) . "/fixtures/invoices/{$r[0]}.json"));
    }

    private function normalise(array $p): array
    {
        foreach (self::VOLATILE_ROOT as $k) {
            unset($p[$k]);
        }
        foreach ($p['Items'] as &$it) {
            foreach (self::VOLATILE_ITEM as $k) {
                unset($it[$k]);
            }
            foreach ($it as $k => $v) {
                if (is_numeric($v) && !is_string($v)) {
                    $it[$k] = round((float) $v, 2);
                } elseif (is_numeric($v)) {
                    $it[$k] = is_int($v + 0) ? $v : (string) round((float) $v, 2);
                }
            }
            ksort($it);
        }
        unset($it);
        ksort($p);
        return $p;
    }
}
