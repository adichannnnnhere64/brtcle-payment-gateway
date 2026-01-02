<?php

namespace Adichan\Payment\Tests\Helpers;

use Illuminate\Support\Str;

class StripeWebhookHelper
{
    public static function generateSignature(array $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?: time();
        $payloadString = json_encode($payload);
        $signedPayload = $timestamp.'.'.$payloadString;
        $signature = hash_hmac('sha256', $signedPayload, $secret);

        return "t={$timestamp},v1={$signature}";
    }

    public static function createPaymentIntentSucceededPayload(string $paymentIntentId): array
    {
        return [
            'id' => 'evt_'.Str::random(24),
            'object' => 'event',
            'api_version' => '2023-10-16',
            'created' => time(),
            'data' => [
                'object' => [
                    'id' => $paymentIntentId,
                    'object' => 'payment_intent',
                    'amount' => 2000,
                    'amount_capturable' => 0,
                    'amount_received' => 2000,
                    'currency' => 'usd',
                    'description' => 'Test payment intent',
                    'metadata' => ['test' => true],
                    'payment_method' => 'pm_'.Str::random(24),
                    'payment_method_types' => ['card'],
                    'status' => 'succeeded',
                ],
            ],
            'livemode' => false,
            'type' => 'payment_intent.succeeded',
        ];
    }
}
