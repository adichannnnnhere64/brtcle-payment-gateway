<?php

namespace Adichan\Payment\Gateways;

use Adichan\Payment\Interfaces\PaymentGatewayInterface;
use Adichan\Payment\Interfaces\PaymentResponseInterface;
use Adichan\Payment\Interfaces\PaymentVerificationInterface;
use Adichan\Payment\Interfaces\PaymentWebhookResultInterface;
use Adichan\Payment\Models\PaymentGateway as GatewayModel;
use Adichan\Transaction\Interfaces\TransactionInterface;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\Customer;
use Stripe\Webhook;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;

class StripeGateway extends AbstractGateway
{
    protected string $webhookSecret;
    
    public function __construct(GatewayModel $model)
    {
        parent::__construct($model);
        $this->initializeStripe();
    }
    
    protected function initializeStripe(): void
    {
        $apiKey = $this->config['secret_key'] ?? '';
        $this->webhookSecret = $this->config['webhook_secret'] ?? '';
        
        if (empty($apiKey)) {
            throw new \RuntimeException('Stripe secret key is not configured');
        }
        
        // Set Stripe API key
        Stripe::setApiKey($apiKey);
        Stripe::setApiVersion('2023-10-16'); // Use latest stable version
        
        // Set app info for tracking
        Stripe::setAppInfo(
            'Adichan Payment Gateway',
            '1.0.0',
            'https://github.com/adichan/payment',
            'pp_partner_XXXXXXXX' // Partner ID if you have one
        );
    }
    
    public function initiatePayment(TransactionInterface $transaction, array $options = []): PaymentResponseInterface
    {
        $this->validateTransaction($transaction);
        
        try {
            // Build payment intent data
            $paymentIntentData = [
                'amount' => (int) ($transaction->getTotal() * 100), // Convert to cents
                'currency' => strtolower($options['currency'] ?? 'usd'),
                'description' => $options['description'] ?? "Transaction #{$transaction->getId()}",
                'metadata' => [
                    'transaction_id' => $transaction->getId(),
                    'customer_ip' => request()->ip(),
                    'platform' => 'adichan_payment',
                ],
                'payment_method_types' => $options['payment_method_types'] ?? ['card'],
                'capture_method' => $options['capture_method'] ?? 'automatic',
            ];
            
            // Add customer if email is provided
            if (!empty($options['customer_email'])) {
                $customer = $this->findOrCreateCustomer(
                    $options['customer_email'],
                    $options['customer_name'] ?? null
                );
                $paymentIntentData['customer'] = $customer->id;
            }
            
            // Add shipping if provided
            if (!empty($options['shipping'])) {
                $paymentIntentData['shipping'] = [
                    'name' => $options['shipping']['name'] ?? null,
                    'address' => [
                        'line1' => $options['shipping']['address']['line1'] ?? null,
                        'city' => $options['shipping']['address']['city'] ?? null,
                        'country' => $options['shipping']['address']['country'] ?? null,
                        'postal_code' => $options['shipping']['address']['postal_code'] ?? null,
                    ],
                ];
            }
            
            // Add statement descriptor if provided
            if (!empty($options['statement_descriptor'])) {
                $paymentIntentData['statement_descriptor'] = substr($options['statement_descriptor'], 0, 22);
            }
            
            // Add receipt email if provided
            if (!empty($options['receipt_email'])) {
                $paymentIntentData['receipt_email'] = $options['receipt_email'];
            }
            
            // Create Payment Intent
            $paymentIntent = PaymentIntent::create($paymentIntentData);
            
            // Create payment record in database
            $payment = \Adichan\Payment\Models\PaymentTransaction::create([
                'gateway_id' => $this->model->id,
                'transaction_id' => $transaction->getId(),
                'gateway_transaction_id' => $paymentIntent->id,
                'gateway_name' => $this->getName(),
                'amount' => $transaction->getTotal(),
                'currency' => strtoupper($options['currency'] ?? 'USD'),
                'status' => $paymentIntent->status,
                'payment_method' => 'stripe',
                'payer_info' => $paymentIntent->customer ? [
                    'customer_id' => $paymentIntent->customer,
                    'email' => $options['customer_email'] ?? null,
                    'name' => $options['customer_name'] ?? null,
                ] : null,
                'metadata' => [
                    'payment_intent' => $paymentIntent->toArray(),
                    'options' => $options,
                    'client_secret' => $paymentIntent->client_secret,
                ],
            ]);
            
            // Determine if action is required
            $requiresAction = in_array($paymentIntent->status, [
                'requires_action',
                'requires_confirmation',
                'requires_payment_method'
            ]);
            
            return new \Adichan\Payment\PaymentResponse(
                $paymentIntent->status !== 'canceled',
                $paymentIntent->id,
                null,
                $paymentIntent->last_payment_error?->message,
                [
                    'client_secret' => $paymentIntent->client_secret,
                    'payment_intent' => $paymentIntent->toArray(),
                    'payment_id' => $payment->id,
                ],
                $requiresAction,
                $this->buildActionData($paymentIntent, $options)
            );
            
        } catch (ApiErrorException $e) {
            return new \Adichan\Payment\PaymentResponse(
                false,
                null,
                null,
                $e->getMessage(),
                [
                    'error' => $e->getMessage(),
                    'stripe_error' => $e->getError()?->toArray(),
                ]
            );
        } catch (\Exception $e) {
            return new \Adichan\Payment\PaymentResponse(
                false,
                null,
                null,
                $e->getMessage(),
                ['error' => $e->getMessage()]
            );
        }
    }
    
