<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrdenPago;
use App\Models\TransaccionPago;
use App\Services\Crypto\EcdhSessionService;
use App\Services\Integrations\M1AuthService;
use App\Services\Integrations\M2BookingService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\StripeClient;

class PaymentController extends Controller
{
    public function __construct(
        private readonly M1AuthService $m1Auth,
        private readonly M2BookingService $m2Booking,
        private readonly EcdhSessionService $ecdhService
    ) {}

    /**
     * Fase 1: Handshake ECDH P-256
     * POST /api/pagos/sesion
     */
    public function createSession(Request $request): JsonResponse
    {
        $request->validate([
            'reservationId' => ['required', 'string', 'max:64'],
        ]);

        try {
            $claims = $this->m1Auth->validateBuyerToken($request->header('Authorization'));
            $buyerId = $claims['sub'];

            $reservation = $this->m2Booking->getActiveReservation(
                $request->string('reservationId')->toString(),
                $buyerId
            );

            $sessionData = $this->ecdhService->createSession($buyerId, $reservation['reservationId']);

            return response()->json([
                'status' => 'ok',
                'session' => $sessionData,
                'reservationSummary' => [
                    'reservationId' => $reservation['reservationId'],
                    'eventName' => $reservation['eventName'],
                    'venue' => $reservation['venue'],
                    'seatId' => $reservation['seatId'],
                    'amountMinor' => $reservation['amountMinor'],
                    'currency' => $reservation['currency'],
                    'expiresAt' => $reservation['expiresAt'],
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Fase 3: Descifrado AES-256-GCM + Creación de Orden y PaymentIntent en Stripe
     * POST /api/pagos/intentos
     */
    public function createIntent(Request $request): JsonResponse
    {
        $request->validate([
            'sessionId' => ['required', 'string'],
            'clientPublicKey' => ['required', 'string'],
            'iv' => ['required', 'string'],
            'ciphertext' => ['required', 'string'],
        ]);

        try {
            $claims = $this->m1Auth->validateBuyerToken($request->header('Authorization'));
            $buyerId = $claims['sub'];

            // Descifra metadatos de la orden y destruye la llave privada efímera del servidor
            $decrypted = $this->ecdhService->decryptOrderMetadata(
                $request->string('sessionId')->toString(),
                $request->string('clientPublicKey')->toString(),
                $request->string('iv')->toString(),
                $request->string('ciphertext')->toString(),
                $buyerId
            );

            $metadata = $decrypted['metadata'];
            $reservationId = $decrypted['reservation_id'];

            // El monto oficial SIEMPRE se calcula en servidor consultando a M2
            $reservation = $this->m2Booking->getActiveReservation($reservationId, $buyerId);

            $orderId = 'ord_' . Str::ulid();
            $paymentId = 'pay_' . Str::ulid();

            $stripeSecret = config('modulo3.stripe.secret');
            if (!$stripeSecret || str_contains($stripeSecret, 'aqui_tu_llave')) {
                throw new Exception('Configura tu STRIPE_SECRET de pruebas en el archivo .env.');
            }

            $stripe = new StripeClient($stripeSecret);

            $paymentIntent = $stripe->paymentIntents->create([
                'amount' => $reservation['amountMinor'],
                'currency' => strtolower($reservation['currency']),
                'automatic_payment_methods' => ['enabled' => true],
                'receipt_email' => $metadata['buyerEmail'] ?? null,
                'metadata' => [
                    'order_id' => $orderId,
                    'payment_id' => $paymentId,
                    'reservation_id' => $reservation['reservationId'],
                    'buyer_id' => $buyerId,
                    'event_id' => $reservation['eventId'],
                    'seat_id' => $reservation['seatId'],
                ],
            ]);

            DB::transaction(function () use ($orderId, $paymentId, $reservation, $buyerId, $metadata, $paymentIntent) {
                OrdenPago::create([
                    'id' => $orderId,
                    'reservation_id' => $reservation['reservationId'],
                    'buyer_id' => $buyerId,
                    'event_id' => $reservation['eventId'],
                    'seat_id' => $reservation['seatId'],
                    'amount_minor' => $reservation['amountMinor'],
                    'currency' => strtoupper($reservation['currency']),
                    'status' => 'PENDING',
                    'buyer_email' => $metadata['buyerEmail'] ?? null,
                    'expires_at' => $reservation['expiresAt'] ?? now()->addMinutes(10),
                ]);

                TransaccionPago::create([
                    'id' => $paymentId,
                    'orden_pago_id' => $orderId,
                    'stripe_payment_intent_id' => $paymentIntent->id,
                    'status' => 'PENDING',
                    'gateway_metadata' => [
                        'stripe_status' => $paymentIntent->status,
                        'ecdh_verified' => true,
                    ],
                ]);
            });

            return response()->json([
                'status' => 'ok',
                'orderId' => $orderId,
                'paymentId' => $paymentId,
                'clientSecret' => $paymentIntent->client_secret,
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}   
