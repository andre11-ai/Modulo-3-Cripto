<?php

namespace App\Services\Integrations;

use App\Models\MockAsiento;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class M2BookingService
{
    public const LOCK_MINUTES = 3;

    public function lockSeatAtomically(string $seatId, string $buyerId): array
    {
        return DB::transaction(function () use ($seatId, $buyerId) {
            $asiento = MockAsiento::where('seat_id', $seatId)
                ->lockForUpdate()
                ->first();

            if (!$asiento) {
                throw new Exception("El asiento {$seatId} no existe.", 404);
            }

            if ($asiento->status === 'LOCKED' && $asiento->locked_until && $asiento->locked_until->getTimestamp() <= time()) {
                $asiento->update([
                    'status' => 'FREE',
                    'reservation_id' => null,
                    'locked_by' => null,
                    'locked_until' => null,
                ]);
            }

            if ($asiento->status === 'SOLD') {
                throw new Exception("El asiento {$seatId} ya fue vendido.", 409);
            }

            if ($asiento->status === 'LOCKED' && $asiento->locked_by !== $buyerId) {
                throw new Exception("El asiento {$seatId} esta ocupado temporalmente por otro comprador.", 409);
            }

            if ($asiento->status === 'LOCKED' && $asiento->locked_by === $buyerId) {
                return $this->formatReservation($asiento);
            }

            $reservationId = 'res_' . Str::ulid();
            $expiresAt = now()->addMinutes(self::LOCK_MINUTES);

            $asiento->update([
                'status' => 'LOCKED',
                'reservation_id' => $reservationId,
                'locked_by' => $buyerId,
                'locked_until' => $expiresAt,
            ]);

            Log::channel('crypto_tunnel')->info(
                "\n[BLOQUEO ATOMICO M2] Asiento {$seatId} apartado para {$buyerId} por " . self::LOCK_MINUTES . " minutos ({$reservationId})"
            );

            return $this->formatReservation($asiento->fresh());
        });
    }

    public function getActiveReservation(string $reservationId, string $buyerId): array
    {
        if (config('modulo3.use_mocks')) {
            $asiento = MockAsiento::where('reservation_id', $reservationId)->first();

            if (!$asiento) {
                throw new Exception('La reserva no existe o ya fue liberada.');
            }

            if ($asiento->status === 'SOLD') {
                throw new Exception('Esta reserva ya fue pagada.');
            }

            if ($asiento->locked_until && $asiento->locked_until->getTimestamp() <= time()) {
                $asiento->update([
                    'status' => 'FREE',
                    'reservation_id' => null,
                    'locked_by' => null,
                    'locked_until' => null,
                ]);
                throw new Exception('El tiempo de retencion del asiento expiro. El asiento fue liberado.');
            }

            if ($asiento->locked_by !== $buyerId) {
                throw new Exception('Esta reserva pertenece a otro comprador.');
            }

            return $this->formatReservation($asiento);
        }

        $url = rtrim(config('modulo3.services.m2_api_url'), '/') . "/reservations/{$reservationId}";
        $response = Http::timeout(5)->get($url, ['buyerId' => $buyerId]);

        if ($response->failed()) {
            throw new Exception('La reserva no existe, expiro o no pertenece al comprador.');
        }

        return $response->json();
    }

    public function markSeatAsSold(string $reservationId): void
    {
        if (config('modulo3.use_mocks')) {
            MockAsiento::where('reservation_id', $reservationId)->update([
                'status' => 'SOLD',
                'locked_until' => null,
            ]);
            Log::channel('crypto_tunnel')->info("\n[VENTA CONFIRMADA M2] Reserva {$reservationId} marcada como VENDIDA (SOLD).");
        }
    }

    public function releaseSeatByReservation(string $reservationId, string $reason = 'expired'): void
    {
        if (config('modulo3.use_mocks')) {
            $asiento = MockAsiento::where('reservation_id', $reservationId)
                ->where('status', 'LOCKED')
                ->first();

            if ($asiento) {
                $asiento->update([
                    'status' => 'FREE',
                    'reservation_id' => null,
                    'locked_by' => null,
                    'locked_until' => null,
                ]);
                Log::channel('crypto_tunnel')->info("\n[ASIENTO LIBERADO M2] Asiento {$asiento->seat_id} vuelve a estar LIBRE (Motivo: {$reason}).");
            }
        }
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
            if ($data['paymentStatus'] === 'EXPIRED') {
                $this->releaseSeatByReservation($data['reservationId'], $data['failureReason']);
            }
            Log::info('[MOCK -> M2_BOOKING] Notificacion de fallo/expiracion:', $payload);
            Log::channel('crypto_tunnel')->info("\n[WEBHOOK -> M2] Pago {$data['paymentStatus']} ({$data['failureReason']}). Si fue por fondos insuficientes, el asiento sigue retenido hasta que venza el reloj.");
            return;
        }

        $url = rtrim(config('modulo3.services.m2_api_url'), '/') . '/webhooks/payment-failed';
        Http::timeout(5)->post($url, $payload);
    }

    private function formatReservation(MockAsiento $asiento): array
    {
        $remainingSeconds = $asiento->locked_until
            ? max(0, $asiento->locked_until->getTimestamp() - time())
            : 0;

        return [
            'reservationId' => $asiento->reservation_id,
            'buyerId' => $asiento->locked_by,
            'status' => $asiento->status,
            'amountMinor' => $asiento->amount_minor,
            'currency' => $asiento->currency,
            'eventId' => $asiento->event_id,
            'seatId' => $asiento->seat_id,
            'eventName' => 'Noche de concierto',
            'venue' => 'Auditorio Central',
            'eventStartsAt' => '2030-01-01T19:00:00Z',
            'eventEndsAt' => '2030-01-01T22:00:00Z',
            'expiresAt' => $asiento->locked_until?->toIso8601String(),
            'remainingSeconds' => $remainingSeconds,
        ];
    }
}