    public function verifyPayment(string $paymentId, array $data = []): PaymentVerificationInterface
    {
        try {
            $paymentIntent = PaymentIntent::retrieve($paymentId);
            $payment = \Adichan\Payment\Models\PaymentTransaction::where('gateway_transaction_id', $paymentId)->first();
            
            // Update payment status in database
            if ($payment) {
                $payment->update([
                    'status' => $paymentIntent->status,
                    'metadata' => array_merge($payment->metadata ?? [], [
                        'verification_response' => $paymentIntent->toArray(),
                        'verified_at' => now()->toIso8601String(),
                    ]),
                ]);
                
                // If payment is successful, mark as verified
                if ($paymentIntent->status === 'succeeded') {
                    $payment->markAsVerified(['stripe_verified' => true]);
                    
                    if ($payment->transaction) {
                        $payment->transaction->complete();
                    }
                }
            }
            
            $isVerified = in_array($paymentIntent->status, ['succeeded', 'processing']);
            
            return new \Adichan\Payment\PaymentVerification(
                $isVerified,
                $payment?->transaction,
                $this->getName(),
                $paymentIntent->status,
                $isVerified ? now() : null,
                ['payment_intent' => $paymentIntent->toArray()]
            );
            
        } catch (ApiErrorException $e) {
            return new \Adichan\Payment\PaymentVerification(
                false,
                null,
                $this->getName(),
                'failed',
                null,
                ['error' => $e->getMessage()]
            );
        }
    }
    
    public function refund(string $paymentId, float $amount = null): PaymentResponseInterface
    {
        try {
            // Retrieve payment intent to get charge ID
            $paymentIntent = PaymentIntent::retrieve($paymentId);
            $chargeId = $paymentIntent->latest_charge;
            
            if (!$chargeId) {
                throw new \RuntimeException('No charge found for this payment');
            }
            
            $refundData = ['charge' => $chargeId];
            
            if ($amount !== null) {
                $refundData['amount'] = (int) ($amount * 100);
            }
            
            // Add reason if provided
            if (!empty($options['reason'])) {
                $refundData['reason'] = $options['reason']; // duplicate, fraudulent, requested_by_customer
            }
            
            $refund = Refund::create($refundData);
            
            // Update payment record
            $payment = \Adichan\Payment\Models\PaymentTransaction::where('gateway_transaction_id', $paymentId)->first();
            if ($payment) {
                $payment->update([
                    'status' => 'refunded',
                    'metadata' => array_merge($payment->metadata ?? [], [
                        'refund' => $refund->toArray(),
                        'refunded_at' => now()->toIso8601String(),
                    ]),
                ]);
            }
            
            return new \Adichan\Payment\PaymentResponse(
                $refund->status === 'succeeded',
                $refund->id,
                null,
                $refund->status !== 'succeeded' ? 'Refund failed' : null,
                ['stripe_refund' => $refund->toArray()]
            );
            
        } catch (ApiErrorException $e) {
            return new \Adichan\Payment\PaymentResponse(
                false,
                null,
                null,
                $e->getMessage(),
                ['error' => $e->getMessage()]
            );
        }
    }
    
    public function supportsWebhook(): bool
    {
        return !empty($this->webhookSecret);
    }
    
