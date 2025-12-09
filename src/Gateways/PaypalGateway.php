<?php

namespace Adichan\Payment\Gateways;

use Adichan\Payment\Interfaces\PaymentGatewayInterface;
use Adichan\Payment\Interfaces\PaymentResponseInterface;
use Adichan\Payment\Interfaces\PaymentVerificationInterface;
use Adichan\Payment\Interfaces\PaymentWebhookResultInterface;
use Adichan\Payment\Models\PaymentGateway as GatewayModel;
use Adichan\Transaction\Interfaces\TransactionInterface;
use PayPalCheckoutSdk\Core\PayPalHttpClient;
use PayPalCheckoutSdk\Core\SandboxEnvironment;
use PayPalCheckoutSdk\Core\ProductionEnvironment;
use PayPalCheckoutSdk\Orders\OrdersCreateRequest;
use PayPalCheckoutSdk\Orders\OrdersGetRequest;
use PayPalCheckoutSdk\Orders\OrdersCaptureRequest;
use PayPalCheckoutSdk\Payments\CapturesRefundRequest;

class PayPalGateway extends AbstractGateway
{
    protected PayPalHttpClient $client;
    protected string $webhookId;
    
    public function __construct(GatewayModel $model)
    {
        parent::__construct($model);
        $this->initializeClient();
    }
    
    protected function initializeClient(): void
    {
        $clientId = $this->config['client_id'] ?? '';
        $clientSecret = $this->config['client_secret'] ?? '';
        $mode = $this->config['mode'] ?? 'sandbox';
        $this->webhookId = $this->config['webhook_id'] ?? '';
        
        if (empty($clientId) || empty($clientSecret)) {
            throw new \RuntimeException('PayPal client credentials are not configured');
        }
        
        if ($mode === 'production') {
            $environment = new ProductionEnvironment($clientId, $clientSecret);
        } else {
            $environment = new SandboxEnvironment($clientId, $clientSecret);
        }
        
        $this->client = new PayPalHttpClient($environment);
    }
    
