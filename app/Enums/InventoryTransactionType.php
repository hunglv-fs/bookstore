<?php

namespace App\Enums;

enum InventoryTransactionType: string
{
    /** Stock received: quantity += n */
    case Purchase = 'purchase';

    /** Order delivered: quantity -= n and reserved_quantity -= n */
    case Sale = 'sale';

    /** Order placed: reserved_quantity += n */
    case Reservation = 'reservation';

    /** Order cancelled: reserved_quantity -= n */
    case Release = 'release';

    /** Manual correction (stock count, damage...): quantity += n (n may be negative) */
    case Adjustment = 'adjustment';
}
