<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A credit's structured items could not be accepted, for example a product
 * from another store, a deactivated product, or an invalid quantity. The
 * whole credit is rejected; nothing is recorded.
 */
class InvalidPurchaseItems extends RuntimeException {}
