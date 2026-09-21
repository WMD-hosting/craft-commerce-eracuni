<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\services;

use craft\base\Component;
use craft\base\ElementInterface;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\LineItem;
use craft\elements\Address;
use wmd\commerceeracuni\core\Buyer;
use wmd\commerceeracuni\core\Line;
use wmd\commerceeracuni\core\OrderSnapshot;
use wmd\commerceeracuni\core\VatResolver;
use wmd\commerceeracuni\Plugin;
use yii\base\InvalidConfigException;

class OrderSnapshotFactory extends Component
{
    public function fromOrder(Order $order): OrderSnapshot
    {
        $settings = Plugin::getInstance()->getSettings();
        $rates = array_map('floatval', $settings->knownRates());

        $lines = [];
        foreach ($order->getLineItems() as $li) {
            $qty = (float) $li->qty;
            if ($qty <= 0.0) {
                continue;
            }
            $subtotal = (float) $li->getSubtotal();
            $discount = (float) $li->getDiscount();          // ≤ 0
            $taxIncl = (float) $li->getTaxIncluded();
            $taxAdded = (float) $li->getTax();
            $gross = round($subtotal + $discount + $taxAdded, 2);
            $rate = $taxIncl > 0.0
                ? VatResolver::rateFromAmounts($subtotal + $discount, $taxIncl, true, $rates)
                : VatResolver::rateFromAmounts($subtotal + $discount, $taxAdded, false, $rates);
            $grossUnit = round($gross / $qty, 4);
            $lines[] = new Line(
                description: $this->lineDescription($li),
                quantity: $qty,
                unit: 'kom',
                grossUnitPrice: round($grossUnit, 2),
                netUnitPrice: round($grossUnit / (1 + $rate / 100), 2),
                vatRate: $rate,
                kpd: $this->kpdFor($li, $settings->kpdFieldHandle, $settings->kpdDefaults),
            );
        }

        $orderDiscount = 0.0;
        foreach ($order->getAdjustmentsByType('discount') as $adj) {
            if ($adj->lineItemId === null) {
                $orderDiscount += abs((float) $adj->amount);
            }
        }

        $address = $order->getBillingAddress() ?? $order->getShippingAddress();
        $buyer = new Buyer(
            firstName: $address?->firstName ?: $order->getCustomer()?->firstName,
            lastName: $address?->lastName ?: $order->getCustomer()?->lastName,
            organization: $address?->organization,
            taxId: $address?->organizationTaxId,
            street: $address?->addressLine1,
            postalCode: $address?->postalCode,
            city: $address?->locality,
            countryCode: $address !== null ? $address->countryCode : $settings->sellerCountry,
            email: $order->getEmail(),
            phone: $this->phoneFor($address, $settings->phoneFieldHandle),
        );

        $gateway = $order->getGateway();

        return new OrderSnapshot(
            orderId: (int) $order->id,
            number: (string) ($order->reference ?: $order->number),
            dateOrdered: ($order->dateOrdered ?? new \DateTime())->format('Y-m-d'),
            datePaid: $order->datePaid?->format('Y-m-d'),
            isPaid: $order->getIsPaid(),
            currency: (string) $order->currency,
            gatewayHandle: (string) ($gateway !== null ? ($gateway->handle ?? '') : ''),
            totalPrice: round((float) $order->getTotalPrice(), 2),
            totalShipping: round((float) $order->getTotalShippingCost(), 2),
            totalDiscount: round($orderDiscount, 2),
            lines: $lines,
            buyer: $buyer,
        );
    }

    public function customerKey(Order $order): string
    {
        $id = $order->getCustomerId();
        return $id ? (string) $id : 'guest-' . substr(md5(strtolower((string) $order->getEmail())), 0, 12);
    }

    public function billingOib(Order $order): ?string
    {
        $tax = $order->getBillingAddress()?->organizationTaxId;
        return $tax && VatResolver::isValidOib($tax) ? preg_replace('/\D/', '', $tax) : null;
    }

    private function lineDescription(LineItem $li): string
    {
        $d = trim((string) $li->getDescription());
        $sku = trim((string) $li->getSku());
        return $sku !== '' && !str_contains($d, $sku) ? "$d ($sku)" : $d;
    }

    /** Variant field → product field → product type default → null. */
    private function kpdFor(LineItem $li, string $handle, array $defaults): ?string
    {
        try {
            $p = $li->getPurchasable();
        } catch (InvalidConfigException) {
            return null; // custom line item: no purchasable, no KPD source
        }
        if ($p instanceof Variant) {
            $v = $this->fieldValue($p, $handle);
            if ($v !== null) {
                return $v;
            }
            $product = $p->getProduct();
            if ($product instanceof Product) {
                $v = $this->fieldValue($product, $handle);
                if ($v !== null) {
                    return $v;
                }
                $typeHandle = $product->getType()->handle;
                if (!empty($defaults[$typeHandle])) {
                    return (string) $defaults[$typeHandle];
                }
            }
        }
        return null;
    }

    private function fieldValue(ElementInterface $el, string $handle): ?string
    {
        if ($handle === '' || $el->getFieldLayout()?->getFieldByHandle($handle) === null) {
            return null;
        }
        $v = trim((string) $el->getFieldValue($handle));
        return $v !== '' ? $v : null;
    }

    private function phoneFor(?Address $address, string $handle): ?string
    {
        if ($address === null || $handle === '') {
            return null;
        }
        return $this->fieldValue($address, $handle);
    }
}
