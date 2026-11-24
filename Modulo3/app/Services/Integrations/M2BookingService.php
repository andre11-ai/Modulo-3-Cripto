<?php

namespace App\Services\Integrations;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class M2BookingService
{
    public function getActiveReservation(string $reservationId, string $buyerId): array
    {
        if (config('modulo3.use_mocks')) {
            return [
                'reservationId' => $reservationId,
                'buyerId' => $buyerId,
                'status' => 'LOCKED',
                'amountMinor' => 125000, 
                'currency' => 'MXN',
                'eventId' => 'evt_2026_001',
                'seatId' => 'A-12',
                'eventName' => 'Noche de concierto',
                'venue' => 'Auditorio Central',
                'eventStartsAt' => '2030-01-01T19:00:00Z',
                'eventEndsAt' => '2030-01-01T22:00:00Z',
                'expiresAt' => now()->addMinutes(10)->toIso8601String(),
            ];
        }

        $url = rtrim(config('modulo3.services.m2_api_url'), '/') . "/reservations/{$reservationId}";
        $response = Http::timeout(5)->get($url, ['buyerId' => $buyerId]);

        if ($response->failed()) {
            throw new Exception('La reserva no existe, expiró o no pertenece al comprador.');
        }

        return $response->json();
    }

    public function notifyPaymentFailedOrExpired(array $data): void
    {
        $payload = [
            'event' => 'payment.failed_or_expired',
            'sourceModule' => 'M3_PAYMENTS',
            'targetModule' => 'M2_BOOKING',
            'payload' => [
                'paymentId' => $data['paymentId'],
                'orderId' => $data['orderId'],
                'reservationId' => $data['reservationId'],
                'eventId' => $data['eventId'],
                'seatId' => $data['seatId'],
                'paymentStatus' => $data['paymentStatus'],
                'failureReason' => $data['failureReason'],
                'occurredAt' => now()->utc()->toIso8601String(),
            ],
        ];

        if (config('modulo3.use_mocks')) {
            Log::info('[MOCK -> M2_BOOKING] Liberación de asiento disparada:', $payload);
            return;
        }

        $url = rtrim(config('modulo3.services.m2_api_url'), '/') . '/webhooks/payment-failed';
        Http::timeout(5)->post($url, $payload);
    }
}