    public function initiatePayment(TransactionInterface $transaction, array $options = []): PaymentResponseInterface
    {
        $this->validateTransaction($transaction);
        
        try {
            $request = new OrdersCreateRequest();
            $request->prefer('return=representation');
            
            $orderData = [
                'intent' => 'CAPTURE',
                'application_context' => [
                    'return_url' => $options['return_url'] ?? $this->config['return_url'] ?? route('payment.callback'),
                    'cancel_url' => $options['cancel_url'] ?? $this->config['cancel_url'] ?? route('payment.cancel'),
                    'brand_name' => $options['brand_name'] ?? config('app.name'),
                    'locale' => $options['locale'] ?? 'en-US',
                    'landing_page' => 'BILLING',
                    'shipping_preference' => 'NO_SHIPPING',
                    'user_action' => 'PAY_NOW',
                ],
                'purchase_units' => [
                    [
                        'reference_id' => 'transaction_' . $transaction->getId(),
                        'description' => $options['description'] ?? 'Transaction #' . $transaction->getId(),
                        'custom_id' => (string) $transaction->getId(),
                        'amount' => [
                            'currency_code' => strtoupper($options['currency'] ?? 'USD'),
                            'value' => number_format($transaction->getTotal(), 2, '.', ''),
                            'breakdown' => [
                                'item_total' => [
                                    'currency_code' => strtoupper($options['currency'] ?? 'USD'),
                                    'value' => number_format($transaction->getTotal(), 2, '.', ''),
                                ]
                            ]
                        ],
                        'items' => $this->buildOrderItems($transaction, $options),
                    ]
                ],
            ];
            
            $request->body = $orderData;
            $response = $this->client->execute($request);
            $order = $response->result;
            
            // Create payment record
            $payment = \Adichan\Payment\Models\PaymentTransaction::create([
                'gateway_id' => $this->model->id,
                'transaction_id' => $transaction->getId(),
                'gateway_transaction_id' => $order->id,
                'gateway_name' => $this->getName(),
                'amount' => $transaction->getTotal(),
                'currency' => strtoupper($options['currency'] ?? 'USD'),
                'status' => strtolower($order->status),
                'payment_method' => 'paypal',
                'payer_info' => $order->payer ? [
                    'payer_id' => $order->payer->payer_id ?? null,
                    'email' => $order->payer->email_address ?? null,
                    'name' => $order->payer->name ?? null,
                ] : null,
                'metadata' => [
                    'order' => json_decode(json_encode($order), true),
                    'options' => $options,
                    'links' => collect($order->links)->mapWithKeys(fn($link) => [$link->rel => $link->href])->toArray(),
                ],
            ]);
            
            // Find approval URL
            $approveLink = collect($order->links)->firstWhere('rel', 'approve');
            $redirectUrl = $approveLink->href ?? null;
            
            return new \Adichan\Payment\PaymentResponse(
                in_array($order->status, ['CREATED', 'APPROVED', 'COMPLETED']),
                $order->id,
                $redirectUrl,
                null,
                [
                    'order' => json_decode(json_encode($order), true),
                    'payment_id' => $payment->id,
                ],
                $order->status === 'CREATED',
                [
                    'approval_url' => $redirectUrl,
                    'order_id' => $order->id,
                ]
            );
            
        } catch (\Exception $e) {
            return new \Adichan\Payment\PaymentResponse(
                false,
                null,
                null,
                $e->getMessage(),
                ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]
            );
        }
    }
    
    public function verifyPayment(string $paymentId, array $data = []): PaymentVerificationInterface
    {
        try {
            $request = new OrdersGetRequest($paymentId);
            $response = $this->client->execute($request);
            $order = $response->result;
            
            $payment = \Adichan\Payment\Models\PaymentTransaction::where('gateway_transaction_id', $paymentId)->first();
            
            // Update payment status
            if ($payment) {
                $payment->update([
                    'status' => strtolower($order->status),
                    'payer_info' => $order->payer ? array_merge($payment->payer_info ?? [], [
                        'payer_id' => $order->payer->payer_id ?? null,
                        'email' => $order->payer->email_address ?? null,
                        'name' => $order->payer->name ?? null,
                    ]) : $payment->payer_info,
                ]);
                
                // If order is completed, capture payment
                if ($order->status === 'APPROVED') {
                    return $this->capturePayment($paymentId, $payment);
                }
                
                if ($order->status === 'COMPLETED') {
                    $payment->markAsVerified(['paypal_order' => json_decode(json_encode($order), true)]);
                    
                    if ($payment->transaction) {
                        $payment->transaction->complete();
                    }
                }
            }
            
            $isVerified = in_array($order->status, ['COMPLETED', 'APPROVED']);
            
            return new \Adichan\Payment\PaymentVerification(
                $isVerified,
                $payment?->transaction,
                $this->getName(),
                strtolower($order->status),
                $isVerified ? now() : null,
                ['paypal_order' => json_decode(json_encode($order), true)]
            );
            
        } catch (\Exception $e) {
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
    
    protected function capturePayment(string $orderId, $payment): PaymentVerificationInterface
    {
        try {
            $request = new OrdersCaptureRequest($orderId);
            $request->prefer('return=representation');
            
            $response = $this->client->execute($request);
            $capture = $response->result;
            
            // Update payment record
            $payment->update([
                'status' => strtolower($capture->status),
                'gateway_transaction_id' => $capture->id ?? $orderId,
                'metadata' => array_merge($payment->metadata ?? [], [
                    'capture' => json_decode(json_encode($capture), true),
                    'captured_at' => now()->toIso8601String(),
                ]),
            ]);
            
            if ($capture->status === 'COMPLETED') {
                $payment->markAsVerified(['paypal_capture' => json_decode(json_encode($capture), true)]);
                
                if ($payment->transaction) {
                    $payment->transaction->complete();
                }
            }
            
            return new \Adichan\Payment\PaymentVerification(
                $capture->status === 'COMPLETED',
                $payment->transaction,
                $this->getName(),
                strtolower($capture->status),
                now(),
                ['paypal_capture' => json_decode(json_encode($capture), true)]
            );
            
        } catch (\Exception $e) {
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
            // First, get the capture ID from the order
            $orderRequest = new OrdersGetRequest($paymentId);
            $orderResponse = $this->client->execute($orderRequest);
            $order = $orderResponse->result;
            
            // Find the capture ID
            $captureId = null;
            foreach ($order->purchase_units ?? [] as $unit) {
                foreach ($unit->payments->captures ?? [] as $capture) {
                    $captureId = $capture->id;
                    break 2;
                }
            }
            
            if (!$captureId) {
                throw new \RuntimeException('No capture found for this payment');
            }
            
            $request = new CapturesRefundRequest($captureId);
            
            if ($amount) {
                $request->body = [
                    'amount' => [
                        'value' => number_format($amount, 2, '.', ''),
                        'currency_code' => $order->purchase_units[0]->amount->currency_code ?? 'USD',
                    ]
                ];
            }
            
            $response = $this->client->execute($request);
            $refund = $response->result;
            
            // Update payment record
            $payment = \Adichan\Payment\Models\PaymentTransaction::where('gateway_transaction_id', $paymentId)->first();
            if ($payment) {
                $payment->update([
                    'status' => 'refunded',
                    'metadata' => array_merge($payment->metadata ?? [], [
                        'refund' => json_decode(json_encode($refund), true),
                        'refunded_at' => now()->toIso8601String(),
                    ]),
                ]);
            }
            
            return new \Adichan\Payment\PaymentResponse(
                $refund->status === 'COMPLETED',
                $refund->id,
                null,
                $refund->status !== 'COMPLETED' ? 'Refund failed' : null,
                ['paypal_refund' => json_decode(json_encode($refund), true)]
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
    
    public function supportsWebhook(): bool
    {
        return !empty($this->webhookId);
    }
    
    public function handleWebhook(array $payload): PaymentWebhookResultInterface
    {
        try {
            // Verify webhook signature
            $this->verifyWebhookSignature($payload);
            
            $eventType = $payload['event_type'] ?? '';
            $resource = $payload['resource'] ?? [];
            $orderId = $resource['id'] ?? $resource['order_id'] ?? null;
            
            if (!$orderId) {
                return new \Adichan\Payment\PaymentWebhookResult(
                    $eventType,
                    '',
                    $payload,
                    false,
                    ['error' => 'No order ID found in webhook payload']
                );
            }
            
            $payment = \Adichan\Payment\Models\PaymentTransaction::where('gateway_transaction_id', $orderId)->first();
            
            if ($payment) {
                $payment->update([
                    'webhook_received' => true,
                    'metadata' => array_merge($payment->metadata ?? [], [
                        'webhook_events' => array_merge($payment->metadata['webhook_events'] ?? [], [
                            [
                                'type' => $eventType,
                                'received_at' => now()->toIso8601String(),
                                'payload' => $payload,
                            ]
                        ])
                    ]),
                ]);
                
                $shouldProcess = in_array($eventType, [
                    'CHECKOUT.ORDER.APPROVED',
                    'CHECKOUT.ORDER.COMPLETED',
                    'PAYMENT.CAPTURE.COMPLETED',
                    'PAYMENT.CAPTURE.DENIED',
                    'PAYMENT.CAPTURE.REFUNDED',
                ]);
                
                // Handle different event types
                switch ($eventType) {
                    case 'CHECKOUT.ORDER.APPROVED':
                        // Auto-capture the payment
                        $this->capturePayment($orderId, $payment);
                        break;
                        
                    case 'CHECKOUT.ORDER.COMPLETED':
                    case 'PAYMENT.CAPTURE.COMPLETED':
                        $payment->update(['status' => 'completed']);
                        $payment->markAsVerified(['webhook_verified' => true]);
                        
                        if ($payment->transaction) {
                            $payment->transaction->complete();
                        }
                        break;
                        
                    case 'PAYMENT.CAPTURE.DENIED':
                        $payment->update(['status' => 'failed']);
                        $payment->markAsFailed('Payment denied by PayPal');
                        break;
                        
                    case 'PAYMENT.CAPTURE.REFUNDED':
                        $payment->update(['status' => 'refunded']);
                        break;
                }
                
                return new \Adichan\Payment\PaymentWebhookResult(
                    $eventType,
                    $orderId,
                    $payload,
                    $shouldProcess,
                    ['success' => true, 'payment_id' => $payment->id]
                );
            }
            
            return new \Adichan\Payment\PaymentWebhookResult(
                $eventType,
                $orderId,
                $payload,
                false,
                ['error' => 'Payment record not found']
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
    
    protected function verifyWebhookSignature(array $payload): void
    {
        // PayPal webhook verification requires additional setup
        // For production, implement proper signature verification
        // https://developer.paypal.com/docs/api/webhooks/v1/#verify-webhook-signature
        
        if (!isset($_SERVER['HTTP_PAYPAL_TRANSMISSION_ID']) || 
            !isset($_SERVER['HTTP_PAYPAL_TRANSMISSION_TIME']) ||
            !isset($_SERVER['HTTP_PAYPAL_TRANSMISSION_SIG']) ||
            !isset($_SERVER['HTTP_PAYPAL_CERT_URL']) ||
            !isset($_SERVER['HTTP_PAYPAL_AUTH_ALGO'])) {
            
            // In development, we might skip verification
            if (app()->environment('production')) {
                throw new \RuntimeException('Missing PayPal webhook verification headers');
            }
        }
    }
    
    protected function buildOrderItems(TransactionInterface $transaction, array $options): array
    {
        $items = [];
        
        // If transaction has items, build detailed item list
        if (method_exists($transaction, 'getItems')) {
            $transactionItems = $transaction->getItems();
            
            foreach ($transactionItems as $item) {
                $items[] = [
                    'name' => $item->itemable->getName() ?? 'Item',
                    'description' => $item->itemable->getDescription() ?? '',
                    'quantity' => (string) $item->quantity,
                    'unit_amount' => [
                        'currency_code' => strtoupper($options['currency'] ?? 'USD'),
                        'value' => number_format($item->price_at_time, 2, '.', ''),
                    ],
                    'category' => 'PHYSICAL_GOODS',
                ];
            }
        } else {
            // Fallback to single item
            $items[] = [
                'name' => $options['description'] ?? 'Transaction #' . $transaction->getId(),
                'description' => 'Payment for transaction',
                'quantity' => '1',
                'unit_amount' => [
                    'currency_code' => strtoupper($options['currency'] ?? 'USD'),
                    'value' => number_format($transaction->getTotal(), 2, '.', ''),
                ],
                'category' => 'DIGITAL_GOODS',
            ];
        }
        
        return $items;
    }
    
    /**
     * Get PayPal access token (for direct API calls if needed)
     */
    public function getAccessToken(): string
    {
        // Implementation for getting access token
        // This is handled automatically by the SDK
        return '';
    }
}
