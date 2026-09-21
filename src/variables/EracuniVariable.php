<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\variables;

use craft\commerce\elements\Order;
use wmd\commerceeracuni\models\Document;
use wmd\commerceeracuni\Plugin;

/**
 * Twig `craft.commerceEracuni` variable.
 */
class EracuniVariable
{
    /**
     * Looks up the invoice document for a Commerce order, if one has been sent (or queued).
     */
    public function documentForOrder(Order|int $order): ?Document
    {
        $id = $order instanceof Order ? (int) $order->id : $order;
        return Plugin::getInstance()->documents->getForOrder($id);
    }
}
