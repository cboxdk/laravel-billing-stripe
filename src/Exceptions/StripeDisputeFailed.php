<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe\Exceptions;

use RuntimeException;

/** A dispute call Stripe refused or could not be reached for, or evidence the adapter refused to send. */
class StripeDisputeFailed extends RuntimeException
{
    /** @param  list<string>  $fields */
    public static function unknownFields(array $fields): self
    {
        return new self('Stripe does not take these evidence fields: '.implode(', ', $fields).'.');
    }
}
