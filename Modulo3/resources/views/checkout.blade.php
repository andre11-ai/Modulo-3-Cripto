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
                    <h1 id="event-name">Noche de concierto</h1>
                    <p class="venue" id="event-venue">Auditorio Central</p>
                </div>

                <div class="crypto-panel" style="margin-bottom: 18px;">
                    <h3>Selecciona un asiento</h3>
                    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px;">
                        @foreach($asientos as $a)
                            @php
                                $isSelected = $a->seat_id === $seatId;
                                $isSold = $a->status === 'SOLD';
                                $bg = $isSold ? 'rgba(239,68,68,0.18)' : ($isSelected ? '#3b82f6' : 'rgba(15,23,42,0.7)');
                                $border = $isSold ? '#ef4444' : ($isSelected ? '#60a5fa' : 'rgba(148,163,184,0.2)');
                                $label = $isSold ? 'Ocupado' : ($a->status === 'LOCKED' ? 'Apartado' : 'Libre');
                            @endphp
                            <a href="{{ route('checkout.show', ['seat' => $a->seat_id]) }}"
                               style="text-decoration: none; text-align: center; padding: 10px 6px; border-radius: 8px; background: {{ $bg }}; border: 1px solid {{ $border }}; color: #f8fafc;">
                                <div style="font-weight: 700; font-size: 0.95rem;">{{ $a->seat_id }}</div>
                                <div style="font-size: 0.72rem; opacity: 0.85;">{{ $label }}</div>
                            </a>
                        @endforeach
                    </div>
                </div>

                <div class="summary-details">
                    <div class="detail-row">
                        <span>Tiempo restante de bloqueo</span>
                        <strong id="countdown-timer">--:--</strong>
                    </div>
                    <div class="detail-row">
                        <span>ID de Reserva</span>
                        <strong id="reservation-id">Generando...</strong>
                    </div>
                    <div class="detail-row">
                        <span>Asiento asignado</span>
                        <strong id="seat-id">{{ $seatId }}</strong>
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
                    Tus datos personales viajan cifrados punto a punto. Los datos de tu tarjeta se envían directo a Stripe sin pasar por nuestro servidor.
                </p>

                <div class="crypto-panel" style="margin-bottom: 18px;">
                    <h3>Validador de Tarjeta Real (Luhn en Navegador + BIN Lookup)</h3>
                    <div style="display: flex; gap: 8px; margin-bottom: 10px;">
                        <input
                            type="text"
                            id="bin-input"
                            maxlength="19"
                            placeholder="Ingresa los 16 dígitos de la tarjeta"
                            style="flex: 1; background: #0f172a; border: 1px solid rgba(148,163,184,0.2); border-radius: 8px; padding: 8px 12px; color: #f8fafc;"
                        >
                        <button type="button" id="btn-check-bin" class="btn-primary" style="padding: 8px 14px; font-size: 0.85rem;">
                            Validar Tarjeta
                        </button>
                    </div>
                    <div id="bin-result" style="font-size: 0.84rem; color: #94a3b8;">
                        Nota PCI-DSS: Luhn se valida localmente; solo los primeros 6 dígitos (BIN) se consultan para identificar el banco.
                    </div>
                </div>

                <form id="metadata-form" class="form-block">
                    <div class="input-group">
                        <label for="buyer-name">Nombre completo *</label>
                        <input type="text" id="buyer-name" name="buyer_name" placeholder="Ej. André Ayala" required>
                    </div>
                    <div class="input-group">
                        <label for="buyer-phone">Número de celular *</label>
                        <input type="tel" id="buyer-phone" name="buyer_phone" placeholder="Ej. 5512345678" minlength="10" required>
                    </div>
                    <div class="input-group">
                        <label for="buyer-email">Correo electrónico para recibir tu boleto *</label>
                        <input type="email" id="buyer-email" name="buyer_email" placeholder="ejemplo@correo.com" required>
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
            seatId: @json($seatId),
            apiBase: '/api/pagos'
        };
    </script>
    <script src="{{ asset('js/checkout.js') }}"></script>
</body>
</html>
