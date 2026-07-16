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
    | Stripe publishable key
    |--------------------------------------------------------------------------
    |
    | The public key (`pk_…`) a product's frontend loads Stripe.js and mounts the
    | payment element with. It is returned in the PaymentIntent / SetupIntent result
    | so the client can confirm (and complete any SCA / 3-D Secure challenge). Safe to
    | expose to the browser; when unset the intent result carries no publishable key.
    |
    */

    'publishable' => env('STRIPE_PUBLISHABLE'),

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
