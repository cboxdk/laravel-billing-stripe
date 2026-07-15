<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe\Exceptions;

use RuntimeException;

/**
 * A Stripe webhook payload failed signature verification (bad signature, stale
 * timestamp, malformed body, or a missing signing secret). The handler rejects the
 * delivery — deny-by-default: an unverified payload is never processed.
 */
class WebhookVerificationFailed extends RuntimeException {}
