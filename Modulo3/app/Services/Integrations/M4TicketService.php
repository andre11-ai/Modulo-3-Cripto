<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class M4TicketService
{
    public function notifyPaymentConfirmed(array $data): void
    {
        $payload = [
            'event' => 'payment.confirmed',
            'sourceModule' => 'M3_PAYMENTS',
            'targetModule' => 'M4_TICKET_ISSUANCE',
            'payload' => [
                'paymentId' => $data['paymentId'],
                'orderId' => $data['orderId'],
                'reservationId' => $data['reservationId'],
                'buyerId' => $data['buyerId'],
                'amountMinor' => $data['amountMinor'],
                'currency' => $data['currency'],
                'paymentStatus' => 'PAID',
                'paidAt' => $data['paidAt'],
            ],
        ];

        if (config('modulo3.use_mocks')) {
            Log::info('[MOCK -> M4_TICKET_ISSUANCE] Emisión de boleto disparada:', $payload);
            return;
        }

        $url = rtrim(config('modulo3.services.m4_api_url'), '/') . '/webhooks/payment-confirmed';
        Http::timeout(5)->post($url, $payload);
    }
}
