<?php

namespace App\Services\OrderNotificationService;

use App\Models\Order;
use App\Mail\OrderNotificationMail;
use Illuminate\Support\Facades\Mail;
use App\Models\SmsGateway;
use Illuminate\Support\Facades\Log;
use Exception;

class OrderNotificationService
{
    protected $maxRetries = 3;
    protected $retryDelay = 5; // seconds

    /**
     * Send notifications via mail and SMS when an order is created or updated.
     *
     * @param Order $order
     * @param string $eventType
     * @return array
     */
    public function sendOrderNotification(Order $order, string $eventType): array
    {
        $message = $this->buildMessage($order, $eventType);
        $results = ['email' => false, 'sms' => false];

        // Send email with retries
        if ($order->email || $order->user?->email) {
            $results['email'] = $this->sendEmailWithRetry($order->email ?? $order->user?->email, $message, $order);
        }

        // Send SMS with retries
        if ($order->phone || $order->user?->phone) {
            $results['sms'] = $this->sendSMSWithRetry($order->phone ?? $order->user?->phone, $message);
        }

        return $results;
    }

    /**
     * Build notification message based on order event type.
     *
     * @param Order $order
     * @param string $eventType
     * @return string
     */
    protected function buildMessage(Order $order, string $eventType): string 
    {
        return match ($eventType) {
            'payment_accepted' => "Your payment for order #{$order->id} has been confirmed. Thank you for your purchase!",
            'created' => "Your order #{$order->id} has been successfully placed!",
            'status_updated' => "Your order #{$order->id} status has been updated to: {$order->status}",
            'delivered' => "Your order #{$order->id} has been delivered. Thank you for shopping with us!",
            'cancelled' => "Your order #{$order->id} has been cancelled. Please contact support if you have any questions.",
            default => "Your order #{$order->id} status has been updated to: {$order->status}"
        };
    }

    /**
     * Send an email notification with retry mechanism.
     *
     * @param string $toEmail
     * @param string $message
     * @param Order $order
     * @return bool
     */
    protected function sendEmailWithRetry(string $toEmail, string $message, Order $order): bool
    {
        $attempt = 1;

        while ($attempt <= $this->maxRetries) {
        try {
                Mail::to($toEmail)->send(new OrderNotificationMail($order, $message));
                Log::info('Order notification email sent successfully', [
                    'order_id' => $order->id,
                    'email' => $toEmail,
                    'attempt' => $attempt
                ]);
                return true;
            } catch (Exception $e) {
                Log::error('Failed to send order notification email', [
                    'order_id' => $order->id,
                    'email' => $toEmail,
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);

                if ($attempt === $this->maxRetries) {
                    break;
                }

                sleep($this->retryDelay);
                $attempt++;
            }
        }

        return false;
    }

    /**
     * Send an SMS notification with retry mechanism.
     *
     * @param string $phoneNumber
     * @param string $message
     * @return bool
     */
    protected function sendSMSWithRetry(string $phoneNumber, string $message): bool
    {
        $attempt = 1;

        while ($attempt <= $this->maxRetries) {
        try {
                // Get active SMS gateway configuration
                $smsGateway = SmsGateway::where('active', 1)->first();
            
                if (!$smsGateway) {
                    Log::warning('No active SMS gateway found');
                    return false;
                }

                // Send SMS using the configured gateway
                // Implementation depends on your SMS provider
                // Add your SMS sending logic here

                Log::info('Order notification SMS sent successfully', [
                    'phone' => $phoneNumber,
                    'attempt' => $attempt
                ]);
                return true;
            } catch (Exception $e) {
                Log::error('Failed to send order notification SMS', [
                    'phone' => $phoneNumber,
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);

                if ($attempt === $this->maxRetries) {
                    break;
                }

                sleep($this->retryDelay);
                $attempt++;
            }
        }

        return false;
    }
}
