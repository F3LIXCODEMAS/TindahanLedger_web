<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A freshly built rule set failed its checks before activation. The message
 * names the check and contains only rule-set and product identifiers, so it
 * is safe to store as the rule set's failure reason.
 */
class RuleSetValidationFailed extends RuntimeException {}
