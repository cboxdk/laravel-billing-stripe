<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe\Exceptions;

use RuntimeException;

/**
 * A Stripe API call to create a payment failed. The gateway turns this into a
 * failed PaymentResult rather than letting it propagate.
 */
class StripeChargeFailed extends RuntimeException {}