    public function handleWebhook(array $payload): PaymentWebhookResultInterface
    {
        $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        
        try {
            // Construct and verify the event
            $event = Webhook::constructEvent(
                json_encode($payload),
                $sigHeader,
                $this->webhookSecret
            );
            
            $eventType = $event->type;
            $paymentIntent = $event->data->object;
            
            // Find payment record
            $payment = \Adichan\Payment\Models\PaymentTransaction::where('gateway_transaction_id', $paymentIntent->id)->first();
            
            if ($payment) {
                $payment->update([
                    'status' => $paymentIntent->status ?? $paymentIntent->payment_intent->status ?? $payment->status,
                    'webhook_received' => true,
                    'metadata' => array_merge($payment->metadata ?? [], [
                        'webhook_events' => array_merge($payment->metadata['webhook_events'] ?? [], [
                            [
                                'type' => $eventType,
                                'received_at' => now()->toIso8601String(),
                                'payload' => $event->toArray(),
                            ]
                        ])
                    ]),
                ]);
                
                // Determine if we should process this webhook
                $shouldProcess = in_array($eventType, [
                    'payment_intent.succeeded',
                    'payment_intent.payment_failed',
                    'payment_intent.canceled',
                    'charge.refunded',
                    'charge.succeeded',
                    'charge.failed',
                ]);
                
                // Handle different event types
                switch ($eventType) {
                    case 'payment_intent.succeeded':
                    case 'charge.succeeded':
                        $payment->update(['status' => 'succeeded']);
                        $payment->markAsVerified(['webhook_verified' => true]);
                        
                        if ($payment->transaction) {
                            $payment->transaction->complete();
                        }
                        break;
                        
                    case 'payment_intent.payment_failed':
                    case 'charge.failed':
                        $payment->update(['status' => 'failed']);
                        $payment->markAsFailed('Payment failed via Stripe webhook');
                        break;
                        
                    case 'payment_intent.canceled':
                        $payment->update(['status' => 'canceled']);
                        break;
                        
                    case 'charge.refunded':
                        $payment->update(['status' => 'refunded']);
                        break;
                        
                    case 'payment_intent.requires_action':
                        $payment->update(['status' => 'requires_action']);
                        break;
                        
                    case 'payment_intent.processing':
                        $payment->update(['status' => 'processing']);
                        break;
                }
                
                return new \Adichan\Payment\PaymentWebhookResult(
                    $eventType,
                    $paymentIntent->id,
                    $event->toArray(),
                    $shouldProcess,
                    [
                        'success' => true,
                        'payment_id' => $payment->id,
                        'payment_status' => $payment->status,
                    ]
                );
            }
            
            // Payment record not found - this could be for a different type of event
            return new \Adichan\Payment\PaymentWebhookResult(
                $eventType,
                $paymentIntent->id,
                $event->toArray(),
                false,
                ['error' => 'Payment record not found', 'event_type' => $eventType]
            );
            
        } catch (SignatureVerificationException $e) {
            // Invalid signature
            return new \Adichan\Payment\PaymentWebhookResult(
                'signature_verification_failed',
                '',
                $payload,
                false,
                ['error' => $e->getMessage(), 'signature_invalid' => true]
            );
            
        } catch (ApiErrorException $e) {
            return new \Adichan\Payment\PaymentWebhookResult(
                'stripe_api_error',
                '',
                $payload,
                false,
                ['error' => $e->getMessage(), 'stripe_error' => $e->getError()?->toArray()]
            );
            
        } catch (\Exception $e) {
            return new \Adichan\Payment\PaymentWebhookResult(
                'error',
                '',
                $payload,
                false,
                ['error' => $e->getMessage()]
            );
        }
    }
    
    /**
     * Find or create a Stripe customer
     */
    protected function findOrCreateCustomer(string $email, ?string $name = null): Customer
    {
        try {
            // Try to find existing customer by email
            $customers = Customer::all(['email' => $email, 'limit' => 1]);
            
            if (count($customers->data) > 0) {
                return $customers->data[0];
            }
            
            // Create new customer
            $customerData = ['email' => $email];
            
            if ($name) {
                $customerData['name'] = $name;
            }
            
            if (!empty($this->config['customer_metadata'])) {
                $customerData['metadata'] = $this->config['customer_metadata'];
            }
            
            return Customer::create($customerData);
            
        } catch (ApiErrorException $e) {
            throw new \RuntimeException("Failed to create Stripe customer: " . $e->getMessage());
        }
    }
    
