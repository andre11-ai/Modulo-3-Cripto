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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\StripeClient;

class PaymentController extends Controller
{
    public function __construct(
        private readonly M1AuthService $m1Auth,
        private readonly M2BookingService $m2Booking,
        private readonly EcdhSessionService $ecdhService
    ) {}

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
                    'remainingSeconds' => $reservation['remainingSeconds'] ?? 180,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

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

            $decrypted = $this->ecdhService->decryptOrderMetadata(
                $request->string('sessionId')->toString(),
                $request->string('clientPublicKey')->toString(),
                $request->string('iv')->toString(),
                $request->string('ciphertext')->toString(),
                $buyerId
            );

            $metadata = $decrypted['metadata'];
            $reservationId = $decrypted['reservation_id'];

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
                    'expires_at' => $reservation['expiresAt'] ?? now()->utc()->addMinutes(3),
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

    public function reserveSeat(Request $request): JsonResponse
    {
        $request->validate([
            'seatId' => ['required', 'string', 'max:32'],
        ]);

        try {
            $buyerId = $request->header('X-Mock-Buyer-Id');
            if (!$buyerId) {
                $claims = $this->m1Auth->validateBuyerToken($request->header('Authorization'));
                $buyerId = $claims['sub'];
            }

            $reservation = $this->m2Booking->lockSeatAtomically(
                $request->string('seatId')->toString(),
                $buyerId
            );

            return response()->json([
                'status' => 'ok',
                'reservation' => $reservation,
            ], 200);
        } catch (Exception $e) {
            $code = in_array($e->getCode(), [404, 409], true) ? $e->getCode() : 400;
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], $code);
        }
    }

    public function expireSeat(Request $request): JsonResponse
    {
        $request->validate([
            'reservationId' => ['required', 'string', 'max:64'],
        ]);

        $this->m2Booking->releaseSeatByReservation(
            $request->string('reservationId')->toString(),
            'timeout_expirado'
        );

        return response()->json(['status' => 'released']);
    }

public function lookupBin(string $bin): JsonResponse
    {
        $cleanBin = preg_replace('/\D/', '', $bin);
        if (strlen($cleanBin) < 6) {
            return response()->json(['status' => 'error', 'message' => 'Se requieren al menos 6 digitos del BIN.'], 422);
        }

        $bin6 = substr($cleanBin, 0, 6);

        $sandboxBins = [
            '424242' => ['scheme' => 'VISA', 'type' => 'CREDIT', 'tier' => 'CLASSIC', 'bank' => 'Stripe Sandbox (Aprobada)', 'country' => 'United States'],
            '400000' => ['scheme' => 'VISA', 'type' => 'DEBIT', 'tier' => 'STANDARD', 'bank' => 'Stripe Sandbox (Sin fondos / Rechazo)', 'country' => 'United States'],
            '555555' => ['scheme' => 'MASTERCARD', 'type' => 'CREDIT', 'tier' => 'STANDARD', 'bank' => 'Stripe Sandbox', 'country' => 'United States'],
        ];

        if (isset($sandboxBins[$bin6])) {
            $d = $sandboxBins[$bin6];
            Log::channel('crypto_tunnel')->info("\n[VALIDADOR BIN] BIN {$bin6} -> {$d['bank']} | {$d['scheme']} {$d['type']} | {$d['country']}");
            return response()->json(['status' => 'ok', 'bin' => $bin6, 'data' => $d]);
        }

        try {
            $res = Http::withoutVerifying()->timeout(6)->get("https://data.handyapi.com/bin/{$bin6}");
            if ($res->successful() && ($res->json('Status') === 'SUCCESS')) {
                $json = $res->json();
                $result = [
                    'scheme' => strtoupper($json['Scheme'] ?? 'DESCONOCIDA'),
                    'type' => strtoupper($json['Type'] ?? 'DESCONOCIDO'),
                    'tier' => strtoupper($json['CardTier'] ?? 'ESTANDAR'),
                    'bank' => $json['Issuer'] ?: 'Banco emisor no especificado',
                    'country' => $json['Country']['Name'] ?? 'N/A',
                ];

                Log::channel('crypto_tunnel')->info(
                    "\n[VALIDADOR BIN REAL] BIN {$bin6} -> Banco: {$result['bank']} | Red: {$result['scheme']} {$result['type']} ({$result['tier']}) | Pais: {$result['country']}"
                );
                return response()->json(['status' => 'ok', 'bin' => $bin6, 'data' => $result]);
            }
        } catch (Exception $e) {
            //
        }

        try {
            $res = Http::withoutVerifying()->timeout(6)
                ->withHeaders(['Accept-Version' => '3'])
                ->get("https://lookup.binlist.net/{$bin6}");

            if ($res->successful()) {
                $json = $res->json();
                $result = [
                    'scheme' => strtoupper($json['scheme'] ?? 'DESCONOCIDA'),
                    'type' => strtoupper($json['type'] ?? 'DESCONOCIDO'),
                    'tier' => strtoupper($json['brand'] ?? 'ESTANDAR'),
                    'bank' => $json['bank']['name'] ?? 'Banco emisor no especificado',
                    'country' => $json['country']['name'] ?? 'N/A',
                ];

                Log::channel('crypto_tunnel')->info(
                    "\n[VALIDADOR BIN REAL] BIN {$bin6} -> Banco: {$result['bank']} | Red: {$result['scheme']} {$result['type']} | Pais: {$result['country']}"
                );
                return response()->json(['status' => 'ok', 'bin' => $bin6, 'data' => $result]);
            }
        } catch (Exception $e) {
            //
        }

        return response()->json([
            'status' => 'error',
            'message' => 'BIN no encontrado en los catalogos bancarios publicos.',
        ], 404);
    }
}
