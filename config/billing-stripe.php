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

    /*
    |--------------------------------------------------------------------------
    | Webhook signing secret
    |--------------------------------------------------------------------------
    |
    | The endpoint signing secret (`whsec_…`) used to verify incoming webhook
    | signatures. When set, the webhook verifier and handler are bound; without it
    | the handler is unavailable and unverified payloads are rejected by default.
    |
    */

    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

];
