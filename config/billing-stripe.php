<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Stripe secret key
    |--------------------------------------------------------------------------
    |
    | When set, the Stripe gateway is bound as the billing PaymentGateway. Without
    | it, billing keeps its default (manual) gateway.
    |
    */

    'secret' => env('STRIPE_SECRET'),

];
