<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EventoWebhook;
use App\Models\OrdenPago;
use App\Models\TransaccionPago;
use App\Services\Integrations\M2BookingService;
use App\Services\Integrations\M4TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly M2BookingService $m2Booking,
        private readonly M4TicketService $m4Ticket
    ) {}


    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('modulo3.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, (string) $sigHeader, (string) $webhookSecret);
        } catch (UnexpectedValueException $e) {
            return response()->json(['error' => 'Payload inválido'], 400);
        } catch (SignatureVerificationException $e) {
            return response()->json(['error' => 'Firma HMAC-SHA256 inválida'], 400);
        }

        $existingEvent = EventoWebhook::where('stripe_event_id', $event->id)->first();
        if ($existingEvent && $existingEvent->processed) {
            return response()->json(['status' => 'already_processed'], 200);
        }

        $paymentIntent = $event->data->object;
        $paymentIntentId = $paymentIntent->id ?? null;

        $webhookRecord = $existingEvent ?? EventoWebhook::create([
            'stripe_event_id' => $event->id,
            'event_type' => $event->type,
            'stripe_payment_intent_id' => $paymentIntentId,
            'payload' => json_decode($payload, true),
            'processed' => false,
        ]);

        if ($event->type === 'payment_intent.succeeded') {
            $this->handlePaymentSucceeded($paymentIntentId, $webhookRecord);
        } elseif (in_array($event->type, ['payment_intent.payment_failed', 'payment_intent.canceled'], true)) {
            $reason = $event->type;
            $status = $event->type === 'payment_intent.canceled' ? 'EXPIRED' : 'FAILED';
            $this->handlePaymentFailed($paymentIntentId, $status, $reason, $webhookRecord);
        } else {
            $webhookRecord->update([
                'processed' => true,
                'processed_at' => now(),
            ]);
        }

        return response()->json(['status' => 'received'], 200);
    }

    private function handlePaymentSucceeded(?string $paymentIntentId, EventoWebhook $webhookRecord): void
    {
        $transaccion = TransaccionPago::where('stripe_payment_intent_id', $paymentIntentId)->first();
        if (!$transaccion) {
            return;
        }

        $orden = OrdenPago::find($transaccion->orden_pago_id);
        if (!$orden) {
            return;
        }

        $paidAt = now()->utc();

        DB::transaction(function () use ($orden, $transaccion, $webhookRecord, $paidAt) {
            $orden->update([
                'status' => 'PAID',
                'paid_at' => $paidAt,
            ]);

            $transaccion->update([
                'status' => 'PAID',
            ]);

            $webhookRecord->update([
                'processed' => true,
                'processed_at' => now(),
            ]);
        });

        $this->m4Ticket->notifyPaymentConfirmed([
            'paymentId' => $transaccion->id,
            'orderId' => $orden->id,
            'reservationId' => $orden->reservation_id,
            'buyerId' => $orden->buyer_id,
            'amountMinor' => $orden->amount_minor,
            'currency' => $orden->currency,
            'paidAt' => $paidAt->toIso8601String(),
        ]);
    }

    private function handlePaymentFailed(
        ?string $paymentIntentId,
        string $newStatus,
        string $reason,
        EventoWebhook $webhookRecord
    ): void {
        $transaccion = TransaccionPago::where('stripe_payment_intent_id', $paymentIntentId)->first();
        if (!$transaccion) {
            return;
        }

        $orden = OrdenPago::find($transaccion->orden_pago_id);
        if (!$orden) {
            return;
        }

        DB::transaction(function () use ($orden, $transaccion, $newStatus, $reason, $webhookRecord) {
            $orden->update(['status' => $newStatus]);
            $transaccion->update([
                'status' => $newStatus,
                'failure_reason' => $reason,
            ]);
            $webhookRecord->update([
                'processed' => true,
                'processed_at' => now(),
            ]);
        });

        $this->m2Booking->notifyPaymentFailedOrExpired([
            'paymentId' => $transaccion->id,
            'orderId' => $orden->id,
            'reservationId' => $orden->reservation_id,
            'eventId' => $orden->event_id,
            'seatId' => $orden->seat_id,
            'paymentStatus' => $newStatus,
            'failureReason' => $reason,
        ]);
    }
}
