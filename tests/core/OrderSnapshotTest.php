<?php
declare(strict_types=1);

namespace wmd\commerceeracuni\tests\core;

use PHPUnit\Framework\TestCase;
use wmd\commerceeracuni\core\Buyer;
use wmd\commerceeracuni\core\Line;
use wmd\commerceeracuni\core\OrderSnapshot;

final class OrderSnapshotTest extends TestCase
{
    public function testBuildsFromArrays(): void
    {
        $buyer = new Buyer(
            firstName: 'Ana', lastName: 'Anić', organization: null, taxId: null,
            street: 'Ilica 1', postalCode: '10000', city: 'Zagreb', countryCode: 'HR',
            email: 'ana@example.com', phone: null,
        );
        $line = new Line(
            description: 'Majica', quantity: 2.0, unit: 'kom',
            grossUnitPrice: 25.00, netUnitPrice: 20.00, vatRate: 25.0, kpd: '141400', kind: Line::KIND_ITEM,
        );
        $snap = new OrderSnapshot(
            orderId: 42, number: 'a1b2c3', dateOrdered: '2026-09-21', datePaid: null, isPaid: false,
            currency: 'EUR', gatewayHandle: 'uplatnica', totalPrice: 50.00, totalShipping: 0.0,
            totalDiscount: 0.0, lines: [$line], buyer: $buyer,
        );
        self::assertSame(50.0, $snap->totalPrice);
        self::assertSame('kom', $snap->lines[0]->unit);
        self::assertSame('HR', $snap->buyer->countryCode);
        self::assertFalse($snap->buyer->isBusiness());
    }

    public function testBuyerIsBusinessWhenOrganizationPresent(): void
    {
        $b = new Buyer('A', 'B', 'Tvrtka d.o.o.', '12345678903', 'x', '10000', 'Zagreb', 'HR', 'a@b.c', null);
        self::assertTrue($b->isBusiness());
        self::assertSame('Tvrtka d.o.o.', $b->displayName());
    }
}
