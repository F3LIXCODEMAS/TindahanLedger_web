<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * A training threshold is missing, malformed, or out of range. The message
 * names the threshold and the accepted range.
 */
class InvalidAssociationThresholds extends InvalidArgumentException {}
