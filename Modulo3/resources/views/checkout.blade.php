<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout Seguro | Auditorio Central</title>
    <link rel="stylesheet" href="{{ asset('css/checkout.css') }}">
    <script src="https://js.stripe.com/v3/"></script>
</head>
<body>
    <div class="checkout-shell">
        <header class="topbar">
            <div class="brand">
                <span class="brand-dot"></span>
                <span>Auditorio Central</span>
                <span class="badge-module">Módulo 3 · Pagos</span>
            </div>
            <div class="security-pill">
                <span class="lock-icon">🔒</span>
                <span>Túnel ECDH P-256 + PCI-DSS</span>
            </div>
        </header>

        <main class="checkout-grid">
            <section class="card summary-card">
                <div class="card-header">
                    <p class="eyebrow">Resumen de tu reserva</p>
                    <h1 id="event-name">Cargando evento...</h1>
                    <p class="venue" id="event-venue">Auditorio Central</p>
                </div>

                <div class="summary-details">
                    <div class="detail-row">
                        <span>ID de Reserva</span>
                        <strong id="reservation-id">{{ $reservationId }}</strong>
                    </div>
                    <div class="detail-row">
                        <span>Asiento asignado</span>
                        <strong id="seat-id">--</strong>
                    </div>
                    <div class="detail-row">
                        <span>Estado del bloqueo</span>
                        <span class="status-tag" id="lock-status">Verificando...</span>
                    </div>
                </div>

                <div class="total-box">
                    <span>Total a pagar</span>
                    <div class="total-amount" id="total-amount">$0.00 MXN</div>
                </div>

                <div class="crypto-panel">
                    <h3>Estado del Túnel Criptográfico</h3>
                    <ul class="crypto-steps">
                        <li id="step-ecdh" class="pending">1. Handshake efímero ECDH (P-256)</li>
                        <li id="step-hkdf" class="pending">2. Derivación HKDF-SHA256 + AES-256-GCM</li>
                        <li id="step-stripe" class="pending">3. PaymentIntent y Stripe Element (PCI-DSS)</li>
                    </ul>
                </div>
            </section>

            <section class="card payment-card">
                <h2>Finalizar compra</h2>
                <p class="subtitle">
                    Tus metadatos viajan cifrados punto a punto. Los datos de tu tarjeta se envían directo a Stripe sin pasar por nuestro servidor.
                </p>

                <form id="metadata-form" class="form-block">
                    <div class="input-group">
                        <label for="buyer-email">Correo electrónico para recibir tu boleto</label>
                        <input
                            type="email"
                            id="buyer-email"
                            name="buyer_email"
                            placeholder="ejemplo@correo.com"
                            value="comprador@auditorio.mx"
                            required
                        >
                    </div>
                    <button type="submit" id="btn-init-payment" class="btn-primary">
                        Cifrar orden y continuar al pago
                    </button>
                </form>

                <form id="stripe-form" class="form-block hidden">
                    <div id="payment-element"></div>
                    <button type="submit" id="btn-confirm-payment" class="btn-primary">
                        Pagar ahora
                    </button>
                </form>

                <div id="status-message" class="alert hidden"></div>
            </section>
        </main>
    </div>

    <script>
        window.M3_CONFIG = {
            stripeKey: @json($stripeKey),
            reservationId: @json($reservationId),
            apiBase: '/api/pagos'
        };
    </script>
    <script src="{{ asset('js/checkout.js') }}"></script>
</body>
</html>
