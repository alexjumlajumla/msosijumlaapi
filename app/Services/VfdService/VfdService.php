<?php

namespace App\Services\VfdService;

use App\Models\VfdReceipt;
use App\Services\CoreService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class VfdService extends CoreService
{
    protected string $baseUrl;
    protected string $apiKey;
    protected string $tin;
    protected string $certPath;

    public function __construct()
    {
        parent::__construct();
        
        $this->baseUrl = config('services.vfd.base_url');
        $this->apiKey = config('services.vfd.api_key');
        $this->tin = config('services.vfd.tin');
        $this->certPath = config('services.vfd.cert_path');
    }

    /**
     * Generate a fiscal receipt for delivery or subscription
     *
     * @param string $type
     * @param array $data
     * @return array
     */
    public function generateReceipt(string $type, array $data): array
    {
        try {
            // Create VFD receipt record
            $receipt = VfdReceipt::create([
                'receipt_type' => $type,
                'model_id' => $data['model_id'],
                'model_type' => $data['model_type'],
                'amount' => $data['amount'],
                'payment_method' => $data['payment_method'],
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'customer_email' => $data['customer_email'] ?? null,
                'receipt_number' => $this->generateReceiptNumber(),
                'status' => VfdReceipt::STATUS_PENDING
            ]);

            // Prepare VFD API request payload
            $payload = [
                'tin' => $this->tin,
                'receiptNumber' => $receipt->receipt_number,
                'amount' => $receipt->amount,
                'paymentMethod' => $this->mapPaymentMethod($receipt->payment_method),
                'customerDetails' => [
                    'name' => $receipt->customer_name,
                    'phone' => $receipt->customer_phone,
                    'email' => $receipt->customer_email
                ],
                'items' => [
                    [
                        'description' => $type === VfdReceipt::TYPE_DELIVERY ? 'Delivery Fee' : 'Subscription Fee',
                        'quantity' => 1,
                        'amount' => $receipt->amount
                    ]
                ]
            ];

            // Make API request to VFD
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json'
            ])->post("{$this->baseUrl}/receipts/generate", $payload);

            if ($response->successful()) {
                $responseData = $response->json();
                
                $receipt->update([
                    'receipt_url' => $responseData['receiptUrl'] ?? null,
                    'vfd_response' => json_encode($responseData),
                    'status' => VfdReceipt::STATUS_GENERATED
                ]);

                // Send SMS with receipt URL if phone is available
                if ($receipt->customer_phone) {
                    $this->sendReceiptSms($receipt);
                }

                return [
                    'status' => true,
                    'message' => 'Receipt generated successfully',
                    'data' => $receipt
                ];
            }

            $receipt->update([
                'status' => VfdReceipt::STATUS_FAILED,
                'error_message' => $response->body()
            ]);

            return [
                'status' => false,
                'message' => 'Failed to generate receipt',
                'error' => $response->body()
            ];

        } catch (Exception $e) {
            Log::error('VFD Receipt Generation Error: ' . $e->getMessage(), [
                'type' => $type,
                'data' => $data,
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'status' => false,
                'message' => 'Error generating receipt',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Generate a unique receipt number
     */
    protected function generateReceiptNumber(): string
    {
        return 'VFD-' . time() . '-' . rand(1000, 9999);
    }

    /**
     * Map internal payment method to VFD payment method
     */
    protected function mapPaymentMethod(string $method): string
    {
        return match (strtolower($method)) {
            'cash' => 'CASH',
            'card' => 'CARD',
            'bank_transfer' => 'BANK',
            default => 'OTHER'
        };
    }

    /**
     * Send receipt URL via SMS
     */
    protected function sendReceiptSms(VfdReceipt $receipt): void
    {
        try {
            $message = "Your fiscal receipt is ready. View it here: {$receipt->receipt_url}";
            
            // Use existing SMS service
            $smsGateway = \App\Models\SmsGateway::where('active', 1)->first();
            if ($smsGateway) {
                // Implement SMS sending logic here using your existing SMS gateway
                // This should match your current SMS implementation
            }
        } catch (Exception $e) {
            Log::error('Failed to send receipt SMS: ' . $e->getMessage());
        }
    }
} 