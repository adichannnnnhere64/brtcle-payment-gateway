<?php

return [
    'default_gateway' => env('PAYMENT_DEFAULT_GATEWAY', 'internal'),

    'gateways' => [
        'internal' => [
            'name' => 'Wallet',
            'driver' => 'internal',
            'is_active' => true,
            'is_external' => false,
            'priority' => 1,
        ],

        'stripe' => [
            'name' => 'Stripe',
            'driver' => 'stripe',
            'is_active' => env('STRIPE_ENABLED', false),
            'is_external' => true,
            'priority' => 2,
            'config' => [
                'secret_key' => env('STRIPE_SECRET_KEY'),
                'public_key' => env('STRIPE_PUBLIC_KEY'),
                'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            ],
        ],

        'paypal' => [
            'name' => 'PayPal',
            'driver' => 'paypal',
            'is_active' => env('PAYPAL_ENABLED', false),
            'is_external' => true,
            'priority' => 3,
            'config' => [
                'client_id' => env('PAYPAL_CLIENT_ID'),
                'client_secret' => env('PAYPAL_CLIENT_SECRET'),
                'mode' => env('PAYPAL_MODE', 'sandbox'),
                'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
            ],
        ],
    ],

    'webhook_path' => 'payment/webhook/{gateway}',

    'currency' => 'USD',

    'routes' => [
        'prefix' => 'payment',
        'middleware' => ['api'],
    ],
];