    /**
     * Build action data for frontend
     */
    protected function buildActionData(PaymentIntent $paymentIntent, array $options): array
    {
        $actionData = [];
        
        if ($paymentIntent->status === 'requires_action' && $paymentIntent->next_action) {
            if ($paymentIntent->next_action->type === 'redirect_to_url') {
                $actionData = [
                    'type' => 'redirect',
                    'redirect_url' => $paymentIntent->next_action->redirect_to_url->url,
                ];
            } elseif ($paymentIntent->next_action->type === 'use_stripe_sdk') {
                $actionData = [
                    'type' => 'stripe_sdk',
                    'client_secret' => $paymentIntent->client_secret,
                ];
            }
        }
        
        // Add Stripe.js configuration
        $actionData['stripe_config'] = [
            'publishable_key' => $this->config['public_key'] ?? '',
            'api_version' => Stripe::getApiVersion(),
        ];
        
        return $actionData;
    }
    
    /**
     * Confirm a payment intent (for manual capture)
     */
    public function confirmPayment(string $paymentId, array $data = []): PaymentResponseInterface
    {
        try {
            $paymentIntent = PaymentIntent::retrieve($paymentId);
            
            $confirmData = [];
            if (!empty($data['payment_method'])) {
                $confirmData['payment_method'] = $data['payment_method'];
            }
            
            if (!empty($data['return_url'])) {
                $confirmData['return_url'] = $data['return_url'];
            }
            
            $paymentIntent = $paymentIntent->confirm($confirmData);
            
            return new \Adichan\Payment\PaymentResponse(
                in_array($paymentIntent->status, ['succeeded', 'processing']),
                $paymentIntent->id,
                null,
                $paymentIntent->last_payment_error?->message,
                ['payment_intent' => $paymentIntent->toArray()]
            );
            
        } catch (ApiErrorException $e) {
            return new \Adichan\Payment\PaymentResponse(
                false,
                null,
                null,
                $e->getMessage(),
                ['error' => $e->getMessage()]
            );
        }
    }
    
    /**
     * Capture a payment intent (for manual capture)
     */
    public function capturePayment(string $paymentId, array $data = []): PaymentResponseInterface
    {
        try {
            $paymentIntent = PaymentIntent::retrieve($paymentId);
            
            $captureData = [];
            if (!empty($data['amount_to_capture'])) {
                $captureData['amount_to_capture'] = (int) ($data['amount_to_capture'] * 100);
            }
            
            $paymentIntent = $paymentIntent->capture($captureData);
            
            // Update payment record
            $payment = \Adichan\Payment\Models\PaymentTransaction::where('gateway_transaction_id', $paymentId)->first();
            if ($payment) {
                $payment->update([
                    'status' => $paymentIntent->status,
                    'metadata' => array_merge($payment->metadata ?? [], [
                        'captured_at' => now()->toIso8601String(),
                        'capture_response' => $paymentIntent->toArray(),
                    ]),
                ]);
                
                if ($paymentIntent->status === 'succeeded') {
                    $payment->markAsVerified(['manually_captured' => true]);
                }
            }
            
            return new \Adichan\Payment\PaymentResponse(
                $paymentIntent->status === 'succeeded',
                $paymentIntent->id,
                null,
                null,
                ['payment_intent' => $paymentIntent->toArray()]
            );
            
        } catch (ApiErrorException $e) {
            return new \Adichan\Payment\PaymentResponse(
                false,
                null,
                null,
                $e->getMessage(),
                ['error' => $e->getMessage()]
            );
        }
    }
    
    /**
     * Cancel a payment intent
     */
    public function cancelPayment(string $paymentId, array $data = []): PaymentResponseInterface
    {
        try {
            $paymentIntent = PaymentIntent::retrieve($paymentId);
            $paymentIntent = $paymentIntent->cancel($data);
            
            // Update payment record
            $payment = \Adichan\Payment\Models\PaymentTransaction::where('gateway_transaction_id', $paymentId)->first();
            if ($payment) {
                $payment->update([
                    'status' => 'canceled',
                    'metadata' => array_merge($payment->metadata ?? [], [
                        'canceled_at' => now()->toIso8601String(),
                        'cancellation_reason' => $data['cancellation_reason'] ?? 'requested_by_merchant',
                    ]),
                ]);
            }
            
            return new \Adichan\Payment\PaymentResponse(
                true,
                $paymentIntent->id,
                null,
                null,
                ['payment_intent' => $paymentIntent->toArray()]
            );
            
        } catch (ApiErrorException $e) {
            return new \Adichan\Payment\PaymentResponse(
                false,
                null,
                null,
                $e->getMessage(),
                ['error' => $e->getMessage()]
            );
        }
    }
}
