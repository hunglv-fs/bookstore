<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An inventory operation that would break stock consistency, e.g. releasing
 * a reservation twice or adjusting stock below what is already reserved.
 * These indicate a bug or a race in the caller, not bad user input.
 */
class InventoryException extends RuntimeException {}
